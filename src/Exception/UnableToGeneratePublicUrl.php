<?php

declare(strict_types=1);

/**
 * Thrown when a public URL cannot be generated for a path.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Exception;

use RuntimeException;
use Throwable;

final class UnableToGeneratePublicUrl extends RuntimeException implements FilesystemOperationFailed
{
    public function errorCode(): string
    {
        return 'filesystem.public_url_failed';
    }

    public function operation(): string
    {
        return 'GENERATE_PUBLIC_URL';
    }

    /**
     * No generator configured.
     *
     * @param string $path
     *
     * @return self
     */
    public static function noGeneratorConfigured(string $path): self
    {
        return new self("Unable to generate a public URL for {$path}: the adapter has no public URL configured.");
    }

    /**
     * Due to error.
     *
     * @param string $path
     * @param ?Throwable $previous
     *
     * @return self
     */
    public static function dueToError(string $path, ?Throwable $previous = null): self
    {
        return new self("Unable to generate a public URL for {$path}.", 0, $previous);
    }
}
