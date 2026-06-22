<?php

declare(strict_types=1);
namespace PHPdot\Filesystem;

use PHPdot\Filesystem\Exception\InvalidVisibilityProvided;

enum Visibility: string
{
    case Public = 'public';
    case Private = 'private';

    /**
     * Parse a raw string into a Visibility, throwing a domain exception on a
     * value that is neither "public" nor "private".
     */
    public static function parse(string $value): self
    {
        return self::tryFrom($value) ?? throw InvalidVisibilityProvided::withVisibility($value);
    }

    public function isPublic(): bool
    {
        return $this === self::Public;
    }
}
