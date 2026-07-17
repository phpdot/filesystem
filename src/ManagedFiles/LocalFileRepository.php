<?php

declare(strict_types=1);

/**
 * The working default repository: one JSON sidecar per record under a directory,
 * mirroring {@see \PHPdot\Filesystem\Upload\Store\LocalSessionStore}.
 *
 * {@see search} globs and filters in PHP — fine for a default; rebind
 * {@see FileRepositoryInterface} to a real database for scale.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\ManagedFiles;

use DateTimeImmutable;
use DateTimeZone;
use PHPdot\Container\Attribute\Binds;
use PHPdot\Container\Attribute\Singleton;
use PHPdot\Filesystem\Contract\FileRepositoryInterface;
use PHPdot\Filesystem\Exception\UnableToCreateDirectory;
use PHPdot\Filesystem\FilesystemConfig;
use PHPdot\Filesystem\Visibility;

#[Singleton]
#[Binds(FileRepositoryInterface::class)]
final class LocalFileRepository implements FileRepositoryInterface
{
    private readonly string $directory;

    /**
     * __construct.
     *
     * @param FilesystemConfig $config
     */
    public function __construct(FilesystemConfig $config = new FilesystemConfig())
    {
        $this->directory = $config->fileRecordsDirectory;

        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw UnableToCreateDirectory::atLocation($this->directory);
        }
    }

    public function save(FileRecord $record): FileRecord
    {
        $data = [
            'id' => $record->id,
            'path' => $record->path,
            'originalName' => $record->originalName,
            'size' => $record->size,
            'mimeType' => $record->mimeType,
            'checksum' => $record->checksum,
            'visibility' => $record->visibility->value,
            'createdAt' => $record->createdAt->getTimestamp(),
            'reference' => $record->reference,
            'referenceId' => $record->referenceId,
            'tags' => $record->tags,
            'isDraft' => $record->isDraft,
            'expiresAt' => $record->expiresAt?->getTimestamp(),
            'isDeleted' => $record->isDeleted,
            'deletedAt' => $record->deletedAt?->getTimestamp(),
            'originalVisibility' => $record->originalVisibility?->value,
            'originalPath' => $record->originalPath,
        ];

        file_put_contents($this->file($record->id), json_encode($data, JSON_THROW_ON_ERROR));

        return $record;
    }

    public function find(string $id): ?FileRecord
    {
        return $this->read($this->file($id));
    }

    public function findByPath(string $path): ?FileRecord
    {
        foreach ($this->all() as $record) {
            if ($record->path === $path) {
                return $record;
            }
        }

        return null;
    }

    public function search(FilesFilter $filter, int $limit = 20, int $offset = 0): array
    {
        $matched = [];
        foreach ($this->all() as $record) {
            if ($filter->matches($record)) {
                $matched[] = $record;
            }
        }

        usort($matched, static fn(FileRecord $a, FileRecord $b): int => $b->createdAt <=> $a->createdAt);

        return [
            'records' => array_slice($matched, $offset, $limit),
            'total' => count($matched),
        ];
    }

    public function softDelete(string $id): void
    {
        $record = $this->find($id);
        if ($record === null || $record->isDeleted) {
            return;
        }

        $this->save($record->markDeleted($this->now()));
    }

    public function hardDelete(string $id): void
    {
        @unlink($this->file($id));
    }

    /**
     * Return every managed file record.
     *
     * @return iterable<FileRecord>
     */
    private function all(): iterable
    {
        $files = glob($this->directory . '/*.json');
        if ($files === false) {
            return;
        }

        foreach ($files as $file) {
            $record = $this->read($file);
            if ($record !== null) {
                yield $record;
            }
        }
    }

    /**
     * Read.
     *
     * @param string $file
     *
     * @return ?FileRecord
     */
    private function read(string $file): ?FileRecord
    {
        if (!is_file($file)) {
            return null;
        }

        $json = @file_get_contents($file);
        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);

        return is_array($data) ? $this->hydrate($data) : null;
    }

    /**
     * Hydrate a managed file record from a raw database row.
     *
     * @param array<array-key,mixed> $data
     *
     * @return FileRecord
     */
    private function hydrate(array $data): FileRecord
    {
        return new FileRecord(
            id: $this->string($data, 'id'),
            path: $this->string($data, 'path'),
            originalName: $this->string($data, 'originalName'),
            size: $this->int($data, 'size'),
            mimeType: $this->string($data, 'mimeType'),
            checksum: $this->string($data, 'checksum'),
            visibility: Visibility::parse($this->stringOr($data, 'visibility', 'private')),
            createdAt: $this->timestamp($data, 'createdAt') ?? $this->now(),
            reference: $this->nullableString($data, 'reference'),
            referenceId: $this->nullableString($data, 'referenceId'),
            tags: $this->tags($data),
            isDraft: $this->bool($data, 'isDraft'),
            expiresAt: $this->timestamp($data, 'expiresAt'),
            isDeleted: $this->bool($data, 'isDeleted'),
            deletedAt: $this->timestamp($data, 'deletedAt'),
            originalVisibility: ($v = $this->nullableString($data, 'originalVisibility')) === null
                ? null
                : Visibility::parse($v),
            originalPath: $this->nullableString($data, 'originalPath'),
        );
    }

    /**
     * Decode a row's tags column into a list of strings.
     *
     * @param array<array-key,mixed> $data
     *
     * @return list<string>
     */
    private function tags(array $data): array
    {
        $tags = [];
        $raw = $data['tags'] ?? null;
        if (is_array($raw)) {
            foreach ($raw as $tag) {
                if (is_string($tag)) {
                    $tags[] = $tag;
                }
            }
        }

        return $tags;
    }

    /**
     * Coerce a raw row value to a string.
     *
     * @param array<array-key,mixed> $data
     * @param string $key
     *
     * @return string
     */
    private function string(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * Coerce a raw row value to a string, or a default.
     *
     * @param array<array-key,mixed> $data
     * @param string $default
     * @param string $key
     *
     * @return string
     */
    private function stringOr(array $data, string $key, string $default): string
    {
        $value = $data[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    /**
     * Coerce a raw row value to a string, or null.
     *
     * @param array<array-key,mixed> $data
     * @param string $key
     *
     * @return ?string
     */
    private function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * Coerce a raw row value to an int.
     *
     * @param array<array-key,mixed> $data
     * @param string $key
     *
     * @return int
     */
    private function int(array $data, string $key): int
    {
        $value = $data[$key] ?? 0;

        return is_int($value) ? $value : 0;
    }

    /**
     * Coerce a raw row value to a bool.
     *
     * @param array<array-key,mixed> $data
     * @param string $key
     *
     * @return bool
     */
    private function bool(array $data, string $key): bool
    {
        return ($data[$key] ?? false) === true;
    }

    /**
     * Coerce a raw row value to a Unix timestamp.
     *
     * @param array<array-key,mixed> $data
     * @param string $key
     *
     * @return ?DateTimeImmutable
     */
    private function timestamp(array $data, string $key): ?DateTimeImmutable
    {
        $value = $data[$key] ?? null;
        if (!is_int($value)) {
            return null;
        }

        return (new DateTimeImmutable('@' . $value))->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * File.
     *
     * @param string $id
     *
     * @return string
     */
    private function file(string $id): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $id) ?? '';

        return $this->directory . '/' . $safe . '.json';
    }

    /**
     * Now.
     *
     * @return DateTimeImmutable
     */
    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
