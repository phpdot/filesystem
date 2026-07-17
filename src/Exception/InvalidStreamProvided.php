<?php

declare(strict_types=1);

/**
 * Thrown when a value expected to be a readable stream resource is not.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Exception;

use InvalidArgumentException;

final class InvalidStreamProvided extends InvalidArgumentException implements FilesystemException
{
    public function errorCode(): string
    {
        return 'filesystem.invalid_stream';
    }

    /**
     * Because not readable.
     *
     * @return self
     */
    public static function becauseNotReadable(): self
    {
        return new self('The provided stream is not readable.');
    }

    /**
     * Because unsupported type.
     *
     * @param string $type
     *
     * @return self
     */
    public static function becauseUnsupportedType(string $type): self
    {
        return new self("Cannot write contents of unsupported type: {$type}.");
    }
}
