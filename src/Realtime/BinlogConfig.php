<?php

declare(strict_types=1);

/**
 * Configuration for the MySQL replication stream, hydrated by phpdot/config
 * from a generated `config/realtime.php`. Flat scalars only, matching
 * {@see \PHPdot\Filesystem\FilesystemConfig}.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Realtime;

use PHPdot\Container\Attribute\Config as ConfigSection;
use PHPdot\Container\Attribute\Singleton;

#[Singleton]
#[ConfigSection('realtime')]
final readonly class BinlogConfig
{
    /**
     * __construct.
     *
     * @param string $host
     * @param int $port
     * @param string $user
     * @param string $password
     * @param string $database
     * @param int $serverId
     * @param bool $tls
     * @param bool $tlsVerifyPeer
     * @param bool $useGtid
     * @param string $tables
     * @param int $heartbeatSeconds
     * @param float $connectTimeout
     * @param float $readTimeout
     * @param string $checkpointFile
     * @param int $checkpointIntervalSeconds
     * @param float $reconnectDelay
     * @param float $maxReconnectDelay
     */
    public function __construct(
        public string $host = '127.0.0.1',
        public int $port = 3306,
        public string $user = '',
        public string $password = '',
        public string $database = '',
        // Must be unique across the replication topology: a duplicate id makes
        // the server disconnect whichever replica registered first.
        public int $serverId = 1001,
        public bool $tls = false,
        public bool $tlsVerifyPeer = true,
        public bool $useGtid = true,
        // Comma-separated `db.table` or `db.*`; empty subscribes to everything.
        public string $tables = '',
        public int $heartbeatSeconds = 30,
        public float $connectTimeout = 10.0,
        // Longer than the heartbeat, so a quiet server is never mistaken for a
        // dead one.
        public float $readTimeout = 90.0,
        public string $checkpointFile = 'storage/.replication/checkpoint.json',
        public int $checkpointIntervalSeconds = 5,
        public float $reconnectDelay = 1.0,
        public float $maxReconnectDelay = 60.0,
    ) {}

    /**
     * The table filter as a list.
     *
     * @return list<string>
     */
    public function tableList(): array
    {
        $tables = array_map(trim(...), explode(',', $this->tables));

        return array_values(array_filter($tables, static fn(string $table): bool => $table !== ''));
    }
}
