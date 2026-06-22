<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Attributes;

use PHPdot\Filesystem\Contract\StorageAttributes;

final readonly class DirectoryAttributes implements StorageAttributes
{
    /**
     * @param array<string,mixed> $extraMetadata
     */
    public function __construct(
        private string $path,
        private ?string $visibility = null,
        private ?int $lastModified = null,
        private array $extraMetadata = [],
    ) {}

    public function path(): string
    {
        return $this->path;
    }

    public function isFile(): bool
    {
        return false;
    }

    public function isDir(): bool
    {
        return true;
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
            'type' => 'dir',
            'path' => $this->path,
            'visibility' => $this->visibility,
            'last_modified' => $this->lastModified,
            'extra_metadata' => $this->extraMetadata,
        ];
    }
}
