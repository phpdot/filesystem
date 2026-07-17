<?php

declare(strict_types=1);

/**
 * The composing root for managed files: validate → generate key → write → track.
 *
 * Deliberately thin. The core byte writer ({@see FilesystemInterface}) and the
 * resumable engine are untouched; this facade only stitches the standalone
 * validation and path-generation utilities onto a {@see FileRepositoryInterface}.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\ManagedFiles;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PHPdot\Container\Attribute\Singleton;
use PHPdot\Filesystem\Config;
use PHPdot\Filesystem\Contract\FileRepositoryInterface;
use PHPdot\Filesystem\Contract\FilesystemInterface;
use PHPdot\Filesystem\Event\FileDeleted;
use PHPdot\Filesystem\Event\FileStored;
use PHPdot\Filesystem\Exception\FileRecordNotFound;
use PHPdot\Filesystem\FilesystemConfig;
use PHPdot\Filesystem\Path\PathGenerator;
use PHPdot\Filesystem\Validation\FileSubject;
use PHPdot\Filesystem\Validation\ValidatorPipeline;
use PHPdot\Filesystem\Visibility;
use PHPdot\Filesystem\Write\WriteContents;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;

#[Singleton]
final class Files
{
    /**
     * __construct.
     *
     * @param FilesystemInterface $filesystem
     * @param FileRepositoryInterface $repository
     * @param WriteContents $writeContents
     * @param StreamFactoryInterface $streams
     * @param PathGenerator $pathGenerator
     * @param FilesystemConfig $config
     * @param ?EventDispatcherInterface $events
     */
    public function __construct(
        private readonly FilesystemInterface $filesystem,
        private readonly FileRepositoryInterface $repository,
        private readonly WriteContents $writeContents,
        private readonly StreamFactoryInterface $streams,
        private readonly PathGenerator $pathGenerator,
        private readonly FilesystemConfig $config = new FilesystemConfig(),
        private readonly ?EventDispatcherInterface $events = null,
    ) {}

    /**
     * Validate and store bytes, returning the persisted record.
     *
     * @param string|StreamInterface|UploadedFileInterface $contents
     * @param FileContext $context
     *
     * @return FileRecord
     */
    public function store(string|StreamInterface|UploadedFileInterface $contents, FileContext $context): FileRecord
    {
        return $this->ingest($contents, $context, isDraft: false);
    }

    /**
     * Store as a draft that expires after the configured TTL unless published.
     *
     * @param StreamInterface|UploadedFileInterface|string $contents
     * @param FileContext $context
     *
     * @return FileRecord
     */
    public function storeDraft(string|StreamInterface|UploadedFileInterface $contents, FileContext $context): FileRecord
    {
        return $this->ingest($contents, $context, isDraft: true);
    }

    /**
     * Promote a draft to a permanent record (clears the draft flag and expiry).
     *
     * @param string $id
     *
     * @return FileRecord
     */
    public function publish(string $id): FileRecord
    {
        return $this->repository->save($this->require($id)->withDraft(false, null));
    }

    /**
     * Soft-delete: relocate the bytes to a private quarantine key (invalidating
     * any leaked public URL), then flag the record. Move-first — if the move
     * fails the record is left intact, never orphaned as "deleted".
     *
     * @param string $id
     *
     * @return FileRecord
     */
    public function delete(string $id): FileRecord
    {
        $record = $this->require($id);
        if ($record->isDeleted) {
            return $record;
        }

        $quarantineKey = $this->config->quarantinePrefix . '/' . $this->id();

        $this->filesystem->move($record->path, $quarantineKey);
        $deleted = $this->repository->save($record->quarantined($quarantineKey, $this->now()));
        $this->filesystem->setVisibility($quarantineKey, Visibility::Private);

        $this->events?->dispatch(new FileDeleted($deleted));

        return $deleted;
    }

    /**
     * Reverse a soft-delete: move the bytes back and restore the visibility.
     *
     * @param string $id
     *
     * @return FileRecord
     */
    public function restore(string $id): FileRecord
    {
        $record = $this->require($id);
        if (!$record->isDeleted) {
            return $record;
        }

        $restored = $record->restored();

        $this->filesystem->move($record->path, $restored->path);
        $saved = $this->repository->save($restored);
        $this->filesystem->setVisibility($restored->path, $restored->visibility);

        return $saved;
    }

    /**
     * Hard-delete expired drafts and soft-deleted records past their retention.
     * Returns the number of records purged.
     *
     * @param DateTimeImmutable $now
     *
     * @return int
     */
    public function purge(DateTimeImmutable $now): int
    {
        $purged = 0;

        $drafts = $this->repository->search(new FilesFilter(isDraft: true, expiryBefore: $now), limit: PHP_INT_MAX);
        foreach ($drafts['records'] as $record) {
            $this->hardPurge($record);
            ++$purged;
        }

        $cutoff = $now->sub(new DateInterval('PT' . $this->config->softDeleteRetention . 'S'));
        $deleted = $this->repository->search(new FilesFilter(isDeleted: true), limit: PHP_INT_MAX);
        foreach ($deleted['records'] as $record) {
            if ($record->deletedAt !== null && $record->deletedAt <= $cutoff) {
                $this->hardPurge($record);
                ++$purged;
            }
        }

        return $purged;
    }

    /**
     * A visibility-aware URL for a managed file, by record id.
     *
     * @param array<string,mixed> $config
     * @param string $id
     *
     * @return string
     */
    public function url(string $id, array $config = []): string
    {
        return $this->filesystem->url($this->require($id)->path, $config);
    }

    /**
     * Repository.
     *
     * @return FileRepositoryInterface
     */
    public function repository(): FileRepositoryInterface
    {
        return $this->repository;
    }

    /**
     * The tus seam (POST): register a draft record for a resumable upload whose
     * bytes have not arrived yet. Finalized by {@see finalizeUpload} once the
     * upload completes; left to expire (and be swept by {@see purge}) if it does
     * not. Content fields are placeholders until finalization.
     *
     * @param string $path
     * @param FileContext $context
     *
     * @return FileRecord
     */
    public function registerUpload(string $path, FileContext $context): FileRecord
    {
        $record = new FileRecord(
            id: $this->id(),
            path: $path,
            originalName: $context->originalName,
            size: 0,
            mimeType: 'application/octet-stream',
            checksum: '',
            visibility: $context->visibility ?? Visibility::parse($this->config->visibility),
            createdAt: $this->now(),
            reference: $context->reference,
            referenceId: $context->referenceId,
            tags: $context->tags,
            isDraft: true,
            expiresAt: $this->now()->add(new DateInterval('PT' . $this->config->draftTtl . 'S')),
        );

        return $this->repository->save($record);
    }

    /**
     * The tus seam (completion): fill the draft's content facts from the now
     * written bytes and publish it. Returns null when no draft tracks the path.
     *
     * @param string $path
     *
     * @return ?FileRecord
     */
    public function finalizeUpload(string $path): ?FileRecord
    {
        $record = $this->repository->findByPath($path);
        if ($record === null) {
            return null;
        }

        $filled = $record
            ->withContent($this->filesystem->fileSize($path), $this->filesystem->mimeType($path), $this->filesystem->checksum($path))
            ->withDraft(false, null);

        $saved = $this->repository->save($filled);
        $this->events?->dispatch(new FileStored($saved));

        return $saved;
    }

    /**
     * Ingest.
     *
     * @param string|StreamInterface|UploadedFileInterface $contents
     * @param FileContext $context
     * @param bool $isDraft
     *
     * @return FileRecord
     */
    private function ingest(
        string|StreamInterface|UploadedFileInterface $contents,
        FileContext $context,
        bool $isDraft,
    ): FileRecord {
        $subject = FileSubject::fromContents($contents, $context->originalName, $this->writeContents, $this->streams);

        (new ValidatorPipeline(...$context->validators))->validate($subject)->throwIfInvalid();

        $visibility = $context->visibility ?? Visibility::parse($this->config->visibility);
        $pattern = $context->pathPattern ?? $this->config->defaultPathPattern;
        $key = $this->pathGenerator->generate($pattern, $subject, fn(string $candidate): bool => $this->filesystem->fileExists($candidate));

        $this->filesystem->write($key, $subject->stream(), [Config::VISIBILITY => $visibility->value]);

        $record = new FileRecord(
            id: $this->id(),
            path: $key,
            originalName: $context->originalName,
            size: $subject->size(),
            mimeType: $subject->mimeType(),
            checksum: $this->filesystem->checksum($key),
            visibility: $visibility,
            createdAt: $this->now(),
            reference: $context->reference,
            referenceId: $context->referenceId,
            tags: $context->tags,
            isDraft: $isDraft,
            expiresAt: $isDraft ? $this->now()->add(new DateInterval('PT' . $this->config->draftTtl . 'S')) : null,
        );

        $saved = $this->repository->save($record);
        $this->events?->dispatch(new FileStored($saved));

        return $saved;
    }

    /**
     * Hard purge.
     *
     * @param FileRecord $record
     *
     * @return void
     */
    private function hardPurge(FileRecord $record): void
    {
        if ($this->filesystem->fileExists($record->path)) {
            $this->filesystem->delete($record->path);
        }

        $this->repository->hardDelete($record->id);
    }

    /**
     * Require.
     *
     * @param string $id
     *
     * @return FileRecord
     */
    private function require(string $id): FileRecord
    {
        return $this->repository->find($id) ?? throw FileRecordNotFound::withId($id);
    }

    /**
     * Id.
     *
     * @return string
     */
    private function id(): string
    {
        return bin2hex(random_bytes(16));
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
