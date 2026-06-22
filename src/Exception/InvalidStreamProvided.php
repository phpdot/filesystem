<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Exception;

use InvalidArgumentException;

final class InvalidStreamProvided extends InvalidArgumentException implements FilesystemException
{
    public function errorCode(): string
    {
        return 'filesystem.invalid_stream';
    }

    public static function becauseNotReadable(): self
    {
        return new self('The provided stream is not readable.');
    }

    public static function becauseUnsupportedType(string $type): self
    {
        return new self("Cannot write contents of unsupported type: {$type}.");
    }
}
