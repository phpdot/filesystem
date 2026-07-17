<?php

declare(strict_types=1);

/**
 * Dispatched after {@see \PHPdot\Filesystem\ManagedFiles\Files::store} has
 * written the bytes and persisted the record. An observer hook only — never the
 * mechanism by which records are created.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Event;

use PHPdot\Filesystem\ManagedFiles\FileRecord;

final readonly class FileStored
{
    /**
     * __construct.
     *
     * @param FileRecord $record
     */
    public function __construct(public FileRecord $record) {}
}
