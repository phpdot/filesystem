<?php

declare(strict_types=1);

/**
 * Persistence for {@see FileRecord}s, modeled on {@see SessionStoreInterface}.
 *
 * DTO-based by design — it fixes the legacy array/return-code contract: methods
 * take and return typed records and throw on error. Rebind this in the container
 * (MySQL, Mongo, Eloquent…) for production scale; the shipped default is
 * {@see \PHPdot\Filesystem\ManagedFiles\LocalFileRepository}.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Contract;

use PHPdot\Filesystem\ManagedFiles\FileRecord;
use PHPdot\Filesystem\ManagedFiles\FilesFilter;

interface FileRepositoryInterface
{
    /**
     * Save.
     *
     * @param FileRecord $record
     *
     * @return FileRecord
     */
    public function save(FileRecord $record): FileRecord;

    /**
     * Find.
     *
     * @param string $id
     *
     * @return ?FileRecord
     */
    public function find(string $id): ?FileRecord;

    /**
     * Find by path.
     *
     * @param string $path
     *
     * @return ?FileRecord
     */
    public function findByPath(string $path): ?FileRecord;

    /**
     * Search managed file records by the given criteria.
     *
     * @param FilesFilter $filter
     * @param int $limit
     * @param int $offset
     *
     * @return array{records: list<FileRecord>, total: int}
     */
    public function search(FilesFilter $filter, int $limit = 20, int $offset = 0): array;

    /**
     * Soft delete.
     *
     * @param string $id
     *
     * @return void
     */
    public function softDelete(string $id): void;

    /**
     * Hard delete.
     *
     * @param string $id
     *
     * @return void
     */
    public function hardDelete(string $id): void;
}
