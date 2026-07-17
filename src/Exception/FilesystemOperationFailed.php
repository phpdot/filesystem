<?php

declare(strict_types=1);

/**
 * A filesystem operation (write, read, delete, ...) failed at the adapter level.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Exception;

interface FilesystemOperationFailed extends FilesystemException
{
    /**
     * The high-level operation that failed, e.g. "WRITE", "READ", "DELETE".
     *
     * @return string
     */
    public function operation(): string;
}
