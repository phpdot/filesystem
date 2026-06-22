<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Event;

/**
 * Dispatched as bytes flow during a write. `total` is null when the size is
 * unknown.
 */
final readonly class UploadProgressed
{
    public function __construct(
        public string $path,
        public int $bytesTransferred,
        public ?int $total,
    ) {}
}
