<?php

declare(strict_types=1);

/**
 * Storage attributes for a file entry: path, size, visibility, mime type, and last-modified time.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Attributes;

use PHPdot\Filesystem\Contract\StorageAttributes;

final readonly class FileAttributes implements StorageAttributes
{
    /**
     * File storage attributes: path, size, visibility, mime type, and last-modified time.
     *
     * @param array<string,mixed> $extraMetadata
     * @param string $path
     * @param ?int $fileSize
     * @param ?string $visibility
     * @param ?int $lastModified
     * @param ?string $mimeType
     */
    public function __construct(
        private string $path,
        private ?int $fileSize = null,
        private ?string $visibility = null,
        private ?int $lastModified = null,
        private ?string $mimeType = null,
        private array $extraMetadata = [],
    ) {}

    public function path(): string
    {
        return $this->path;
    }

    public function isFile(): bool
    {
        return true;
    }

    public function isDir(): bool
    {
        return false;
    }

    /**
     * File size.
     *
     * @return ?int
     */
    public function fileSize(): ?int
    {
        return $this->fileSize;
    }

    public function visibility(): ?string
    {
        return $this->visibility;
    }

    public function lastModified(): ?int
    {
        return $this->lastModified;
    }

    /**
     * Mime type.
     *
     * @return ?string
     */
    public function mimeType(): ?string
    {
        return $this->mimeType;
    }

    /**
     * @return array<string,mixed>
     */
    public function extraMetadata(): array
    {
        return $this->extraMetadata;
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'type' => 'file',
            'path' => $this->path,
            'file_size' => $this->fileSize,
            'visibility' => $this->visibility,
            'last_modified' => $this->lastModified,
            'mime_type' => $this->mimeType,
            'extra_metadata' => $this->extraMetadata,
        ];
    }
}
