<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Exception;

use InvalidArgumentException;

final class InvalidConfigurationValue extends InvalidArgumentException implements FilesystemException
{
    public function errorCode(): string
    {
        return 'filesystem.invalid_config_value';
    }

    public static function forKey(string $key, string $expectedType): self
    {
        return new self("Configuration value for \"{$key}\" is not of the expected type: {$expectedType}.");
    }
}
