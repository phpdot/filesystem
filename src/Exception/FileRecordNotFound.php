<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Exception;

use RuntimeException;

/**
 * Thrown when a managed-file operation references a record id that the bound
 * {@see \PHPdot\Filesystem\Contract\FileRepositoryInterface} does not know.
 */
final class FileRecordNotFound extends RuntimeException implements FilesystemException
{
    public function errorCode(): string
    {
        return 'filesystem.file_record_not_found';
    }

    public static function withId(string $id): self
    {
        return new self("No managed file record found for id: {$id}.");
    }
}
