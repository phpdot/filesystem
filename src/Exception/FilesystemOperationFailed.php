<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Exception;

/**
 * A filesystem operation (write, read, delete, ...) failed at the adapter level.
 */
interface FilesystemOperationFailed extends FilesystemException
{
    /**
     * The high-level operation that failed, e.g. "WRITE", "READ", "DELETE".
     */
    public function operation(): string;
}
