<?php

declare(strict_types=1);

/**
 * Thrown when a presigned direct-upload URL cannot be generated for a path.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Exception;

use RuntimeException;
use Throwable;

final class UnableToPresignUpload extends RuntimeException implements FilesystemOperationFailed
{
    public function errorCode(): string
    {
        return 'filesystem.presign_upload_failed';
    }

    public function operation(): string
    {
        return 'PRESIGN_UPLOAD';
    }

    /**
     * Not supported.
     *
     * @param string $path
     *
     * @return self
     */
    public static function notSupported(string $path): self
    {
        return new self("Unable to presign an upload for {$path}: the adapter does not support presigned uploads.");
    }

    /**
     * Due to error.
     *
     * @param string $path
     * @param ?Throwable $previous
     *
     * @return self
     */
    public static function dueToError(string $path, null|Throwable $previous = null): self
    {
        return new self("Unable to presign an upload for {$path}.", 0, $previous);
    }
}
