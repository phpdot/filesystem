<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Upload;

use DateTimeImmutable;

/**
 * The persisted state of a resumable upload. Immutable; mutations return copies.
 *
 * `uploadId` is the S3 multipart UploadId (or an opaque Local handle); `parts`
 * maps ascending part numbers to the identity retained for completion (S3 ETag
 * or Local marker).
 */
final readonly class UploadSession
{
    /**
     * @param array<int,string> $parts
     */
    public function __construct(
        public string $id,
        public string $path,
        public string $uploadId,
        public ?int $totalSize,
        public int $bytesReceived,
        public array $parts,
        public int $chunkSize,
        public DateTimeImmutable $expiresAt,
    ) {}

    public function withPart(int $partNumber, string $identity): self
    {
        $parts = $this->parts;
        $parts[$partNumber] = $identity;

        return new self(
            $this->id,
            $this->path,
            $this->uploadId,
            $this->totalSize,
            $this->bytesReceived,
            $parts,
            $this->chunkSize,
            $this->expiresAt,
        );
    }

    public function withBytesReceived(int $bytesReceived): self
    {
        return new self(
            $this->id,
            $this->path,
            $this->uploadId,
            $this->totalSize,
            $bytesReceived,
            $this->parts,
            $this->chunkSize,
            $this->expiresAt,
        );
    }

    public function isComplete(): bool
    {
        return $this->totalSize !== null && $this->bytesReceived >= $this->totalSize;
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }
}
