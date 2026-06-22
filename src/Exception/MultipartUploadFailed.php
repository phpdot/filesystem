<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Exception;

use RuntimeException;
use Throwable;

final class MultipartUploadFailed extends RuntimeException implements FilesystemException
{
    public function errorCode(): string
    {
        return 'filesystem.multipart_failed';
    }

    public static function withReason(string $reason, ?Throwable $previous = null): self
    {
        return new self("Multipart upload failed: {$reason}", 0, $previous);
    }
}
