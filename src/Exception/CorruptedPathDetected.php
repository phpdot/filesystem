<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Exception;

use RuntimeException;

final class CorruptedPathDetected extends RuntimeException implements FilesystemException
{
    public function errorCode(): string
    {
        return 'filesystem.corrupted_path';
    }

    public static function forPath(string $path): self
    {
        return new self("Corrupted path detected (contains control characters): {$path}.");
    }
}
