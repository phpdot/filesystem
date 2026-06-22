<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Exception;

use Throwable;

/**
 * Marker implemented by every exception thrown by phpdot/filesystem.
 *
 * Catch this to handle anything originating from the library.
 */
interface FilesystemException extends Throwable
{
    /**
     * A stable, machine-readable error code, e.g. "filesystem.write_failed".
     */
    public function errorCode(): string;
}
