<?php

declare(strict_types=1);

/**
 * Thrown when a directory cannot be created.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Exception;

use RuntimeException;
use Throwable;

final class UnableToCreateDirectory extends RuntimeException implements FilesystemOperationFailed
{
    public function errorCode(): string
    {
        return 'filesystem.create_directory_failed';
    }

    public function operation(): string
    {
        return 'CREATE_DIRECTORY';
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
        $message = "Unable to create directory at location: {$path}.";

        if ($reason !== '') {
            $message .= ' ' . $reason;
        }

        return new self($message, 0, $previous);
    }
}
