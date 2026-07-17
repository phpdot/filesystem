<?php

declare(strict_types=1);

/**
 * A single entry returned when listing a directory: either a file or a directory.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Contract;

use JsonSerializable;

interface StorageAttributes extends JsonSerializable
{
    /**
     * Path.
     *
     * @return string
     */
    public function path(): string;

    /**
     * Is file.
     *
     * @return bool
     */
    public function isFile(): bool;

    /**
     * Is dir.
     *
     * @return bool
     */
    public function isDir(): bool;

    /**
     * Last modified.
     *
     * @return ?int
     */
    public function lastModified(): ?int;

    /**
     * Visibility.
     *
     * @return ?string
     */
    public function visibility(): ?string;

    /**
     * Return any adapter-specific extra metadata.
     *
     * @return array<string,mixed>
     */
    public function extraMetadata(): array;
}
