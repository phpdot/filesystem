<?php

declare(strict_types=1);

namespace PHPdot\Filesystem\Tests\Unit\Realtime;

use PHPdot\Filesystem\Realtime\Binlog\ColumnType;
use PHPdot\Filesystem\Realtime\Binlog\EventType;

/**
 * Builds byte-accurate binlog events so the decoder can be tested against a
 * synthetic stream rather than a live server.
 *
 * Mirrors MySQL's layouts by hand: if this builder and the decoder ever drift
 * apart, the tests fail, which is the point.
 */
final class BinlogEventBuilder
{
    /**
     * Wrap a body in the fixed 19-byte event header.
     */
    public static function event(EventType $type, string $body, int $logPosition = 0, int $timestamp = 1_766_000_000): string
    {
        $size = 19 + strlen($body);

        return pack('V', $timestamp)
            . chr($type->value)
            . pack('V', 1)
            . pack('V', $size)
            . pack('V', $logPosition)
            . pack('v', 0)
            . $body;
    }

    /**
     * A ROTATE event, naming the file the stream continues in.
     */
    public static function rotate(string $file, int $position = 4): string
    {
        return self::event(EventType::ROTATE, self::uint64($position) . $file);
    }

    /**
     * A TABLE_MAP describing one table's columns.
     *
     * @param list<ColumnType> $types
     * @param list<int> $metadata
     * @param list<bool> $nullable
     * @param list<string> $names
     * @param list<int> $primaryKey
     * @param array<int,list<string>> $enumValues
     */
    public static function tableMap(
        int $tableId,
        string $database,
        string $table,
        array $types,
        array $metadata = [],
        array $nullable = [],
        array $names = [],
        array $primaryKey = [],
        array $enumValues = [],
    ): string {
        $count = count($types);
        $metadata = $metadata === [] ? array_fill(0, $count, 0) : $metadata;
        $nullable = $nullable === [] ? array_fill(0, $count, false) : $nullable;

        $typeBytes = '';
        $metaBytes = '';

        foreach ($types as $index => $type) {
            $typeBytes .= chr($type->value);
            $metaBytes .= match ($type->metadataLength()) {
                1 => chr($metadata[$index] & 0xFF),
                2 => match ($type) {
                    // STRING-family metadata is big-endian; everything else is not.
                    ColumnType::STRING, ColumnType::ENUM, ColumnType::SET => chr(($metadata[$index] >> 8) & 0xFF) . chr($metadata[$index] & 0xFF),
                    default => pack('v', $metadata[$index]),
                },
                default => '',
            };
        }

        $body = self::uint48($tableId)
            . pack('v', 0)
            . chr(strlen($database)) . $database . "\0"
            . chr(strlen($table)) . $table . "\0"
            . self::lengthEncoded($count)
            . $typeBytes
            . self::lengthEncoded(strlen($metaBytes)) . $metaBytes
            . self::bitmap($nullable);

        $body .= self::optionalMetadata($types, $names, $primaryKey, $enumValues);

        return self::event(EventType::TABLE_MAP, $body);
    }

    /**
     * A row event carrying one or more images per row.
     *
     * @param list<bool> $present
     * @param list<string> $rows already-encoded row images
     */
    public static function rows(EventType $type, int $tableId, int $columnCount, array $present, array $rows, int $logPosition = 0): string
    {
        $body = self::uint48($tableId) . pack('v', 0);

        if ($type->hasExtraRowData()) {
            $body .= pack('v', 2); // extra-data length, inclusive of itself
        }

        $body .= self::lengthEncoded($columnCount) . self::bitmap($present);

        if ($type->hasSecondBitmap()) {
            $body .= self::bitmap($present);
        }

        return self::event($type, $body . implode('', $rows), $logPosition);
    }

    /**
     * One row image: a null bitmap over the present columns, then the values.
     *
     * @param list<bool> $nulls
     */
    public static function rowImage(array $nulls, string $values): string
    {
        return self::bitmap($nulls) . $values;
    }

