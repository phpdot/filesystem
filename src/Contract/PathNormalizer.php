<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Contract;

use PHPdot\Filesystem\Exception\CorruptedPathDetected;
use PHPdot\Filesystem\Exception\PathTraversalDetected;

interface PathNormalizer
{
    /**
     * Normalize a path to a clean, root-relative form (no leading/trailing
     * separators, no "." or redundant "/" segments).
     *
     * @throws PathTraversalDetected when a ".." segment escapes the root
     * @throws CorruptedPathDetected when the path contains control characters
     */
    public function normalizePath(string $path): string;
}
