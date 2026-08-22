<?php

declare(strict_types=1);

/**
 * Turns raw binlog events into {@see ChangeEvent}s.
 *
 * The binlog is a *stateful* stream, not a series of self-describing records.
 * A row event names only a numeric table id and a packed blob of values; the
 * column layout arrived earlier in a TABLE_MAP event, the current file name
 * arrived in a ROTATE, and the transaction identity arrived in a GTID event.
 * This class is where that state lives, which is also why one reader must see
 * every event on a connection in order — handing it a subset silently
 * mis-decodes rows.
 *
 * Deliberately transport-free: it is fed byte strings, so the whole decode path
 * is testable without a MySQL server.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Realtime\Binlog;

use PHPdot\Filesystem\Exception\MalformedBinlogPacket;
use PHPdot\Filesystem\Exception\UnsupportedBinlogFeature;
use PHPdot\Filesystem\Realtime\Change\ChangeEvent;
use PHPdot\Filesystem\Realtime\Change\Checkpoint;
use PHPdot\Filesystem\Realtime\Change\GtidSet;
use PHPdot\Filesystem\Realtime\Protocol\PacketReader;

final class EventReader
{
    /**
     * @var array<int,TableMap>
     */
    private array $tableMaps = [];

    private int $checksumLength = 0;

    private Checkpoint $checkpoint;

    private GtidSet $gtidSet;

    private null|string $pendingGtid = null;

    /**
     * __construct.
     *
     * @param Checkpoint $checkpoint
     * @param list<string> $tables
     */
    public function __construct(Checkpoint $checkpoint = new Checkpoint(), private readonly array $tables = [])
    {
        $this->checkpoint = $checkpoint;
        $this->gtidSet = $checkpoint->hasGtidSet() ? GtidSet::parse($checkpoint->gtidSet ?? '') : GtidSet::empty();
    }

    /**
     * Whether events on this stream carry a trailing CRC32.
     *
     * Taken from the server's `binlog_checksum` at connect rather than sniffed
     * from the stream: guessing wrong shifts every subsequent field by four
     * bytes, which corrupts values rather than failing loudly.
     *
     * @param bool $enabled
     *
     * @return void
     */
    public function useChecksum(bool $enabled): void
    {
        $this->checksumLength = $enabled ? 4 : 0;
    }

    /**
     * The point the stream has been consumed to.
     *
     * @return Checkpoint
     */
    public function checkpoint(): Checkpoint
    {
        return $this->checkpoint;
    }

    /**
     * Decode one binlog event, returning any row changes it carried.
     *
     * Most events yield nothing — they are the bookkeeping that makes the next
     * row event decodable.
     *
     * @param string $event
     *
     * @return list<ChangeEvent>
     */
    public function consume(string $event): array
    {
        $reader = new PacketReader($event);
        $header = EventHeader::decode($reader);
        $type = $header->type();

        $body = new PacketReader($reader->take(min($header->bodyLength($this->checksumLength), $reader->remaining())));

        // logPosition is the offset of the *next* event, which is exactly what a
        // resume should ask for. A zero means a synthetic event (the fake ROTATE
        // that opens a dump), which must not move the checkpoint.
        if ($header->logPosition > 0) {
            $this->checkpoint = $this->checkpoint->withPosition($header->logPosition);
        }

        if ($type === null) {
            return [];
        }

        return match (true) {
            $type === EventType::ROTATE => $this->handleRotate($body),
            $type === EventType::FORMAT_DESCRIPTION => $this->handleFormatDescription(),
            $type === EventType::TABLE_MAP => $this->handleTableMap($body),
            $type === EventType::GTID => $this->handleGtid($body),
            $type === EventType::XID => $this->commitTransaction(),
            $type === EventType::QUERY => $this->handleQuery($body),
            $type === EventType::TRANSACTION_PAYLOAD => throw UnsupportedBinlogFeature::serverSetting(
                'binlog_transaction_compression',
                'ON',
                'OFF',
            ),
            $type === EventType::PARTIAL_UPDATE_ROWS => throw UnsupportedBinlogFeature::serverSetting(
                'binlog_row_value_options',
                'PARTIAL_JSON',
                '',
            ),
            $type->isRowsEvent() => $this->handleRows($type, $body, $header),
            default => [],
        };
    }

    /**
     * ROTATE names the file the stream continues in — the only place the file
     * name is ever stated.
     *
     * @param PacketReader $body
     *
     * @return list<ChangeEvent>
     */
    private function handleRotate(PacketReader $body): array
    {
        $position = $body->uint64();
        $file = $body->rest();

        $this->checkpoint = $this->checkpoint->withFile($file, $position);

        return [];
    }

    /**
     * A FORMAT_DESCRIPTION opens every binlog file. Any cached table map belongs
     * to the previous file and must not be reused.
     *
     * @return list<ChangeEvent>
     */
    private function handleFormatDescription(): array
    {
        $this->tableMaps = [];

        return [];
    }

    /**
     * Handle table map.
     *
     * @param PacketReader $body
     *
     * @return list<ChangeEvent>
     */
    private function handleTableMap(PacketReader $body): array
    {
        $map = TableMap::decode($body);
        $this->tableMaps[$map->tableId] = $map;

        return [];
    }

    /**
     * GTID announces the identity of the transaction about to be logged. It is
     * held pending until a commit is seen, so an interrupted transaction never
     * lands in the executed set.
     *
     * @param PacketReader $body
     *
     * @return list<ChangeEvent>
     */
    private function handleGtid(PacketReader $body): array
    {
        $body->skip(1); // flags
        $uuid = bin2hex($body->take(16));
        $number = $body->int64();

        $this->pendingGtid = sprintf(
            '%s-%s-%s-%s-%s:%d',
            substr($uuid, 0, 8),
            substr($uuid, 8, 4),
            substr($uuid, 12, 4),
            substr($uuid, 16, 4),
            substr($uuid, 20, 12),
            $number,
        );

        return [];
    }

    /**
     * QUERY carries either a transaction boundary or a DDL statement. DDL
     * invalidates every cached column layout.
     *
     * @param PacketReader $body
     *
     * @return list<ChangeEvent>
     */
    private function handleQuery(PacketReader $body): array
    {
        $body->skip(4 + 4); // thread id, execution time
        $schemaLength = $body->uint8();
        $body->skip(2); // error code
        $body->skip($body->uint16()); // status variables
        $body->skip($schemaLength + 1);

        $statement = strtoupper(trim($body->rest()));

        if ($statement === 'BEGIN') {
            return [];
        }

        // Anything else at this level committed: an explicit COMMIT, or a DDL
        // statement, which under GTID is its own transaction.
        if ($statement !== 'ROLLBACK') {
            $this->commitTransaction();
        }

        if (!str_starts_with($statement, 'COMMIT')) {
            $this->tableMaps = [];
        }

        return [];
    }

    /**
     * Fold the pending GTID into the executed set and expose it on the
     * checkpoint.
     *
     * @return list<ChangeEvent>
     */
    private function commitTransaction(): array
    {
        if ($this->pendingGtid === null) {
            return [];
        }

        [$uuid, $number] = explode(':', $this->pendingGtid, 2);

        $this->gtidSet = $this->gtidSet->with($uuid, (int) $number);
        $this->checkpoint = $this->checkpoint->withGtidSet($this->gtidSet->toString());
        $this->pendingGtid = null;

        return [];
    }

    /**
     * Decode a row event against its TABLE_MAP.
     *
     * @param EventType $type
     * @param PacketReader $body
     * @param EventHeader $header
     *
     * @return list<ChangeEvent>
     */
    private function handleRows(EventType $type, PacketReader $body, EventHeader $header): array
    {
        $tableId = $body->uint48();
        $body->skip(2); // flags

        if ($type->hasExtraRowData()) {
            // The length is inclusive of its own two bytes.
            $body->skip(max(0, $body->uint16() - 2));
        }

        $map = $this->tableMaps[$tableId] ?? throw MalformedBinlogPacket::unmappedTable($tableId);

        $columnCount = $body->lengthEncodedInt() ?? 0;
        $firstBitmap = $body->bitmap($columnCount);
        $secondBitmap = $type->hasSecondBitmap() ? $body->bitmap($columnCount) : $firstBitmap;

        if (!$this->accepts($map)) {
            return [];
        }

        $beforeColumns = $type->hasBeforeImage() ? $firstBitmap : [];
        $afterColumns = $type->hasAfterImage() ? $secondBitmap : [];

        $kind = $type->changeKind();
        $changes = [];

        while (!$body->eof()) {
            $before = $beforeColumns === [] ? [] : $this->readRow($body, $map, $beforeColumns);
            $after = $afterColumns === [] ? [] : $this->readRow($body, $map, $afterColumns);

            $changes[] = new ChangeEvent(
                $kind,
                $map->database,
                $map->table,
                $before,
                $after,
                $this->primaryKeyOf($map, $after === [] ? $before : $after),
                $header->occurredAt(),
                $this->checkpoint,
                $this->pendingGtid,
            );
        }

        return $changes;
    }

    /**
     * Read one row image: a null bitmap over the present columns, then the
     * non-null values back to back.
     *
     * @param PacketReader $body
     * @param TableMap $map
     * @param list<bool> $present
     *
     * @return array<string,mixed>
     */
    private function readRow(PacketReader $body, TableMap $map, array $present): array
    {
        $presentCount = count(array_filter($present));
        $nulls = $body->bitmap($presentCount);

        $row = [];
        $cursor = 0;

        foreach ($map->columnTypes as $index => $type) {
            if (($present[$index] ?? false) !== true) {
                continue;
            }

            $name = $map->columnName($index);
            $metadata = $map->columnMetadata[$index] ?? 0;

            $row[$name] = ($nulls[$cursor] ?? false)
                ? null
                : ValueDecoder::decode($body, $type, $metadata, $map->isUnsigned($index), $this->labelsFor($map, $index, $type, $metadata));

            ++$cursor;
        }

        return $row;
    }

    /**
     * ENUM/SET labels for a column, when the server sent them.
     *
     * @param TableMap $map
     * @param int $index
     * @param ColumnType $type
     * @param int $metadata
     *
     * @return list<string>
     */
    private function labelsFor(TableMap $map, int $index, ColumnType $type, int $metadata): array
    {
        return match (TableMap::effectiveType($type, $metadata)) {
            ColumnType::ENUM => $map->enumValues[$index] ?? [],
            ColumnType::SET => $map->setValues[$index] ?? [],
            default => [],
        };
    }

    /**
     * The primary key columns of a row, when the server sent the key definition.
     *
     * @param TableMap $map
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function primaryKeyOf(TableMap $map, array $row): array
    {
        $key = [];

        foreach ($map->primaryKey as $index) {
            $name = $map->columnName($index);

            if (array_key_exists($name, $row)) {
                $key[$name] = $row[$name];
            }
        }

        return $key;
    }

    /**
     * Whether this table is subscribed to. An empty filter means everything;
     * entries are `db.table` or `db.*`.
     *
     * @param TableMap $map
     *
     * @return bool
     */
    private function accepts(TableMap $map): bool
    {
        if ($this->tables === []) {
            return true;
        }

        return in_array($map->qualifiedName(), $this->tables, true)
            || in_array($map->database . '.*', $this->tables, true);
    }
}
