<?php

declare(strict_types=1);

/**
 * A checkpoint store that forgets on restart.
 *
 * Useful for tests and for consumers that genuinely want to start from the
 * live end of the binlog every time; never for a consumer that must not miss
 * events across a deploy.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Realtime\Checkpoint;

use PHPdot\Filesystem\Realtime\Change\Checkpoint;
use PHPdot\Filesystem\Realtime\Contract\CheckpointStoreInterface;

final class InMemoryCheckpointStore implements CheckpointStoreInterface
{
    /**
     * __construct.
     *
     * @param Checkpoint $checkpoint
     */
    public function __construct(private Checkpoint $checkpoint = new Checkpoint()) {}

    public function load(): Checkpoint
    {
        return $this->checkpoint;
    }

    public function save(Checkpoint $checkpoint): void
    {
        $this->checkpoint = $checkpoint;
    }
}
