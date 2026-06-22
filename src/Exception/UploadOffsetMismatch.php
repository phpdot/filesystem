<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Exception;

use RuntimeException;

/**
 * A resumable chunk was offered at the wrong offset (tus requires each PATCH to
 * continue from the session's current offset).
 */
final class UploadOffsetMismatch extends RuntimeException implements FilesystemException
{
    private int $expectedOffset = 0;

    public function errorCode(): string
    {
        return 'filesystem.upload_offset_mismatch';
    }

    public function expectedOffset(): int
    {
        return $this->expectedOffset;
    }

    public static function expected(int $expected, int $given): self
    {
        $exception = new self("Upload offset mismatch: expected {$expected}, got {$given}.");
        $exception->expectedOffset = $expected;

        return $exception;
    }
}
