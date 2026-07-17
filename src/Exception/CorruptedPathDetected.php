<?php

declare(strict_types=1);

/**
 * Thrown when a path is corrupted or malformed during normalization.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Exception;

use RuntimeException;

final class CorruptedPathDetected extends RuntimeException implements FilesystemException
{
    public function errorCode(): string
    {
        return 'filesystem.corrupted_path';
    }

    /**
     * For path.
     *
     * @param string $path
     *
     * @return self
     */
    public static function forPath(string $path): self
    {
        return new self("Corrupted path detected (contains control characters): {$path}.");
    }
}
