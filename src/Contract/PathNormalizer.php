<?php

declare(strict_types=1);

/**
 * Contract for normalizing and validating storage paths, rejecting traversal.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Contract;

use PHPdot\Filesystem\Exception\CorruptedPathDetected;
use PHPdot\Filesystem\Exception\PathTraversalDetected;

interface PathNormalizer
{
    /**
     * Normalize a path to a clean, root-relative form (no leading/trailing
     * separators, no "." or redundant "/" segments).
     *
     * @param string $path
     *
     * @throws PathTraversalDetected when a ".." segment escapes the root
     * @throws CorruptedPathDetected when the path contains control characters
     *
     * @return string
     */
    public function normalizePath(string $path): string;
}
