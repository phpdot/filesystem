<?php

declare(strict_types=1);

/**
 * Dispatched when a write fails; carries the underlying error.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Event;

use Throwable;

final readonly class UploadFailed
{
    /**
     * __construct.
     *
     * @param string $path
     * @param Throwable $error
     */
    public function __construct(
        public string $path,
        public Throwable $error,
    ) {}
}
