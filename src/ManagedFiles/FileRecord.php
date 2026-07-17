<?php

declare(strict_types=1);

/**
 * The tracked metadata for a managed file: a database row in spirit, persisted
 * through a {@see Contract\FileRepositoryInterface}.
 *
 * It *composes* the shape of {@see \PHPdot\Filesystem\Attributes\FileAttributes}
 * rather than extending it, because it carries ingest concerns the byte layer
 * has no business knowing — original name, owner reference, tags, draft/expiry
 * and soft-delete bookkeeping. Immutable; mutations return copies.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\ManagedFiles;

use DateTimeImmutable;
use PHPdot\Filesystem\Visibility;

final readonly class FileRecord
{
    /**
     * One managed-file record: identity, path, size, and metadata.
     *
     * @param list<string> $tags
     * @param string $id
     * @param string $path
     * @param string $originalName
     * @param int $size
     * @param string $mimeType
     * @param string $checksum
     * @param Visibility $visibility
     * @param DateTimeImmutable $createdAt
     * @param ?string $reference
     * @param ?string $referenceId
     * @param bool $isDraft
     * @param ?DateTimeImmutable $expiresAt
     * @param bool $isDeleted
     * @param ?DateTimeImmutable $deletedAt
     * @param ?Visibility $originalVisibility
     * @param ?string $originalPath
     */
    public function __construct(
        public string $id,
        public string $path,
        public string $originalName,
        public int $size,
        public string $mimeType,
        public string $checksum,
        public Visibility $visibility,
        public DateTimeImmutable $createdAt,
        public ?string $reference = null,
        public ?string $referenceId = null,
        public array $tags = [],
        public bool $isDraft = false,
        public ?DateTimeImmutable $expiresAt = null,
        public bool $isDeleted = false,
        public ?DateTimeImmutable $deletedAt = null,
        public ?Visibility $originalVisibility = null,
        public ?string $originalPath = null,
    ) {}

    /**
     * Is expired.
     *
     * @param DateTimeImmutable $now
     *
     * @return bool
     */
    public function isExpired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt !== null && $now >= $this->expiresAt;
    }

    /**
     * With draft.
     *
     * @param bool $isDraft
     * @param ?DateTimeImmutable $expiresAt
     *
     * @return self
     */
    public function withDraft(bool $isDraft, ?DateTimeImmutable $expiresAt): self
    {
        return new self(
            $this->id,
            $this->path,
            $this->originalName,
            $this->size,
            $this->mimeType,
            $this->checksum,
            $this->visibility,
            $this->createdAt,
            $this->reference,
            $this->referenceId,
            $this->tags,
            isDraft: $isDraft,
            expiresAt: $expiresAt,
            isDeleted: $this->isDeleted,
            deletedAt: $this->deletedAt,
            originalVisibility: $this->originalVisibility,
            originalPath: $this->originalPath,
        );
    }

    /**
     * With content.
     *
     * @param int $size
     * @param string $mimeType
     * @param string $checksum
     *
     * @return self
     */
    public function withContent(int $size, string $mimeType, string $checksum): self
    {
        return new self(
            $this->id,
            $this->path,
            $this->originalName,
            $size,
            $mimeType,
            $checksum,
            $this->visibility,
            $this->createdAt,
            $this->reference,
            $this->referenceId,
            $this->tags,
            isDraft: $this->isDraft,
            expiresAt: $this->expiresAt,
            isDeleted: $this->isDeleted,
            deletedAt: $this->deletedAt,
            originalVisibility: $this->originalVisibility,
            originalPath: $this->originalPath,
        );
    }

    /**
     * Flag the record deleted without relocating bytes — the repository-level
     * primitive behind {@see Contract\FileRepositoryInterface::softDelete}.
     *
     * @param DateTimeImmutable $deletedAt
     *
     * @return FileRecord
     */
    public function markDeleted(DateTimeImmutable $deletedAt): self
    {
        return new self(
            $this->id,
            $this->path,
            $this->originalName,
            $this->size,
            $this->mimeType,
            $this->checksum,
            $this->visibility,
            $this->createdAt,
            $this->reference,
            $this->referenceId,
            $this->tags,
            isDraft: $this->isDraft,
            expiresAt: $this->expiresAt,
            isDeleted: true,
            deletedAt: $deletedAt,
            originalVisibility: $this->originalVisibility,
            originalPath: $this->originalPath,
        );
    }

    /**
     * Record a soft-delete that has relocated the bytes to a private quarantine
     * key: remembers the original path and visibility so {@see restored} can
     * reverse it.
     *
     * @param string $quarantinePath
     * @param DateTimeImmutable $deletedAt
     *
     * @return FileRecord
     */
    public function quarantined(string $quarantinePath, DateTimeImmutable $deletedAt): self
    {
        return new self(
            $this->id,
            $quarantinePath,
            $this->originalName,
            $this->size,
            $this->mimeType,
            $this->checksum,
            Visibility::Private,
            $this->createdAt,
            $this->reference,
            $this->referenceId,
            $this->tags,
            isDraft: $this->isDraft,
            expiresAt: $this->expiresAt,
            isDeleted: true,
            deletedAt: $deletedAt,
            originalVisibility: $this->visibility,
            originalPath: $this->path,
        );
    }

    /**
     * Reverse a {@see quarantined} record back to its original path and visibility.
     *
     * @return FileRecord
     */
    public function restored(): self
    {
        return new self(
            $this->id,
            $this->originalPath ?? $this->path,
            $this->originalName,
            $this->size,
            $this->mimeType,
            $this->checksum,
            $this->originalVisibility ?? $this->visibility,
            $this->createdAt,
            $this->reference,
            $this->referenceId,
            $this->tags,
            isDraft: $this->isDraft,
            expiresAt: $this->expiresAt,
            isDeleted: false,
            deletedAt: null,
            originalVisibility: null,
            originalPath: null,
        );
    }
}
