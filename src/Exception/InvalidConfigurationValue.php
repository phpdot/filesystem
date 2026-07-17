<?php

declare(strict_types=1);

/**
 * Thrown when a filesystem configuration value is invalid.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Exception;

use InvalidArgumentException;

final class InvalidConfigurationValue extends InvalidArgumentException implements FilesystemException
{
    public function errorCode(): string
    {
        return 'filesystem.invalid_config_value';
    }

    /**
     * For key.
     *
     * @param string $key
     * @param string $expectedType
     *
     * @return self
     */
    public static function forKey(string $key, string $expectedType): self
    {
        return new self("Configuration value for \"{$key}\" is not of the expected type: {$expectedType}.");
    }
}
