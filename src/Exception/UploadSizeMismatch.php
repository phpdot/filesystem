<?php

declare(strict_types=1);

/**
 * The bytes a multipart upload actually holds do not match the session's
 * declaration, in either direction; complete() aborts the upload before
 * throwing this, so the parts are never left orphaned on the storage.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Exception;

use RuntimeException;

final class UploadSizeMismatch extends RuntimeException implements FilesystemException
{
    private int $declaredBytes = 0;

    private int $receivedBytes = 0;

    public function errorCode(): string
    {
        return 'filesystem.upload_size_mismatch';
    }

    /**
     * Declared bytes.
     *
     * @return int
     */
    public function declaredBytes(): int
    {
        return $this->declaredBytes;
    }

    /**
     * Received bytes.
     *
     * @return int
     */
    public function receivedBytes(): int
    {
        return $this->receivedBytes;
    }

    /**
     * Declared.
     *
     * @param int $declared
     * @param int $received
     *
     * @return self
     */
    public static function declared(int $declared, int $received): self
    {
        $exception = new self(
            "Upload size mismatch: received {$received} of {$declared} declared bytes; the multipart upload was aborted.",
        );
        $exception->declaredBytes = $declared;
        $exception->receivedBytes = $received;

        return $exception;
    }
}
