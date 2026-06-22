<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Contract;

use JsonSerializable;

/**
 * A single entry returned when listing a directory: either a file or a directory.
 */
interface StorageAttributes extends JsonSerializable
{
    public function path(): string;

    public function isFile(): bool;

    public function isDir(): bool;

    public function lastModified(): ?int;

    public function visibility(): ?string;

    /**
     * @return array<string,mixed>
     */
    public function extraMetadata(): array;
}
