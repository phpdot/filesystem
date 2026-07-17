<?php

declare(strict_types=1);

/**
 * Thrown when the contents of a directory cannot be listed.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Exception;

use RuntimeException;
use Throwable;

final class UnableToListContents extends RuntimeException implements FilesystemOperationFailed
{
    public function errorCode(): string
    {
        return 'filesystem.list_contents_failed';
    }

    public function operation(): string
    {
        return 'LIST_CONTENTS';
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
        $message = "Unable to list contents at location: {$path}.";

        if ($reason !== '') {
            $message .= ' ' . $reason;
        }

        return new self($message, 0, $previous);
    }
}
