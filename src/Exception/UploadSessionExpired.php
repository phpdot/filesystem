<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Exception;

use RuntimeException;

final class UploadSessionExpired extends RuntimeException implements FilesystemException
{
    public function errorCode(): string
    {
        return 'filesystem.upload_session_expired';
    }

    public static function withId(string $id): self
    {
        return new self("Upload session has expired: {$id}.");
    }
}
