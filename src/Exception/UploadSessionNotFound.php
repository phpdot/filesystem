<?php

declare(strict_types=1);

/**
 * Thrown when a resumable upload session cannot be found.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Exception;

use RuntimeException;

final class UploadSessionNotFound extends RuntimeException implements FilesystemException
{
    public function errorCode(): string
    {
        return 'filesystem.upload_session_not_found';
    }

    /**
     * With id.
     *
     * @param string $id
     *
     * @return self
     */
    public static function withId(string $id): self
    {
        return new self("No upload session found with id: {$id}.");
    }
}
