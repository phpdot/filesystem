<?php

declare(strict_types=1);

/**
 * Thrown when a path metadata cannot be retrieved.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

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

    /**
     * Metadata type.
     *
     * @return string
     */
    public function metadataType(): string
    {
        return $this->metadataType;
    }

    /**
     * Mime type.
     *
     * @param string $path
     * @param string $reason
     * @param ?Throwable $previous
     *
     * @return self
     */
    public static function mimeType(string $path, string $reason = '', ?Throwable $previous = null): self
    {
        return self::create($path, 'mimeType', $reason, $previous);
    }

    /**
     * Last modified.
     *
     * @param string $path
     * @param string $reason
     * @param ?Throwable $previous
     *
     * @return self
     */
    public static function lastModified(string $path, string $reason = '', ?Throwable $previous = null): self
    {
        return self::create($path, 'lastModified', $reason, $previous);
    }

    /**
     * File size.
     *
     * @param string $path
     * @param string $reason
     * @param ?Throwable $previous
     *
     * @return self
     */
    public static function fileSize(string $path, string $reason = '', ?Throwable $previous = null): self
    {
        return self::create($path, 'fileSize', $reason, $previous);
    }

    /**
     * Visibility.
     *
     * @param string $path
     * @param string $reason
     * @param ?Throwable $previous
     *
     * @return self
     */
    public static function visibility(string $path, string $reason = '', ?Throwable $previous = null): self
    {
        return self::create($path, 'visibility', $reason, $previous);
    }

    /**
     * Checksum.
     *
     * @param string $path
     * @param string $reason
     * @param ?Throwable $previous
     *
     * @return self
     */
    public static function checksum(string $path, string $reason = '', ?Throwable $previous = null): self
    {
        return self::create($path, 'checksum', $reason, $previous);
    }

    /**
     * Create.
     *
     * @param string $path
     * @param string $type
     * @param string $reason
     * @param ?Throwable $previous
     *
     * @return self
     */
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
