<?php

declare(strict_types=1);

/**
 * Thrown when a visibility value is neither public nor private.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Exception;

use InvalidArgumentException;

final class InvalidVisibilityProvided extends InvalidArgumentException implements FilesystemException
{
    public function errorCode(): string
    {
        return 'filesystem.invalid_visibility';
    }

    /**
     * With visibility.
     *
     * @param string $value
     *
     * @return self
     */
    public static function withVisibility(string $value): self
    {
        return new self("Invalid visibility provided: \"{$value}\". Expected \"public\" or \"private\".");
    }
}
