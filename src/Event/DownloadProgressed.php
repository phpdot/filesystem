<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Event;

/**
 * Dispatched as bytes flow during a read. `total` is null when the size is
 * unknown.
 */
final readonly class DownloadProgressed
{
    public function __construct(
        public string $path,
        public int $bytesTransferred,
        public ?int $total,
    ) {}
}
