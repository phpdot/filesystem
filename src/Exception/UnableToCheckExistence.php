<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Exception;

use RuntimeException;
use Throwable;

final class UnableToCheckExistence extends RuntimeException implements FilesystemOperationFailed
{
    public function errorCode(): string
    {
        return 'filesystem.existence_check_failed';
    }

    public function operation(): string
    {
        return 'EXISTENCE_CHECK';
    }

    public static function forLocation(string $path, ?Throwable $previous = null): self
    {
        return new self("Unable to check existence for: {$path}.", 0, $previous);
    }
}
