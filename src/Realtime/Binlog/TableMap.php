<?php

declare(strict_types=1);

/**
 * A decoded TABLE_MAP_EVENT: the schema every following row event is read
 * against.
 *
 * MySQL emits one of these before each batch of row events and never repeats
 * the column layout inside the row events themselves, so the stream is
 * stateful — {@see \PHPdot\Filesystem\Realtime\Binlog\EventReader} caches these
 * by table id and a row event without its map cannot be decoded at all.
 *
 * Column names, signedness and ENUM/SET labels only arrive when the server runs
 * `binlog_row_metadata = FULL`. Without it the names list is empty and rows
 * decode positionally.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Realtime\Binlog;

use PHPdot\Filesystem\Realtime\Protocol\PacketReader;

final readonly class TableMap
{
    private const OPTIONAL_SIGNEDNESS = 1;
    private const OPTIONAL_COLUMN_NAME = 4;
    private const OPTIONAL_SET_STR_VALUE = 5;
    private const OPTIONAL_ENUM_STR_VALUE = 6;
    private const OPTIONAL_SIMPLE_PRIMARY_KEY = 8;

    /**
     * __construct.
     *
     * @param int $tableId
     * @param string $database
     * @param string $table
     * @param list<ColumnType> $columnTypes
     * @param list<int> $columnMetadata
     * @param list<bool> $nullable
     * @param list<string> $columnNames
     * @param array<int,bool> $unsigned
     * @param array<int,list<string>> $enumValues
     * @param array<int,list<string>> $setValues
     * @param list<int> $primaryKey
     */
    public function __construct(
        public int $tableId,
        public string $database,
        public string $table,
        public array $columnTypes,
        public array $columnMetadata,
        public array $nullable,
        public array $columnNames = [],
        public array $unsigned = [],
        public array $enumValues = [],
        public array $setValues = [],
        public array $primaryKey = [],
    ) {}

    /**
     * Column count.
     *
     * @return int
     */
    public function columnCount(): int
    {
        return count($this->columnTypes);
    }

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
     * The name of a column, or its positional key when the server withheld
     * names (`binlog_row_metadata` left at MINIMAL).
     *
     * @param int $index
     *
     * @return string
     */
    public function columnName(int $index): string
    {
        return $this->columnNames[$index] ?? '@' . $index;
    }

    /**
     * Whether the column at this index is an unsigned numeric.
     *
     * @param int $index
     *
     * @return bool
     */
    public function isUnsigned(int $index): bool
    {
        return $this->unsigned[$index] ?? false;
    }

    /**
     * Decode a TABLE_MAP_EVENT body, cursor positioned at the post-header.
     *
     * @param PacketReader $reader
     *
     * @return self
     */
    public static function decode(PacketReader $reader): self
    {
        $tableId = $reader->uint48();
        $reader->skip(2); // flags — reserved, always zero in practice.

        $database = $reader->take($reader->uint8());
        $reader->skip(1); // NUL terminator, already length-prefixed.
        $table = $reader->take($reader->uint8());
        $reader->skip(1);

        $columnCount = $reader->lengthEncodedInt() ?? 0;

        /** @var list<ColumnType> $types */
        $types = [];

        for ($i = 0; $i < $columnCount; ++$i) {
            $types[] = ColumnType::fromByte($reader->uint8());
        }

        $metadata = self::decodeMetadata($reader, $types);
        $nullable = $reader->bitmap($columnCount);

        $map = new self($tableId, $database, $table, $types, $metadata, $nullable);

        return $reader->eof() ? $map : self::withOptionalMetadata($map, $reader);
    }

    /**
     * The metadata block is a packed run whose per-column width depends on the
     * column's own type.
     *
     * @param PacketReader $reader
     * @param list<ColumnType> $types
     *
     * @return list<int>
     */
    private static function decodeMetadata(PacketReader $reader, array $types): array
    {
        $block = new PacketReader($reader->take($reader->lengthEncodedInt() ?? 0));

        /** @var list<int> $metadata */
        $metadata = [];

        foreach ($types as $type) {
            $metadata[] = match ($type->metadataLength()) {
                1 => $block->uint8(),
                // STRING packs the real type in the high byte, so it is read
                // big-endian; every other 2-byte metadata is little-endian.
                2 => match ($type) {
                    ColumnType::STRING, ColumnType::ENUM, ColumnType::SET => $block->unsignedBigEndian(2),
                    default => $block->uint16(),
                },
                default => 0,
            };
        }

        return $metadata;
    }

    /**
     * Fold the optional metadata block (`binlog_row_metadata = FULL`) into the
     * map. Unknown field types are skipped by their own length, so a newer
     * server's additions are forward-compatible.
     *
     * @param self $map
     * @param PacketReader $reader
     *
     * @return self
     */
    private static function withOptionalMetadata(self $map, PacketReader $reader): self
    {
        $names = [];
        $unsigned = [];
        $enumValues = [];
        $setValues = [];
        $primaryKey = [];

        while (!$reader->eof()) {
            $fieldType = $reader->uint8();
            $field = new PacketReader($reader->take($reader->lengthEncodedInt() ?? 0));

            match ($fieldType) {
                self::OPTIONAL_COLUMN_NAME => $names = self::readStrings($field),
                self::OPTIONAL_SIGNEDNESS => $unsigned = self::readSignedness($field, $map->columnTypes),
                self::OPTIONAL_ENUM_STR_VALUE => $enumValues = self::readLabelSets($field, $map, ColumnType::ENUM),
                self::OPTIONAL_SET_STR_VALUE => $setValues = self::readLabelSets($field, $map, ColumnType::SET),
                self::OPTIONAL_SIMPLE_PRIMARY_KEY => $primaryKey = self::readIndexes($field),
                default => null,
            };
        }

        return new self(
            $map->tableId,
            $map->database,
            $map->table,
            $map->columnTypes,
            $map->columnMetadata,
            $map->nullable,
            $names,
            $unsigned,
            $enumValues,
            $setValues,
            $primaryKey,
        );
    }

    /**
     * Read strings.
     *
     * @param PacketReader $reader
     *
     * @return list<string>
     */
    private static function readStrings(PacketReader $reader): array
    {
        $values = [];

        while (!$reader->eof()) {
            $values[] = $reader->lengthEncodedString() ?? '';
        }

        return $values;
    }

    /**
     * Read indexes.
     *
     * @param PacketReader $reader
     *
     * @return list<int>
     */
    private static function readIndexes(PacketReader $reader): array
    {
        $values = [];

        while (!$reader->eof()) {
            $values[] = $reader->lengthEncodedInt() ?? 0;
        }

        return $values;
    }

    /**
     * The signedness bitmap covers only numeric columns, in order, and — unlike
     * every other MySQL bitmap — is read most-significant bit first.
     *
     * @param PacketReader $reader
     * @param list<ColumnType> $types
     *
     * @return array<int,bool>
     */
    private static function readSignedness(PacketReader $reader, array $types): array
    {
        $bits = [];

        while (!$reader->eof()) {
            $byte = $reader->uint8();

            for ($i = 7; $i >= 0; --$i) {
                $bits[] = ($byte & (1 << $i)) !== 0;
            }
        }

        $unsigned = [];
        $cursor = 0;

        foreach ($types as $index => $type) {
            if (!self::isNumeric($type)) {
                continue;
            }

            $unsigned[$index] = $bits[$cursor] ?? false;
            ++$cursor;
        }

        return $unsigned;
    }

    /**
     * ENUM/SET label sets, emitted once per matching column in column order.
     *
     * Both types reach the row image disguised as STRING with their real type
     * folded into the high byte of their metadata, so the match is on the
     * effective type rather than the declared one.
     *
     * @param PacketReader $reader
     * @param self $map
     * @param ColumnType $want
     *
     * @return array<int,list<string>>
     */
    private static function readLabelSets(PacketReader $reader, self $map, ColumnType $want): array
    {
        /** @var list<int> $columns */
        $columns = [];

        foreach ($map->columnTypes as $index => $type) {
            if (self::effectiveType($type, $map->columnMetadata[$index] ?? 0) === $want) {
                $columns[] = $index;
            }
        }

        $sets = [];
        $cursor = 0;

        while (!$reader->eof()) {
            $count = $reader->lengthEncodedInt() ?? 0;
            $labels = [];

            for ($i = 0; $i < $count; ++$i) {
                $labels[] = $reader->lengthEncodedString() ?? '';
            }

            if (isset($columns[$cursor])) {
                $sets[$columns[$cursor]] = $labels;
            }

            ++$cursor;
        }

        return $sets;
    }

    /**
     * The type a column really is, unwrapping the STRING disguise MySQL puts on
     * ENUM and SET columns in the row image.
     *
     * @param ColumnType $type
     * @param int $metadata
     *
     * @return ColumnType
     */
    public static function effectiveType(ColumnType $type, int $metadata): ColumnType
    {
        if ($type !== ColumnType::STRING) {
            return $type;
        }

        return ColumnType::tryFrom($metadata >> 8) ?? $type;
    }

    /**
     * Is numeric.
     *
     * @param ColumnType $type
     *
     * @return bool
     */
    private static function isNumeric(ColumnType $type): bool
    {
        return match ($type) {
            ColumnType::TINY, ColumnType::SHORT, ColumnType::INT24, ColumnType::LONG,
            ColumnType::LONGLONG, ColumnType::FLOAT, ColumnType::DOUBLE,
            ColumnType::DECIMAL, ColumnType::NEWDECIMAL, ColumnType::YEAR => true,
            default => false,
        };
    }
}
