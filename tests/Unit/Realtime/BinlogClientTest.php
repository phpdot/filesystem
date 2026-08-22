<?php

declare(strict_types=1);

namespace PHPdot\Filesystem\Tests\Unit\Realtime;

use PHPdot\Filesystem\Exception\UnsupportedBinlogFeature;
use PHPdot\Filesystem\Realtime\Binlog\ColumnType;
use PHPdot\Filesystem\Realtime\Binlog\EventType;
use PHPdot\Filesystem\Realtime\BinlogClient;
use PHPdot\Filesystem\Realtime\BinlogConfig;
use PHPdot\Filesystem\Realtime\Change\ChangeEvent;
use PHPdot\Filesystem\Realtime\Change\ChangeKind;
use PHPdot\Filesystem\Realtime\Change\Checkpoint;
use PHPdot\Filesystem\Realtime\Checkpoint\InMemoryCheckpointStore;
use PHPdot\Filesystem\Realtime\Protocol\Command;
use PHPdot\Filesystem\Tests\Unit\Realtime\BinlogEventBuilder as Build;
use PHPdot\Filesystem\Tests\Unit\RecordingDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * Drives the whole client against a scripted server: the pre-flight variable
 * checks, the dump command, decoding, checkpointing and reconnect.
 */
final class BinlogClientTest extends TestCase
{
    private const TABLE_ID = 7;

    private static function config(BinlogConfig ...$override): BinlogConfig
    {
        return $override[0] ?? new BinlogConfig(
            serverId: 4242,
            // Keep the reconnect backoff from making the suite slow.
            reconnectDelay: 0.001,
            maxReconnectDelay: 0.002,
            checkpointIntervalSeconds: 0,
        );
    }

    /**
     * The exchange every successful dump starts with.
     */
    private static function scriptPreflight(
        ScriptedPacketStream $stream,
        string $logBin = 'ON',
        string $format = 'ROW',
        string $checksum = 'NONE',
    ): ScriptedPacketStream {
        return $stream
            ->pushResultSet($logBin)   // SELECT @@GLOBAL.log_bin
            ->pushResultSet($format)   // SELECT @@GLOBAL.binlog_format
            ->pushOk()                 // SET @master_binlog_checksum
            ->pushResultSet($checksum) // SELECT @@GLOBAL.binlog_checksum
            ->pushOk()                 // SET @master_heartbeat_period
            ->pushOk();                // COM_REGISTER_SLAVE
    }

    /**
     * A one-row insert into `shop.orders`.
     */
    private static function scriptInsert(ScriptedPacketStream $stream, int $logPosition = 900): ScriptedPacketStream
    {
        return $stream
            ->pushEvent(Build::formatDescription())
            ->pushEvent(Build::rotate('binlog.000004'))
            ->pushEvent(Build::tableMap(
                self::TABLE_ID,
                'shop',
                'orders',
                [ColumnType::LONG],
                [0],
                [false],
                ['id'],
                [0],
            ))
            ->pushEvent(Build::rows(EventType::WRITE_ROWS_V2, self::TABLE_ID, 1, [true], [
                Build::rowImage([false], pack('V', 99)),
            ], $logPosition));
    }

    /**
     * Consume the stream, stopping after the given number of changes so the
     * generator's idle loop terminates.
     *
     * @return list<ChangeEvent>
     */
    private static function collect(BinlogClient $client, int $limit = 1): array
    {
        $changes = [];

        foreach ($client->changes() as $change) {
            $changes[] = $change;

            if (count($changes) >= $limit) {
                $client->stop();
            }
        }

        return $changes;
    }

    /**
     * Find the write that issued the given command byte.
     *
     * @param list<string> $writes
     */
    private static function commandPayload(array $writes, int $command): null|string
    {
        foreach ($writes as $write) {
            if ($write !== '' && ord($write[0]) === $command) {
                return $write;
            }
        }

        return null;
    }

