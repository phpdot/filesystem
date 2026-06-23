<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Exception;

use RuntimeException;

/**
 * Thrown when {@see \PHPdot\Filesystem\Path\PathGenerator} cannot produce a
 * collision-free key — e.g. a pattern with no entropy that already exists, or
 * one that keeps colliding after the bounded number of retries.
 */
final class UnableToGeneratePath extends RuntimeException implements FilesystemException
{
    public function errorCode(): string
    {
        return 'filesystem.path_generation_failed';
    }

    public static function afterCollisions(string $pattern, int $attempts): self
    {
        return new self("Unable to generate a non-colliding path from pattern '{$pattern}' after {$attempts} attempt(s).");
    }

    public static function unknownToken(string $token): self
    {
        return new self("Unknown path token '{{$token}}'.");
    }

    public static function emptyKey(string $pattern): self
    {
        return new self("Pattern '{$pattern}' produced an empty storage key.");
    }

    public static function unknownHashAlgorithm(string $algo): self
    {
        return new self("Unknown hash algorithm '{$algo}' in a {hash:...} path token.");
    }
}
