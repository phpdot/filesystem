<?php

declare(strict_types=1);

/**
 * Thrown when {@see \PHPdot\Filesystem\Path\PathGenerator} cannot produce a
 * collision-free key — e.g. a pattern with no entropy that already exists, or
 * one that keeps colliding after the bounded number of retries.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Exception;

use RuntimeException;

final class UnableToGeneratePath extends RuntimeException implements FilesystemException
{
    public function errorCode(): string
    {
        return 'filesystem.path_generation_failed';
    }

    /**
     * After collisions.
     *
     * @param string $pattern
     * @param int $attempts
     *
     * @return self
     */
    public static function afterCollisions(string $pattern, int $attempts): self
    {
        return new self("Unable to generate a non-colliding path from pattern '{$pattern}' after {$attempts} attempt(s).");
    }

    /**
     * Unknown token.
     *
     * @param string $token
     *
     * @return self
     */
    public static function unknownToken(string $token): self
    {
        return new self("Unknown path token '{{$token}}'.");
    }

    /**
     * Empty key.
     *
     * @param string $pattern
     *
     * @return self
     */
    public static function emptyKey(string $pattern): self
    {
        return new self("Pattern '{$pattern}' produced an empty storage key.");
    }

    /**
     * Unknown hash algorithm.
     *
     * @param string $algo
     *
     * @return self
     */
    public static function unknownHashAlgorithm(string $algo): self
    {
        return new self("Unknown hash algorithm '{$algo}' in a {hash:...} path token.");
    }
}
