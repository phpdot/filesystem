<?php

declare(strict_types=1);

/**
 * Visibility levels for stored entries: public or private.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem;

use PHPdot\Filesystem\Exception\InvalidVisibilityProvided;

enum Visibility: string
{
    case Public = 'public';
    case Private = 'private';

    /**
     * Parse a raw string into a Visibility, throwing a domain exception on a
     * value that is neither "public" nor "private".
     *
     * @param string $value
     *
     * @return self
     */
    public static function parse(string $value): self
    {
        return self::tryFrom($value) ?? throw InvalidVisibilityProvided::withVisibility($value);
    }

    /**
     * Is public.
     *
     * @return bool
     */
    public function isPublic(): bool
    {
        return $this === self::Public;
    }
}
