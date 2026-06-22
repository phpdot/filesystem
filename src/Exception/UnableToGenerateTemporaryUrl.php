<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Exception;

use RuntimeException;
use Throwable;

final class UnableToGenerateTemporaryUrl extends RuntimeException implements FilesystemOperationFailed
{
    public function errorCode(): string
    {
        return 'filesystem.temporary_url_failed';
    }

    public function operation(): string
    {
        return 'GENERATE_TEMPORARY_URL';
    }

    public static function notSupported(string $path): self
    {
        return new self("Unable to generate a temporary URL for {$path}: the adapter does not support temporary URLs.");
    }

    public static function dueToError(string $path, ?Throwable $previous = null): self
    {
        return new self("Unable to generate a temporary URL for {$path}.", 0, $previous);
    }
}
