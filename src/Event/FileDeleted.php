<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Event;

use PHPdot\Filesystem\ManagedFiles\FileRecord;

/**
 * Dispatched after {@see \PHPdot\Filesystem\ManagedFiles\Files::delete} has
 * quarantined the bytes and flagged the record deleted.
 */
final readonly class FileDeleted
{
    public function __construct(public FileRecord $record) {}
}
