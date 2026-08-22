<?php

declare(strict_types=1);

/**
 * The working default factory: a TCP socket, optionally upgraded to TLS, with
 * the handshake already completed.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Realtime\Transport;

use PHPdot\Container\Attribute\Binds;
use PHPdot\Container\Attribute\Singleton;
use PHPdot\Filesystem\Realtime\BinlogConfig;
use PHPdot\Filesystem\Realtime\Contract\ConnectionFactoryInterface;
use PHPdot\Filesystem\Realtime\Protocol\Connection;

#[Singleton]
#[Binds(ConnectionFactoryInterface::class)]
final readonly class SocketConnectionFactory implements ConnectionFactoryInterface
{
    /**
     * __construct.
     *
     * @param BinlogConfig $config
     */
    public function __construct(private BinlogConfig $config = new BinlogConfig()) {}

    public function connect(): Connection
    {
        $stream = SocketPacketStream::connect(
            $this->config->host,
            $this->config->port,
            $this->config->connectTimeout,
            $this->config->readTimeout,
            $this->config->tlsVerifyPeer,
        );

        $connection = new Connection($stream);

        $connection->authenticate(
            $this->config->user,
            $this->config->password,
            $this->config->database,
            $this->config->tls,
        );

        return $connection;
    }
}
