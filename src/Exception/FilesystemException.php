<?php

declare(strict_types=1);

/**
 * Marker implemented by every exception thrown by phpdot/filesystem.
 *
 * Catch this to handle anything originating from the library.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Exception;

use Throwable;

interface FilesystemException extends Throwable
{
    /**
     * A stable, machine-readable error code, e.g. "filesystem.write_failed".
     *
     * @return string
     */
    public function errorCode(): string;
}
