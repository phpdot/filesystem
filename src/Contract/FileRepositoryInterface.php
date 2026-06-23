<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Contract;

use PHPdot\Filesystem\ManagedFiles\FileRecord;
use PHPdot\Filesystem\ManagedFiles\FilesFilter;

/**
 * Persistence for {@see FileRecord}s, modeled on {@see SessionStoreInterface}.
 *
 * DTO-based by design — it fixes the legacy array/return-code contract: methods
 * take and return typed records and throw on error. Rebind this in the container
 * (MySQL, Mongo, Eloquent…) for production scale; the shipped default is
 * {@see \PHPdot\Filesystem\ManagedFiles\LocalFileRepository}.
 */
interface FileRepositoryInterface
{
    public function save(FileRecord $record): FileRecord;

    public function find(string $id): ?FileRecord;

    public function findByPath(string $path): ?FileRecord;

    /**
     * @return array{records: list<FileRecord>, total: int}
     */
    public function search(FilesFilter $filter, int $limit = 20, int $offset = 0): array;

    public function softDelete(string $id): void;

    public function hardDelete(string $id): void;
}
