<?php

declare(strict_types=1);

/**
 * A query against the file repository. Every field is optional; a null field is
 * not constrained. {@see matches} lets in-memory stores (e.g.
 * {@see LocalFileRepository}) apply the filter without duplicating the logic.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\ManagedFiles;

use DateTimeImmutable;

final readonly class FilesFilter
{
    /**
     * __construct.
     *
     * @param ?string $reference
     * @param ?string $referenceId
     * @param ?bool $isDraft
     * @param ?bool $isDeleted
     * @param ?DateTimeImmutable $expiryBefore
     * @param ?string $mimeType
     * @param ?string $tag
     */
    public function __construct(
        public ?string $reference = null,
        public ?string $referenceId = null,
        public ?bool $isDraft = null,
        public ?bool $isDeleted = null,
        public ?DateTimeImmutable $expiryBefore = null,
        public ?string $mimeType = null,
        public ?string $tag = null,
    ) {}

    /**
     * Matches.
     *
     * @param FileRecord $record
     *
     * @return bool
     */
    public function matches(FileRecord $record): bool
    {
        if ($this->reference !== null && $record->reference !== $this->reference) {
            return false;
        }

        if ($this->referenceId !== null && $record->referenceId !== $this->referenceId) {
            return false;
        }

        if ($this->isDraft !== null && $record->isDraft !== $this->isDraft) {
            return false;
        }

        if ($this->isDeleted !== null && $record->isDeleted !== $this->isDeleted) {
            return false;
        }

        if ($this->mimeType !== null && $record->mimeType !== $this->mimeType) {
            return false;
        }

        if ($this->tag !== null && !in_array($this->tag, $record->tags, true)) {
            return false;
        }

        if ($this->expiryBefore !== null && ($record->expiresAt === null || $record->expiresAt >= $this->expiryBefore)) {
            return false;
        }

        return true;
    }
}
