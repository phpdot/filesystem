<?php

declare(strict_types=1);

/**
 * Thrown when a multipart (chunked) upload fails.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Exception;

use RuntimeException;
use Throwable;

final class MultipartUploadFailed extends RuntimeException implements FilesystemException
{
    public function errorCode(): string
    {
        return 'filesystem.multipart_failed';
    }

    /**
     * With reason.
     *
     * @param string $reason
     * @param ?Throwable $previous
     *
     * @return self
     */
    public static function withReason(string $reason, ?Throwable $previous = null): self
    {
        return new self("Multipart upload failed: {$reason}", 0, $previous);
    }
}