    /**
     * A GTID event naming the transaction about to be logged.
     */
    public static function gtid(string $uuid, int $number): string
    {
        $packed = hex2bin(str_replace('-', '', $uuid));

        return self::event(EventType::GTID, "\0" . ($packed === false ? str_repeat("\0", 16) : $packed) . self::uint64($number));
    }

    /**
     * An XID event — a committed transaction.
     */
    public static function commit(int $xid = 1, int $logPosition = 0): string
    {
        return self::event(EventType::XID, self::uint64($xid), $logPosition);
    }

    /**
     * A QUERY event carrying a statement.
     */
    public static function query(string $schema, string $statement): string
    {
        $body = pack('V', 7) . pack('V', 0)
            . chr(strlen($schema))
            . pack('v', 0)
            . pack('v', 0)
            . $schema . "\0"
            . $statement;

        return self::event(EventType::QUERY, $body);
    }

    /**
     * A FORMAT_DESCRIPTION, which opens every binlog file.
     */
    public static function formatDescription(): string
    {
        return self::event(EventType::FORMAT_DESCRIPTION, pack('v', 4) . str_pad('8.4.0', 50, "\0") . pack('V', 0) . chr(19));
    }

    /**
     * Little-endian unsigned of an arbitrary width.
     */
    public static function uint(int $value, int $width): string
    {
        $bytes = '';

        for ($i = 0; $i < $width; ++$i) {
            $bytes .= chr(($value >> ($i * 8)) & 0xFF);
        }

        return $bytes;
    }

    /**
     * Uint48.
     */
    public static function uint48(int $value): string
    {
        return self::uint($value, 6);
    }

    /**
     * Uint64.
     */
    public static function uint64(int $value): string
    {
        return self::uint($value, 8);
    }

    /**
     * A MySQL length-encoded integer.
     */
    public static function lengthEncoded(int $value): string
    {
        return match (true) {
            $value < 0xFB => chr($value),
            $value < 0x10000 => chr(0xFC) . pack('v', $value),
            $value < 0x1000000 => chr(0xFD) . substr(pack('V', $value), 0, 3),
            default => chr(0xFE) . self::uint64($value),
        };
    }

    /**
     * A length-encoded string.
     */
    public static function lengthEncodedString(string $value): string
    {
        return self::lengthEncoded(strlen($value)) . $value;
    }

    /**
     * A MySQL bitmap, least-significant bit first.
     *
     * @param list<bool> $flags
     */
    public static function bitmap(array $flags): string
    {
        $bytes = array_fill(0, max(1, intdiv(count($flags) + 7, 8)), 0);

        foreach ($flags as $index => $flag) {
            if ($flag) {
                $bytes[$index >> 3] |= 1 << ($index & 7);
            }
        }

        return implode('', array_map(chr(...), $bytes));
    }

    /**
     * The optional metadata block a server running `binlog_row_metadata = FULL`
     * appends to every TABLE_MAP.
     *
     * @param list<ColumnType> $types
     * @param list<string> $names
     * @param list<int> $primaryKey
     * @param array<int,list<string>> $enumValues
     */
    private static function optionalMetadata(array $types, array $names, array $primaryKey, array $enumValues): string
    {
        $block = '';

        if ($names !== []) {
            $payload = implode('', array_map(self::lengthEncodedString(...), $names));
            $block .= chr(4) . self::lengthEncoded(strlen($payload)) . $payload;
        }

        if ($enumValues !== []) {
            $payload = '';

            foreach ($enumValues as $labels) {
                $payload .= self::lengthEncoded(count($labels)) . implode('', array_map(self::lengthEncodedString(...), $labels));
            }

            $block .= chr(6) . self::lengthEncoded(strlen($payload)) . $payload;
        }

        if ($primaryKey !== []) {
            $payload = implode('', array_map(self::lengthEncoded(...), $primaryKey));
            $block .= chr(8) . self::lengthEncoded(strlen($payload)) . $payload;
        }

        return $block;
    }
}
