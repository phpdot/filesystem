<?php

declare(strict_types=1);

/**
 * Thrown when a file copy operation fails.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Exception;

use RuntimeException;
use Throwable;

final class UnableToCopyFile extends RuntimeException implements FilesystemOperationFailed
{
    public function errorCode(): string
    {
        return 'filesystem.copy_failed';
    }

    public function operation(): string
    {
        return 'COPY';
    }

    /**
     * From to.
     *
     * @param string $source
     * @param string $destination
     * @param string $reason
     * @param ?Throwable $previous
     *
     * @return self
     */
    public static function fromTo(string $source, string $destination, string $reason = '', ?Throwable $previous = null): self
    {
        $message = "Unable to copy file from {$source} to {$destination}.";

        if ($reason !== '') {
            $message .= ' ' . $reason;
        }

        return new self($message, 0, $previous);
    }
}
