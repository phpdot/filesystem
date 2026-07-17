<?php

declare(strict_types=1);

/**
 * Maps the portable {@see Visibility} model onto POSIX permission bits and back.
 *
 * Public = world/group readable; private = owner-only. Defaults are the common,
 * portable choices (files 0644/0600, directories 0755/0700).
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Adapter;

use PHPdot\Filesystem\Visibility;

final class PortableVisibility
{
    /**
     * __construct.
     *
     * @param int $filePublic
     * @param int $filePrivate
     * @param int $directoryPublic
     * @param int $directoryPrivate
     */
    public function __construct(
        private readonly int $filePublic = 0644,
        private readonly int $filePrivate = 0600,
        private readonly int $directoryPublic = 0755,
        private readonly int $directoryPrivate = 0700,
    ) {}

    /**
     * For file.
     *
     * @param string $visibility
     *
     * @return int
     */
    public function forFile(string $visibility): int
    {
        return Visibility::parse($visibility) === Visibility::Public ? $this->filePublic : $this->filePrivate;
    }

    /**
     * For directory.
     *
     * @param string $visibility
     *
     * @return int
     */
    public function forDirectory(string $visibility): int
    {
        return Visibility::parse($visibility) === Visibility::Public ? $this->directoryPublic : $this->directoryPrivate;
    }

    /**
     * Inverse for file.
     *
     * @param int $permissions
     *
     * @return string
     */
    public function inverseForFile(int $permissions): string
    {
        return ($permissions & 0044) !== 0 ? Visibility::Public->value : Visibility::Private->value;
    }

    /**
     * Inverse for directory.
     *
     * @param int $permissions
     *
     * @return string
     */
    public function inverseForDirectory(int $permissions): string
    {
        return ($permissions & 0044) !== 0 ? Visibility::Public->value : Visibility::Private->value;
    }
}
