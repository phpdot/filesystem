<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Exception;

use RuntimeException;

final class UploadSessionNotFound extends RuntimeException implements FilesystemException
{
    public function errorCode(): string
    {
        return 'filesystem.upload_session_not_found';
    }

    public static function withId(string $id): self
    {
        return new self("No upload session found with id: {$id}.");
    }
}
