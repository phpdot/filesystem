<?php

declare(strict_types=1);

/**
 * The replication checkpoint could not be durably stored, so a restart would
 * silently rewind or skip events. Fatal by design.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Exception;

use RuntimeException;

final class UnableToPersistCheckpoint extends RuntimeException implements FilesystemException
{
    public function errorCode(): string
    {
        return 'filesystem.unable_to_persist_checkpoint';
    }

    /**
     * At location.
     *
     * @param string $location
     *
     * @return self
     */
    public static function atLocation(string $location): self
    {
        return new self("Unable to persist the replication checkpoint at \"{$location}\".");
    }

    /**
     * Corrupted.
     *
     * @param string $location
     *
     * @return self
     */
    public static function corrupted(string $location): self
    {
        return new self("The replication checkpoint at \"{$location}\" is unreadable or corrupted.");
    }
}
