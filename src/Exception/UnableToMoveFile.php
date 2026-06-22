<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Exception;

use RuntimeException;
use Throwable;

final class UnableToMoveFile extends RuntimeException implements FilesystemOperationFailed
{
    public function errorCode(): string
    {
        return 'filesystem.move_failed';
    }

    public function operation(): string
    {
        return 'MOVE';
    }

    public static function fromTo(string $source, string $destination, string $reason = '', ?Throwable $previous = null): self
    {
        $message = "Unable to move file from {$source} to {$destination}.";

        if ($reason !== '') {
            $message .= ' ' . $reason;
        }

        return new self($message, 0, $previous);
    }
}
