<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Exception;

use RuntimeException;
use Throwable;

final class UnableToDeleteFile extends RuntimeException implements FilesystemOperationFailed
{
    public function errorCode(): string
    {
        return 'filesystem.delete_failed';
    }

    public function operation(): string
    {
        return 'DELETE';
    }

    public static function atLocation(string $path, string $reason = '', ?Throwable $previous = null): self
    {
        $message = "Unable to delete file at location: {$path}.";

        if ($reason !== '') {
            $message .= ' ' . $reason;
        }

        return new self($message, 0, $previous);
    }
}
