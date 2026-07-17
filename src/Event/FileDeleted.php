<?php

declare(strict_types=1);

/**
 * Dispatched after {@see \PHPdot\Filesystem\ManagedFiles\Files::delete} has
 * quarantined the bytes and flagged the record deleted.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Event;

use PHPdot\Filesystem\ManagedFiles\FileRecord;

final readonly class FileDeleted
{
    /**
     * __construct.
     *
     * @param FileRecord $record
     */
    public function __construct(public FileRecord $record) {}
}
