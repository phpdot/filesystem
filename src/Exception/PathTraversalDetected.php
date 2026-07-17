<?php

declare(strict_types=1);

/**
 * Thrown when a path attempts to escape its root via directory traversal.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Exception;

use RuntimeException;

final class PathTraversalDetected extends RuntimeException implements FilesystemException
{
    public function errorCode(): string
    {
        return 'filesystem.path_traversal';
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
        return new self("Path traversal detected, refusing to operate on: {$path}.");
    }
}