    public function testStreamsADecodedRowChange(): void
    {
        $stream = self::scriptInsert(self::scriptPreflight(new ScriptedPacketStream()));

        $changes = self::collect(new BinlogClient(
            self::config(),
            new InMemoryCheckpointStore(),
            new ScriptedConnectionFactory($stream),
        ));

        self::assertCount(1, $changes);
        self::assertSame(ChangeKind::INSERT, $changes[0]->kind);
        self::assertSame('shop.orders', $changes[0]->qualifiedName());
        self::assertSame(['id' => 99], $changes[0]->after);
    }

    public function testPersistsTheCheckpointSoARestartResumesInPlace(): void
    {
        $store = new InMemoryCheckpointStore();
        $stream = self::scriptInsert(self::scriptPreflight(new ScriptedPacketStream()), 1500);

        self::collect(new BinlogClient(self::config(), $store, new ScriptedConnectionFactory($stream)));

        self::assertSame('binlog.000004', $store->load()->file);
        self::assertSame(1500, $store->load()->position);
    }

    public function testResumesFromAStoredFileAndPosition(): void
    {
        $stream = self::scriptInsert(self::scriptPreflight(new ScriptedPacketStream()));
        $store = new InMemoryCheckpointStore(new Checkpoint('binlog.000007', 4242));

        self::collect(new BinlogClient(self::config(), $store, new ScriptedConnectionFactory($stream)));

        $dump = self::commandPayload($stream->writes(), Command::BINLOG_DUMP);

        self::assertNotNull($dump);
        self::assertSame(4242, unpack('V', substr($dump, 1, 4))[1]);
        self::assertSame(4242, unpack('V', substr($dump, 7, 4))[1]); // server id
        self::assertSame('binlog.000007', substr($dump, 11));
    }

    public function testUsesTheGtidDumpWhenTheCheckpointCarriesAGtidSet(): void
    {
        $stream = self::scriptInsert(self::scriptPreflight(new ScriptedPacketStream()));
        $store = new InMemoryCheckpointStore(Checkpoint::fromGtidSet('3e11fa47-71ca-11e1-9e33-c80aa9429562:1-5'));

        self::collect(new BinlogClient(self::config(), $store, new ScriptedConnectionFactory($stream)));

        // GTID resume survives a failover; a file/position resume does not.
        self::assertNotNull(self::commandPayload($stream->writes(), Command::BINLOG_DUMP_GTID));
        self::assertNull(self::commandPayload($stream->writes(), Command::BINLOG_DUMP));
    }

    public function testFallsBackToFilePositionWhenNoGtidSetIsKnown(): void
    {
        $stream = self::scriptInsert(self::scriptPreflight(new ScriptedPacketStream()));

        self::collect(new BinlogClient(
            self::config(),
            new InMemoryCheckpointStore(),
            new ScriptedConnectionFactory($stream),
        ));

        self::assertNotNull(self::commandPayload($stream->writes(), Command::BINLOG_DUMP));
    }

    public function testRegistersItselfAsAReplicaWithTheConfiguredServerId(): void
    {
        $stream = self::scriptInsert(self::scriptPreflight(new ScriptedPacketStream()));

        self::collect(new BinlogClient(
            self::config(),
            new InMemoryCheckpointStore(),
            new ScriptedConnectionFactory($stream),
        ));

        $register = self::commandPayload($stream->writes(), Command::REGISTER_SLAVE);

        self::assertNotNull($register);
        self::assertSame(4242, unpack('V', substr($register, 1, 4))[1]);
    }

    public function testAsksTheServerForHeartbeatsSoASilentPeerIsDetectable(): void
    {
        $stream = self::scriptInsert(self::scriptPreflight(new ScriptedPacketStream()));

        self::collect(new BinlogClient(
            self::config(),
            new InMemoryCheckpointStore(),
            new ScriptedConnectionFactory($stream),
        ));

        $statements = implode("\n", $stream->writes());

        self::assertStringContainsString('SET @master_binlog_checksum', $statements);
        self::assertStringContainsString('SET @master_heartbeat_period', $statements);
    }

