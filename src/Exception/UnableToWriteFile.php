<?php

declare(strict_types=1);

/**
 * Thrown when a file cannot be written.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Exception;

use RuntimeException;
use Throwable;

final class UnableToWriteFile extends RuntimeException implements FilesystemOperationFailed
{
    public function errorCode(): string
    {
        return 'filesystem.write_failed';
    }

    public function operation(): string
    {
        return 'WRITE';
    }

    /**
     * At location.
     *
     * @param string $path
     * @param string $reason
     * @param ?Throwable $previous
     *
     * @return self
     */
    public static function atLocation(string $path, string $reason = '', ?Throwable $previous = null): self
    {
        $message = "Unable to write file at location: {$path}.";

        if ($reason !== '') {
            $message .= ' ' . $reason;
        }

        return new self($message, 0, $previous);
    }
}
