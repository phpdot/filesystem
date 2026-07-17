<?php

declare(strict_types=1);

/**
 * The persisted state of a resumable upload. Immutable; mutations return copies.
 *
 * `uploadId` is the S3 multipart UploadId (or an opaque Local handle); `parts`
 * maps ascending part numbers to the identity retained for completion (S3 ETag
 * or Local marker).
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Upload;

use DateTimeImmutable;

final readonly class UploadSession
{
    /**
     * One resumable upload session: id, target path, and received parts.
     *
     * @param array<int,string> $parts
     * @param string $id
     * @param string $path
     * @param string $uploadId
     * @param ?int $totalSize
     * @param int $bytesReceived
     * @param int $chunkSize
     * @param DateTimeImmutable $expiresAt
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

    /**
     * With part.
     *
     * @param int $partNumber
     * @param string $identity
     *
     * @return self
     */
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

    /**
     * With bytes received.
     *
     * @param int $bytesReceived
     *
     * @return self
     */
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

    /**
     * Is complete.
     *
     * @return bool
     */
    public function isComplete(): bool
    {
        return $this->totalSize !== null && $this->bytesReceived >= $this->totalSize;
    }

    /**
     * Is expired.
     *
     * @param DateTimeImmutable $now
     *
     * @return bool
     */
    public function isExpired(DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }
}
