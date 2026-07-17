<?php

declare(strict_types=1);

/**
 * A no-op repository for apps that want the {@see Files} facade (validation,
 * server-side keys, quarantine-on-delete) but no record tracking. Bind it to
 * {@see FileRepositoryInterface} in the container to disable persistence.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\ManagedFiles;

use PHPdot\Filesystem\Contract\FileRepositoryInterface;

final class NullFileRepository implements FileRepositoryInterface
{
    public function save(FileRecord $record): FileRecord
    {
        return $record;
    }

    public function find(string $id): ?FileRecord
    {
        return null;
    }

    public function findByPath(string $path): ?FileRecord
    {
        return null;
    }

    public function search(FilesFilter $filter, int $limit = 20, int $offset = 0): array
    {
        return ['records' => [], 'total' => 0];
    }

    public function softDelete(string $id): void {}

    public function hardDelete(string $id): void {}
}
