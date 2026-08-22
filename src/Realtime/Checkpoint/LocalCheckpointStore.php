<?php

declare(strict_types=1);

/**
 * The working default: one JSON file, replaced atomically.
 *
 * Writes go to a temporary file in the same directory and are then renamed over
 * the target, because `rename` is atomic within a filesystem while a partial
 * `file_put_contents` is not. A checkpoint truncated by a crash mid-write would
 * resume the stream from a plausible but wrong position, which is worse than
 * having no checkpoint at all.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Realtime\Checkpoint;

use JsonException;
use PHPdot\Container\Attribute\Binds;
use PHPdot\Container\Attribute\Singleton;
use PHPdot\Filesystem\Exception\UnableToCreateDirectory;
use PHPdot\Filesystem\Exception\UnableToPersistCheckpoint;
use PHPdot\Filesystem\Realtime\BinlogConfig;
use PHPdot\Filesystem\Realtime\Change\Checkpoint;
use PHPdot\Filesystem\Realtime\Contract\CheckpointStoreInterface;

#[Singleton]
#[Binds(CheckpointStoreInterface::class)]
final class LocalCheckpointStore implements CheckpointStoreInterface
{
    private readonly string $file;

    /**
     * __construct.
     *
     * @param BinlogConfig $config
     */
    public function __construct(BinlogConfig $config = new BinlogConfig())
    {
        $this->file = $config->checkpointFile;
        $directory = dirname($this->file);

        if (!is_dir($directory) && !@mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw UnableToCreateDirectory::atLocation($directory);
        }
    }

    public function load(): Checkpoint
    {
        if (!is_file($this->file)) {
            return new Checkpoint();
        }

        $contents = @file_get_contents($this->file);

        if ($contents === false || $contents === '') {
            return new Checkpoint();
        }

        try {
            /** @var array{file?: string, position?: int, gtidSet?: ?string} $data */
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw UnableToPersistCheckpoint::corrupted($this->file);
        }

        return Checkpoint::fromArray($data);
    }

    public function save(Checkpoint $checkpoint): void
    {
        $temporary = $this->file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $encoded = json_encode($checkpoint->toArray(), JSON_THROW_ON_ERROR);

        if (@file_put_contents($temporary, $encoded) === false || !@rename($temporary, $this->file)) {
            @unlink($temporary);

            throw UnableToPersistCheckpoint::atLocation($this->file);
        }
    }
}
