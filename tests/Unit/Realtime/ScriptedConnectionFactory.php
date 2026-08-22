<?php

declare(strict_types=1);

namespace PHPdot\Filesystem\Tests\Unit\Realtime;

use PHPdot\Filesystem\Exception\ReplicationConnectionFailed;
use PHPdot\Filesystem\Realtime\Contract\ConnectionFactoryInterface;
use PHPdot\Filesystem\Realtime\Protocol\Connection;

/**
 * Hands out scripted connections in order, so the client's reconnect loop can be
 * driven deterministically.
 */
final class ScriptedConnectionFactory implements ConnectionFactoryInterface
{
    /**
     * @var list<ScriptedPacketStream>
     */
    private array $streams;

    private int $connects = 0;

    public function __construct(ScriptedPacketStream ...$streams)
    {
        $this->streams = array_values($streams);
    }

    /**
     * How many times the client asked for a connection.
     */
    public function connects(): int
    {
        return $this->connects;
    }

    public function connect(): Connection
    {
        $stream = array_shift($this->streams);

        if ($stream === null) {
            throw ReplicationConnectionFailed::lost('the script ran out of connections');
        }

        ++$this->connects;

        return new Connection($stream);
    }
}
