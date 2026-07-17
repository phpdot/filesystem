<?php

declare(strict_types=1);

/**
 * Thrown when a managed-file operation references a record id that the bound
 * {@see \PHPdot\Filesystem\Contract\FileRepositoryInterface} does not know.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Exception;

use RuntimeException;

final class FileRecordNotFound extends RuntimeException implements FilesystemException
{
    public function errorCode(): string
    {
        return 'filesystem.file_record_not_found';
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
        return new self("No managed file record found for id: {$id}.");
    }
}
