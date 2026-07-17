<?php

declare(strict_types=1);

/**
 * Dispatched as bytes flow during a read. `total` is null when the size is
 * unknown.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Event;

final readonly class DownloadProgressed
{
    /**
     * __construct.
     *
     * @param string $path
     * @param int $bytesTransferred
     * @param ?int $total
     */
    public function __construct(
        public string $path,
        public int $bytesTransferred,
        public ?int $total,
    ) {}
}
