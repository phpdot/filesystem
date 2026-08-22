<?php

declare(strict_types=1);

/**
 * One row change, decoded and normalized.
 *
 * This is the package's realtime unit: a single row's before and/or after image
 * keyed by column name, plus the checkpoint it was read at. Dispatched through
 * PSR-14, so any listener can fan it out to WebSockets, a queue, or a cache
 * invalidation.
 *
 * `before` is empty for an insert and `after` is empty for a delete. Under
 * `binlog_row_image = MINIMAL` both are partial — only the columns MySQL chose
 * to log are present, which is why {@see changedColumns} reads keys rather than
 * assuming a full row.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Realtime\Change;

use DateTimeImmutable;

final readonly class ChangeEvent
{
    /**
     * __construct.
     *
     * @param ChangeKind $kind
     * @param string $database
     * @param string $table
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @param array<string,mixed> $primaryKey
     * @param DateTimeImmutable $occurredAt
     * @param Checkpoint $checkpoint
     * @param ?string $gtid
     */
    public function __construct(
        public ChangeKind $kind,
        public string $database,
        public string $table,
        public array $before,
        public array $after,
        public array $primaryKey,
        public DateTimeImmutable $occurredAt,
        public Checkpoint $checkpoint,
        public null|string $gtid = null,
    ) {}

    /**
     * Fully qualified `database.table`.
     *
     * @return string
     */
    public function qualifiedName(): string
    {
        return $this->database . '.' . $this->table;
    }

    /**
     * The row as it now stands — the after image, or the before image for a
     * delete.
     *
     * @return array<string,mixed>
     */
    public function row(): array
    {
        return $this->kind === ChangeKind::DELETE ? $this->before : $this->after;
    }

    /**
     * Columns whose value actually differs between the two images. Empty for
     * anything but an update.
     *
     * @return list<string>
     */
    public function changedColumns(): array
    {
        if ($this->kind !== ChangeKind::UPDATE) {
            return [];
        }

        $changed = [];

        foreach ($this->after as $column => $value) {
            if (!array_key_exists($column, $this->before) || $this->before[$column] !== $value) {
                $changed[] = $column;
            }
        }

        return $changed;
    }

    /**
     * Whether the named column changed in this event.
     *
     * @param string $column
     *
     * @return bool
     */
    public function touches(string $column): bool
    {
        return in_array($column, $this->changedColumns(), true);
    }

    /**
     * A JSON-safe rendering, suitable for putting straight onto a socket.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'database' => $this->database,
            'table' => $this->table,
            'primaryKey' => $this->primaryKey,
            'before' => $this->before,
            'after' => $this->after,
            'occurredAt' => $this->occurredAt->format(DateTimeImmutable::ATOM),
            'checkpoint' => $this->checkpoint->toArray(),
            'gtid' => $this->gtid,
        ];
    }
}
