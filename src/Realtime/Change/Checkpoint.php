<?php

declare(strict_types=1);

/**
 * Where the consumer has read up to.
 *
 * A checkpoint is the whole durability story for a replication stream: on
 * reconnect the server resumes from exactly this point, so persisting it too
 * early loses events and persisting a stale one replays them. Prefer the GTID
 * set where the server has GTIDs enabled — it survives a failover to a
 * different binlog file, and a file/position pair does not.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Realtime\Change;

final readonly class Checkpoint
{
    /**
     * __construct.
     *
     * @param string $file
     * @param int $position
     * @param ?string $gtidSet
     */
    public function __construct(
        public string $file = '',
        public int $position = 4,
        public null|string $gtidSet = null,
    ) {}

    /**
     * A checkpoint that names no starting point, so the server picks: the
     * current end of the binlog for a file/position dump.
     *
     * @return self
     */
    public static function start(): self
    {
        return new self();
    }

    /**
     * From gtid set.
     *
     * @param string $gtidSet
     *
     * @return self
     */
    public static function fromGtidSet(string $gtidSet): self
    {
        return new self('', 4, $gtidSet);
    }

    /**
     * Whether this checkpoint names a binlog file to resume from.
     *
     * @return bool
     */
    public function hasFile(): bool
    {
        return $this->file !== '';
    }

    /**
     * Whether this checkpoint carries a GTID set to resume from.
     *
     * @return bool
     */
    public function hasGtidSet(): bool
    {
        return $this->gtidSet !== null && $this->gtidSet !== '';
    }

    /**
     * With position.
     *
     * @param int $position
     *
     * @return self
     */
    public function withPosition(int $position): self
    {
        return new self($this->file, $position, $this->gtidSet);
    }

    /**
     * With file.
     *
     * @param string $file
     * @param int $position
     *
     * @return self
     */
    public function withFile(string $file, int $position): self
    {
        return new self($file, $position, $this->gtidSet);
    }

    /**
     * Fold a newly committed GTID into the set.
     *
     * @param ?string $gtidSet
     *
     * @return self
     */
    public function withGtidSet(null|string $gtidSet): self
    {
        return new self($this->file, $this->position, $gtidSet);
    }

    /**
     * To array.
     *
     * @return array{file: string, position: int, gtidSet: ?string}
     */
    public function toArray(): array
    {
        return ['file' => $this->file, 'position' => $this->position, 'gtidSet' => $this->gtidSet];
    }

    /**
     * From array.
     *
     * @param array{file?: string, position?: int, gtidSet?: ?string} $data
     *
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self($data['file'] ?? '', $data['position'] ?? 4, $data['gtidSet'] ?? null);
    }

    /**
     * A human-readable rendering, e.g. "binlog.000004:1985".
     *
     * @return string
     */
    public function describe(): string
    {
        $position = $this->hasFile() ? "{$this->file}:{$this->position}" : 'start of stream';

        return $this->hasGtidSet() ? "{$position} (gtid {$this->gtidSet})" : $position;
    }
}
