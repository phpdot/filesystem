<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Exception;

use RuntimeException;

final class PathTraversalDetected extends RuntimeException implements FilesystemException
{
    public function errorCode(): string
    {
        return 'filesystem.path_traversal';
    }

    public static function forPath(string $path): self
    {
        return new self("Path traversal detected, refusing to operate on: {$path}.");
    }
}
