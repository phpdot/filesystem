<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Event;

use PHPdot\Filesystem\ManagedFiles\FileRecord;

/**
 * Dispatched after {@see \PHPdot\Filesystem\ManagedFiles\Files::store} has
 * written the bytes and persisted the record. An observer hook only — never the
 * mechanism by which records are created.
 */
final readonly class FileStored
{
    public function __construct(public FileRecord $record) {}
}
