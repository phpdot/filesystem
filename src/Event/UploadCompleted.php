<?php

declare(strict_types=1);

/**
 * Dispatched once a write has finished successfully.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Event;

final readonly class UploadCompleted
{
    /**
     * __construct.
     *
     * @param string $path
     * @param int $bytesWritten
     */
    public function __construct(
        public string $path,
        public int $bytesWritten,
    ) {}
}
