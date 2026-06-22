<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Exception;

use InvalidArgumentException;

final class InvalidVisibilityProvided extends InvalidArgumentException implements FilesystemException
{
    public function errorCode(): string
    {
        return 'filesystem.invalid_visibility';
    }

    public static function withVisibility(string $value): self
    {
        return new self("Invalid visibility provided: \"{$value}\". Expected \"public\" or \"private\".");
    }
}
