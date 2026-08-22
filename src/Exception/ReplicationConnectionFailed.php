<?php

declare(strict_types=1);

/**
 * The replication socket could not be opened, or dropped mid-stream.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Exception;

use RuntimeException;
use Throwable;

final class ReplicationConnectionFailed extends RuntimeException implements FilesystemException
{
    public function errorCode(): string
    {
        return 'filesystem.replication_connection_failed';
    }

    /**
     * Connect.
     *
     * @param string $host
     * @param int $port
     * @param string $reason
     * @param ?Throwable $previous
     *
     * @return self
     */
    public static function connect(string $host, int $port, string $reason, null|Throwable $previous = null): self
    {
        $detail = $reason !== '' ? $reason : 'no detail provided';

        return new self("Unable to connect to MySQL at {$host}:{$port}: {$detail}.", 0, $previous);
    }

    /**
     * The peer closed the connection, or a read/write returned short.
     *
     * @param string $reason
     *
     * @return self
     */
    public static function lost(string $reason): self
    {
        return new self("MySQL replication connection lost: {$reason}.");
    }

    /**
     * The server answered a command with an ERR packet.
     *
     * @param int $code
     * @param string $message
     *
     * @return self
     */
    public static function serverError(int $code, string $message): self
    {
        $detail = $message !== '' ? $message : 'no detail provided';

        return new self("MySQL returned error {$code}: {$detail}.");
    }
}
