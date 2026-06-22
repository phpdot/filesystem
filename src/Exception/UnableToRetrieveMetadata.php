<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Exception;

use RuntimeException;
use Throwable;

final class UnableToRetrieveMetadata extends RuntimeException implements FilesystemOperationFailed
{
    private string $metadataType = '';

    public function errorCode(): string
    {
        return 'filesystem.retrieve_metadata_failed';
    }

    public function operation(): string
    {
        return 'RETRIEVE_METADATA';
    }

    public function metadataType(): string
    {
        return $this->metadataType;
    }

    public static function mimeType(string $path, string $reason = '', ?Throwable $previous = null): self
    {
        return self::create($path, 'mimeType', $reason, $previous);
    }

    public static function lastModified(string $path, string $reason = '', ?Throwable $previous = null): self
    {
        return self::create($path, 'lastModified', $reason, $previous);
    }

    public static function fileSize(string $path, string $reason = '', ?Throwable $previous = null): self
    {
        return self::create($path, 'fileSize', $reason, $previous);
    }

    public static function visibility(string $path, string $reason = '', ?Throwable $previous = null): self
    {
        return self::create($path, 'visibility', $reason, $previous);
    }

    public static function checksum(string $path, string $reason = '', ?Throwable $previous = null): self
    {
        return self::create($path, 'checksum', $reason, $previous);
    }

    private static function create(string $path, string $type, string $reason, ?Throwable $previous): self
    {
        $message = "Unable to retrieve the {$type} for file at location: {$path}.";

        if ($reason !== '') {
            $message .= ' ' . $reason;
        }

        $exception = new self($message, 0, $previous);
        $exception->metadataType = $type;

        return $exception;
    }
}
