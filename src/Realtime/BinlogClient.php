<?php

declare(strict_types=1);

/**
 * The realtime entry point: streams committed row changes out of MySQL.
 *
 * MySQL has no PostgreSQL-style WAL to subscribe to, but it does have the
 * binary log, and it will push it to anything that registers as a replica. This
 * client does exactly that — handshake, COM_BINLOG_DUMP, then decode — so
 * changes arrive as they commit instead of being polled for. Because it reads
 * the same log replication reads, it also sees writes made by other
 * applications, migrations and hand-run SQL, which an application-level event
 * never will.
 *
 * ```php
 * $client = new BinlogClient(new BinlogConfig(user: 'repl', password: '…', tables: 'shop.orders'));
 *
 * foreach ($client->changes() as $change) {
 *     echo $change->kind->value, ' ', $change->qualifiedName(), PHP_EOL;
 * }
 * ```
 *
 * Delivery is at-least-once: the checkpoint advances only after a change has
 * been handed to the consumer, so a crash mid-handling replays that change
 * rather than dropping it. Consumers must be idempotent.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Realtime;

use Closure;
use Generator;
use PHPdot\Container\Attribute\Singleton;
use PHPdot\Filesystem\Exception\ReplicationConnectionFailed;
use PHPdot\Filesystem\Exception\UnsupportedBinlogFeature;
use PHPdot\Filesystem\Realtime\Binlog\EventReader;
use PHPdot\Filesystem\Realtime\Change\ChangeEvent;
use PHPdot\Filesystem\Realtime\Change\Checkpoint;
use PHPdot\Filesystem\Realtime\Checkpoint\LocalCheckpointStore;
use PHPdot\Filesystem\Realtime\Contract\CheckpointStoreInterface;
use PHPdot\Filesystem\Realtime\Contract\ConnectionFactoryInterface;
use PHPdot\Filesystem\Realtime\Protocol\Connection;
use PHPdot\Filesystem\Realtime\Transport\SocketConnectionFactory;
use Psr\EventDispatcher\EventDispatcherInterface;

#[Singleton]
final class BinlogClient
{
    private bool $stopped = false;

    private Checkpoint $checkpoint;

    private int $lastPersistedAt = 0;

    private null|ReplicationConnectionFailed $lastFailure = null;

    /**
     * __construct.
     *
     * @param BinlogConfig $config
     * @param ?CheckpointStoreInterface $checkpoints
     * @param ?ConnectionFactoryInterface $connections
     * @param ?EventDispatcherInterface $events
     */
    public function __construct(
        private readonly BinlogConfig $config = new BinlogConfig(),
        private readonly null|CheckpointStoreInterface $checkpoints = null,
        private readonly null|ConnectionFactoryInterface $connections = null,
        private readonly null|EventDispatcherInterface $events = null,
    ) {
        $this->checkpoint = new Checkpoint();
    }

    /**
     * Stream row changes until {@see stop} is called or the process ends.
     *
     * The checkpoint advances as the consumer resumes the generator, so a
     * `break` out of the loop leaves the stream positioned at the last change
     * actually handled.
     *
     * @return Generator<int,ChangeEvent>
     */
    public function changes(): Generator
    {
        $store = $this->checkpoints ?? new LocalCheckpointStore($this->config);
        $factory = $this->connections ?? new SocketConnectionFactory($this->config);

        $this->checkpoint = $store->load();
        $this->stopped = false;
        $delay = $this->config->reconnectDelay;

        while (!$this->stopRequested()) {
            $connection = null;

            try {
                $connection = $factory->connect();
                $reader = $this->startDump($connection);
                $delay = $this->config->reconnectDelay;

                while (!$this->stopRequested()) {
                    $event = $connection->readEvent();

                    if ($event === null) {
                        // An idle read: the binlog is quiet, or a heartbeat
                        // arrived. Flush the checkpoint and keep waiting.
                        $this->persist($store, false);

                        continue;
                    }

                    foreach ($reader->consume($event) as $change) {
                        yield $change;
                    }

                    $this->checkpoint = $reader->checkpoint();
                    $this->persist($store, false);
                }
            } catch (ReplicationConnectionFailed $failure) {
                // Only transport failures are retried. A rejected credential or
                // an unsupported server setting will fail identically forever,
                // so those propagate instead of spinning.
                $this->lastFailure = $failure;
                $connection?->close();
                $connection = null;

                if ($this->stopRequested()) {
                    break;
                }

                $this->pause($delay);
                $delay = min($delay * 2, $this->config->maxReconnectDelay);
            } finally {
                $connection?->close();
            }
        }

        $this->persist($store, true);
    }

    /**
     * Stream changes, dispatching each through PSR-14 and an optional callback.
     *
     * @param ?Closure $onChange
     *
     * @return void
     */
    public function stream(null|Closure $onChange = null): void
    {
        foreach ($this->changes() as $change) {
            $this->events?->dispatch($change);
            $onChange?->__invoke($change);
        }
    }

    /**
     * Whether a stop has been requested.
     *
     * Read through a method rather than the property directly: the consumer
     * calls {@see stop} while the generator is suspended at a `yield`, so the
     * flag changes at a point no flow analysis of this method can see.
     *
     * @return bool
     */
    private function stopRequested(): bool
    {
        return $this->stopped;
    }

    /**
     * Ask the stream to finish.
     *
     * Takes effect on the next event or idle read, so a quiet stream can take
     * up to `readTimeout` seconds to notice.
     *
     * @return void
     */
    public function stop(): void
    {
        $this->stopped = true;
    }

    /**
     * Where the stream has read to.
     *
     * @return Checkpoint
     */
    public function checkpoint(): Checkpoint
    {
        return $this->checkpoint;
    }

    /**
     * The transport failure behind the most recent reconnect, if any.
     *
     * @return ?ReplicationConnectionFailed
     */
    public function lastFailure(): null|ReplicationConnectionFailed
    {
        return $this->lastFailure;
    }

    /**
     * Verify the server is configured for row-level replication, tell it this
     * connection is a replica, and start the dump.
     *
     * @param Connection $connection
     *
     * @return EventReader
     */
    private function startDump(Connection $connection): EventReader
    {
        $this->requireSetting($connection, 'log_bin', ['ON', '1'], 'ON');
        $this->requireSetting($connection, 'binlog_format', ['ROW'], 'ROW');

        // Announcing checksum support keeps the server from rewriting every
        // event to strip the CRC; the same value tells the decoder how many
        // trailing bytes to ignore.
        $connection->execute('SET @master_binlog_checksum = @@global.binlog_checksum');
        $checksum = strtoupper($connection->serverVariable('binlog_checksum') ?? 'NONE');

        if ($checksum !== 'NONE' && $checksum !== 'CRC32') {
            throw UnsupportedBinlogFeature::serverSetting('binlog_checksum', $checksum, 'NONE or CRC32');
        }

        // Heartbeats turn a silent connection into a positive liveness signal,
        // so a dead peer is detected by the read timeout rather than by a write
        // failing minutes later. The period is expressed in nanoseconds.
        $period = max(1, $this->config->heartbeatSeconds) * 1_000_000_000;
        $connection->execute("SET @master_heartbeat_period = {$period}");

        $connection->registerReplica($this->config->serverId);

        $useGtid = $this->config->useGtid && $this->checkpoint->hasGtidSet();
        $connection->dumpBinlog($this->checkpoint, $this->config->serverId, $useGtid);

        $reader = new EventReader($this->checkpoint, $this->config->tableList());
        $reader->useChecksum($checksum === 'CRC32');

        return $reader;
    }

    /**
     * Require setting.
     *
     * @param Connection $connection
     * @param string $name
     * @param list<string> $accepted
     * @param string $required
     *
     * @return void
     */
    private function requireSetting(Connection $connection, string $name, array $accepted, string $required): void
    {
        $actual = strtoupper($connection->serverVariable($name) ?? '');

        if (!in_array($actual, $accepted, true)) {
            throw UnsupportedBinlogFeature::serverSetting($name, $actual === '' ? 'unset' : $actual, $required);
        }
    }

    /**
     * Write the checkpoint, throttled so a busy stream does not turn every row
     * into a disk write. A forced write is used when the stream ends.
     *
     * @param CheckpointStoreInterface $store
     * @param bool $force
     *
     * @return void
     */
    private function persist(CheckpointStoreInterface $store, bool $force): void
    {
        $now = time();

        if (!$force && $now - $this->lastPersistedAt < $this->config->checkpointIntervalSeconds) {
            return;
        }

        $this->lastPersistedAt = $now;
        $store->save($this->checkpoint);
    }

    /**
     * Wait between reconnect attempts. Uses `usleep`, which Swoole's runtime
     * hooks into a coroutine yield.
     *
     * @param float $seconds
     *
     * @return void
     */
    private function pause(float $seconds): void
    {
        usleep((int) ($seconds * 1_000_000));
    }
}
