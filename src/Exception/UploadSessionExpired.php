<?php

declare(strict_types=1);

/**
 * Thrown when a resumable upload session has expired.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Exception;

use RuntimeException;

final class UploadSessionExpired extends RuntimeException implements FilesystemException
{
    public function errorCode(): string
    {
        return 'filesystem.upload_session_expired';
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
        return new self("Upload session has expired: {$id}.");
    }
}
