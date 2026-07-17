<?php

declare(strict_types=1);

/**
 * Thrown when a path visibility cannot be set.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Exception;

use RuntimeException;
use Throwable;

final class UnableToSetVisibility extends RuntimeException implements FilesystemOperationFailed
{
    public function errorCode(): string
    {
        return 'filesystem.set_visibility_failed';
    }

    public function operation(): string
    {
        return 'SET_VISIBILITY';
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
        $message = "Unable to set visibility for file at location: {$path}.";

        if ($reason !== '') {
            $message .= ' ' . $reason;
        }

        return new self($message, 0, $previous);
    }
}
