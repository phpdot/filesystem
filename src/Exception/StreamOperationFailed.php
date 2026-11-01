<?php

declare(strict_types=1);

/**
 * A stream the package hands out refused an operation, as PSR-7 has a stream refuse: a write to a read-only stream,
 * or a seek outside it.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Exception;

use RuntimeException;

final class StreamOperationFailed extends RuntimeException implements FilesystemException
{
    public function errorCode(): string
    {
        return 'filesystem.stream_operation_failed';
    }

    public static function becauseReadOnly(): self
    {
        return new self('The stream is read-only.');
    }

    public static function becauseOutsideWindow(int $target, int $limit): self
    {
        return new self("Cannot seek to {$target}: the stream holds bytes 0 to {$limit}.");
    }
}
