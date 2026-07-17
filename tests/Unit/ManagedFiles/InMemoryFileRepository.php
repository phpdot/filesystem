<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Tests\Unit\ManagedFiles;

use DateTimeImmutable;
use DateTimeZone;
use PHPdot\Filesystem\Contract\FileRepositoryInterface;
use PHPdot\Filesystem\ManagedFiles\FileRecord;
use PHPdot\Filesystem\ManagedFiles\FilesFilter;

/**
 * A fast in-memory {@see FileRepositoryInterface} for facade tests.
 */
final class InMemoryFileRepository implements FileRepositoryInterface
{
    /** @var array<string,FileRecord> */
    public array $records = [];

    public function save(FileRecord $record): FileRecord
    {
        $this->records[$record->id] = $record;

        return $record;
    }

    public function find(string $id): ?FileRecord
    {
        return $this->records[$id] ?? null;
    }

    public function findByPath(string $path): ?FileRecord
    {
        foreach ($this->records as $record) {
            if ($record->path === $path) {
                return $record;
            }
        }

        return null;
    }

    public function search(FilesFilter $filter, int $limit = 20, int $offset = 0): array
    {
        $matched = array_values(array_filter($this->records, static fn(FileRecord $r): bool => $filter->matches($r)));

        usort($matched, static fn(FileRecord $a, FileRecord $b): int => $b->createdAt <=> $a->createdAt);

        return [
            'records' => array_slice($matched, $offset, $limit),
            'total' => count($matched),
        ];
    }

    public function softDelete(string $id): void
    {
        $record = $this->find($id);
        if ($record !== null && !$record->isDeleted) {
            $this->save($record->markDeleted(new DateTimeImmutable('now', new DateTimeZone('UTC'))));
        }
    }

    public function hardDelete(string $id): void
    {
        unset($this->records[$id]);
    }
}
