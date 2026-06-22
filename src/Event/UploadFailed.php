<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Event;

use Throwable;

/**
 * Dispatched when a write fails; carries the underlying error.
 */
final readonly class UploadFailed
{
    public function __construct(
        public string $path,
        public Throwable $error,
    ) {}
}
