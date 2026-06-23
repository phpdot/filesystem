<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\ManagedFiles;

use DateTimeImmutable;
use PHPdot\Filesystem\Visibility;

/**
 * The tracked metadata for a managed file: a database row in spirit, persisted
 * through a {@see Contract\FileRepositoryInterface}.
 *
 * It *composes* the shape of {@see \PHPdot\Filesystem\Attributes\FileAttributes}
 * rather than extending it, because it carries ingest concerns the byte layer
 * has no business knowing — original name, owner reference, tags, draft/expiry
 * and soft-delete bookkeeping. Immutable; mutations return copies.
 */
final readonly class FileRecord
{
    /**
     * @param list<string> $tags
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

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt !== null && $now >= $this->expiresAt;
    }

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
