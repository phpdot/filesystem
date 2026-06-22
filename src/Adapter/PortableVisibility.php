<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Adapter;

use PHPdot\Filesystem\Visibility;

/**
 * Maps the portable {@see Visibility} model onto POSIX permission bits and back.
 *
 * Public = world/group readable; private = owner-only. Defaults are the common,
 * portable choices (files 0644/0600, directories 0755/0700).
 */
final class PortableVisibility
{
    public function __construct(
        private readonly int $filePublic = 0644,
        private readonly int $filePrivate = 0600,
        private readonly int $directoryPublic = 0755,
        private readonly int $directoryPrivate = 0700,
    ) {}

    public function forFile(string $visibility): int
    {
        return Visibility::parse($visibility) === Visibility::Public ? $this->filePublic : $this->filePrivate;
    }

    public function forDirectory(string $visibility): int
    {
        return Visibility::parse($visibility) === Visibility::Public ? $this->directoryPublic : $this->directoryPrivate;
    }

    public function inverseForFile(int $permissions): string
    {
        return ($permissions & 0044) !== 0 ? Visibility::Public->value : Visibility::Private->value;
    }

    public function inverseForDirectory(int $permissions): string
    {
        return ($permissions & 0044) !== 0 ? Visibility::Public->value : Visibility::Private->value;
    }
}