    public function testRefusesAServerLoggingStatementsRatherThanRows(): void
    {
        $stream = self::scriptPreflight(new ScriptedPacketStream(), format: 'STATEMENT');

        $client = new BinlogClient(self::config(), new InMemoryCheckpointStore(), new ScriptedConnectionFactory($stream));

        $this->expectException(UnsupportedBinlogFeature::class);
        $this->expectExceptionMessageMatches('/binlog_format/');

        self::collect($client);
    }

    public function testRefusesAServerWithBinaryLoggingDisabled(): void
    {
        $stream = self::scriptPreflight(new ScriptedPacketStream(), logBin: 'OFF');

        $client = new BinlogClient(self::config(), new InMemoryCheckpointStore(), new ScriptedConnectionFactory($stream));

        $this->expectException(UnsupportedBinlogFeature::class);
        $this->expectExceptionMessageMatches('/log_bin/');

        self::collect($client);
    }

    public function testAConfigurationFailureIsNotRetriedForever(): void
    {
        $stream = self::scriptPreflight(new ScriptedPacketStream(), format: 'MIXED');
        $factory = new ScriptedConnectionFactory($stream);

        try {
            self::collect(new BinlogClient(self::config(), new InMemoryCheckpointStore(), $factory));
        } catch (UnsupportedBinlogFeature) {
            // Expected: the same misconfiguration would fail identically on
            // every retry, so it propagates instead of spinning.
        }

        self::assertSame(1, $factory->connects());
    }

    public function testReconnectsAfterATransportFailureAndKeepsStreaming(): void
    {
        $failing = self::scriptPreflight(new ScriptedPacketStream())->pushFailure();
        $working = self::scriptInsert(self::scriptPreflight(new ScriptedPacketStream()));
        $factory = new ScriptedConnectionFactory($failing, $working);

        $changes = self::collect(new BinlogClient(self::config(), new InMemoryCheckpointStore(), $factory));

        self::assertSame(2, $factory->connects());
        self::assertCount(1, $changes);
        self::assertTrue($failing->isClosed());
    }

    public function testRecordsTheFailureBehindAReconnect(): void
    {
        $failing = self::scriptPreflight(new ScriptedPacketStream())->pushFailure();
        $working = self::scriptInsert(self::scriptPreflight(new ScriptedPacketStream()));

        $client = new BinlogClient(
            self::config(),
            new InMemoryCheckpointStore(),
            new ScriptedConnectionFactory($failing, $working),
        );

        self::collect($client);

        self::assertNotNull($client->lastFailure());
        self::assertStringContainsString('scripted failure', $client->lastFailure()->getMessage());
    }

    public function testStreamDispatchesEachChangeThroughPsr14(): void
    {
        $stream = self::scriptInsert(self::scriptPreflight(new ScriptedPacketStream()));
        $dispatcher = new RecordingDispatcher();

        $client = new BinlogClient(
            self::config(),
            new InMemoryCheckpointStore(),
            new ScriptedConnectionFactory($stream),
            $dispatcher,
        );

        $seen = [];

        $client->stream(function (ChangeEvent $change) use ($client, &$seen): void {
            $seen[] = $change;
            $client->stop();
        });

        self::assertCount(1, $seen);
        self::assertCount(1, $dispatcher->events);
        self::assertInstanceOf(ChangeEvent::class, $dispatcher->events[0]);
    }

    public function testChangeEventsSerialiseForTransport(): void
    {
        $stream = self::scriptInsert(self::scriptPreflight(new ScriptedPacketStream()));

        $changes = self::collect(new BinlogClient(
            self::config(),
            new InMemoryCheckpointStore(),
            new ScriptedConnectionFactory($stream),
        ));

        $encoded = json_encode($changes[0]->toArray());

        self::assertIsString($encoded);
        self::assertStringContainsString('"kind":"insert"', $encoded);
        self::assertStringContainsString('"table":"orders"', $encoded);
    }
}
