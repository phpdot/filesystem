<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Exception;

use RuntimeException;
use Throwable;

final class UnableToReadFile extends RuntimeException implements FilesystemOperationFailed
{
    public function errorCode(): string
    {
        return 'filesystem.read_failed';
    }

    public function operation(): string
    {
        return 'READ';
    }

    public static function fromLocation(string $path, string $reason = '', ?Throwable $previous = null): self
    {
        $message = "Unable to read file from location: {$path}.";

        if ($reason !== '') {
            $message .= ' ' . $reason;
        }

        return new self($message, 0, $previous);
    }
}
