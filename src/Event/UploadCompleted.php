<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Event;

/**
 * Dispatched once a write has finished successfully.
 */
final readonly class UploadCompleted
{
    public function __construct(
        public string $path,
        public int $bytesWritten,
    ) {}
}
