<?php

declare(strict_types=1);

/**
 * Storage attributes for a directory entry: path, visibility, and last-modified time.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Attributes;

use PHPdot\Filesystem\Contract\StorageAttributes;

final readonly class DirectoryAttributes implements StorageAttributes
{
    /**
     * Directory storage attributes: path, visibility, and last-modified time.
     *
     * @param array<string,mixed> $extraMetadata
     * @param string $path
     * @param ?string $visibility
     * @param ?int $lastModified
     */
    public function __construct(
        private string $path,
        private null|string $visibility = null,
        private null|int $lastModified = null,
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

    public function visibility(): null|string
    {
        return $this->visibility;
    }

    public function lastModified(): null|int
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
