<?php

declare(strict_types=1);

/**
 * Durable storage for the replication checkpoint.
 *
 * The store is the difference between at-least-once delivery and silent data
 * loss: whatever it last accepted is where the stream resumes after a crash.
 * Implementations must make {@see save} atomic — a half-written checkpoint is
 * worse than none, because it resumes from a plausible but wrong position.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Realtime\Contract;

use PHPdot\Filesystem\Realtime\Change\Checkpoint;

interface CheckpointStoreInterface
{
    /**
     * The stored checkpoint, or an empty one when nothing has been stored yet.
     *
     * @return Checkpoint
     */
    public function load(): Checkpoint;

    /**
     * Persist the checkpoint, atomically.
     *
     * @param Checkpoint $checkpoint
     *
     * @return void
     */
    public function save(Checkpoint $checkpoint): void;
}
