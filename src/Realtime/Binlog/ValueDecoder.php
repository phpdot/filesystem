<?php

declare(strict_types=1);

/**
 * Decodes one column value out of a row image.
 *
 * The row image is a packed binary blob whose layout is driven entirely by the
 * TABLE_MAP's per-column type and metadata — there are no field delimiters, so
 * a single mis-sized read corrupts every column after it. Each branch below is
 * the exact width MySQL wrote.
 *
 * Value shapes are chosen to survive `json_encode` without loss:
 * temporal types render as MySQL's canonical text form, DECIMAL as an exact
 * decimal string, and BIGINT UNSIGNED above PHP_INT_MAX as a string rather than
 * a silently wrapped negative int.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Realtime\Binlog;

use PHPdot\Filesystem\Exception\UnsupportedBinlogFeature;
use PHPdot\Filesystem\Realtime\Protocol\PacketReader;

final class ValueDecoder
{
    /**
     * Bytes used by a partially filled 9-digit DECIMAL group, indexed by digit
     * count.
     */
    private const DECIMAL_COMPRESSED_BYTES = [0, 1, 1, 2, 2, 3, 3, 4, 4, 4];

    private const DECIMAL_DIGITS_PER_GROUP = 9;

    /**
     * Decode.
     *
     * @param PacketReader $reader
     * @param ColumnType $type
     * @param int $metadata
     * @param bool $unsigned
     * @param list<string> $labels
     *
     * @return mixed
     */
    public static function decode(PacketReader $reader, ColumnType $type, int $metadata, bool $unsigned, array $labels = []): mixed
    {
        return match ($type) {
            ColumnType::NULL => null,
            ColumnType::TINY => $unsigned ? $reader->uint8() : $reader->int8(),
            ColumnType::SHORT => $unsigned ? $reader->uint16() : $reader->int16(),
            ColumnType::INT24 => $unsigned ? $reader->uint24() : $reader->int24(),
            ColumnType::LONG => $unsigned ? $reader->uint32() : $reader->int32(),
            ColumnType::LONGLONG => self::decodeLongLong($reader, $unsigned),
            ColumnType::FLOAT => $reader->float32(),
            ColumnType::DOUBLE => $reader->float64(),
            ColumnType::YEAR => self::decodeYear($reader),
            ColumnType::DATE => self::decodeDate($reader),
            ColumnType::TIME => self::decodeLegacyTime($reader),
            ColumnType::TIME2 => self::decodeTime2($reader, $metadata),
            ColumnType::DATETIME => self::decodeLegacyDateTime($reader),
            ColumnType::DATETIME2 => self::decodeDateTime2($reader, $metadata),
            ColumnType::TIMESTAMP => self::formatTimestamp($reader->uint32(), 0, 0),
            ColumnType::TIMESTAMP2 => self::decodeTimestamp2($reader, $metadata),
            ColumnType::DECIMAL, ColumnType::NEWDECIMAL => self::decodeNewDecimal($reader, $metadata & 0xFF, $metadata >> 8),
            ColumnType::BIT => self::decodeBit($reader, $metadata),
            ColumnType::ENUM => self::labelFor($reader->unsignedLittleEndian($metadata), $labels),
            ColumnType::SET => self::decodeSet($reader, $metadata, $labels),
            ColumnType::VARCHAR, ColumnType::VAR_STRING => self::decodeVariableString($reader, $metadata),
            ColumnType::STRING => self::decodeString($reader, $metadata, $labels),
            ColumnType::TINY_BLOB, ColumnType::MEDIUM_BLOB, ColumnType::LONG_BLOB,
            ColumnType::BLOB, ColumnType::GEOMETRY => self::decodeBlob($reader, $metadata),
            ColumnType::JSON => JsonBinaryDecoder::decode(self::decodeBlob($reader, $metadata)),
            ColumnType::NEWDATE => throw UnsupportedBinlogFeature::columnType($type->value),
        };
    }

    /**
     * BIGINT. Unsigned values above PHP_INT_MAX come back as decimal strings so
     * nothing wraps to a negative.
     *
     * @param PacketReader $reader
     * @param bool $unsigned
     *
     * @return int|string
     */
    private static function decodeLongLong(PacketReader $reader, bool $unsigned): int|string
    {
        $value = $reader->int64();

        return $unsigned && $value < 0 ? sprintf('%u', $value) : $value;
    }

    /**
     * Year.
     *
     * @param PacketReader $reader
     *
     * @return int
     */
    private static function decodeYear(PacketReader $reader): int
    {
        $value = $reader->uint8();

        return $value === 0 ? 0 : $value + 1900;
    }

    /**
     * DATE packs year/month/day into 24 bits.
     *
     * @param PacketReader $reader
     *
     * @return string
     */
    private static function decodeDate(PacketReader $reader): string
    {
        $value = $reader->uint24();

        return sprintf('%04d-%02d-%02d', $value >> 9, ($value >> 5) & 0x0F, $value & 0x1F);
    }

    /**
     * Pre-5.6 TIME: a 24-bit integer holding the decimal digits HHMMSS.
     *
     * @param PacketReader $reader
     *
     * @return string
     */
    private static function decodeLegacyTime(PacketReader $reader): string
    {
        $value = $reader->uint24();

        return sprintf('%02d:%02d:%02d', intdiv($value, 10000), intdiv($value, 100) % 100, $value % 100);
    }

    /**
     * Pre-5.6 DATETIME: a 64-bit integer holding the decimal digits YYYYMMDDHHMMSS.
     *
     * @param PacketReader $reader
     *
     * @return string
     */
    private static function decodeLegacyDateTime(PacketReader $reader): string
    {
        $value = $reader->int64();
        $date = intdiv($value, 1000000);
        $time = $value % 1000000;

        return sprintf(
            '%04d-%02d-%02d %02d:%02d:%02d',
            intdiv($date, 10000),
            intdiv($date, 100) % 100,
            $date % 100,
            intdiv($time, 10000),
            intdiv($time, 100) % 100,
            $time % 100,
        );
    }

    /**
     * DATETIME2: 5 big-endian bytes — 1 sign bit, 17 bits of year*13+month,
     * then 5/5/6/6 for day/hour/minute/second — followed by the fractional tail.
     *
     * @param PacketReader $reader
     * @param int $fsp
     *
     * @return string
     */
    private static function decodeDateTime2(PacketReader $reader, int $fsp): string
    {
        $value = $reader->unsignedBigEndian(5);
        $yearMonth = ($value >> 22) & 0x1FFFF;

        $formatted = sprintf(
            '%04d-%02d-%02d %02d:%02d:%02d',
            intdiv($yearMonth, 13),
            $yearMonth % 13,
            ($value >> 17) & 0x1F,
            ($value >> 12) & 0x1F,
            ($value >> 6) & 0x3F,
            $value & 0x3F,
        );

        return $formatted . self::fractionalSuffix($reader, $fsp);
    }

    /**
     * TIME2: 3 big-endian bytes — 1 sign bit, 1 reserved, then 10/6/6 for
     * hour/minute/second.
     *
     * @param PacketReader $reader
     * @param int $fsp
     *
     * @return string
     */
    private static function decodeTime2(PacketReader $reader, int $fsp): string
    {
        $value = $reader->unsignedBigEndian(3);
        $negative = ($value & 0x800000) === 0;

        // Negative TIMEs are stored as the complement of the magnitude.
        $magnitude = $negative ? 0x1000000 - $value : $value;

        $formatted = sprintf(
            '%s%02d:%02d:%02d',
            $negative ? '-' : '',
            ($magnitude >> 12) & 0x3FF,
            ($magnitude >> 6) & 0x3F,
            $magnitude & 0x3F,
        );

        return $formatted . self::fractionalSuffix($reader, $fsp);
    }

    /**
     * TIMESTAMP2: 4 big-endian bytes of UTC epoch seconds, plus fraction.
     *
     * @param PacketReader $reader
     * @param int $fsp
     *
     * @return string
     */
    private static function decodeTimestamp2(PacketReader $reader, int $fsp): string
    {
        $seconds = $reader->unsignedBigEndian(4);

        return self::formatTimestamp($seconds, self::readFraction($reader, $fsp), $fsp);
    }

    /**
     * TIMESTAMP values are genuinely UTC on the wire, so they render as an
     * unambiguous UTC instant rather than being reinterpreted in a session zone.
     *
     * @param int $seconds
     * @param int $microseconds
     * @param int $fsp
     *
     * @return string
     */
    private static function formatTimestamp(int $seconds, int $microseconds, int $fsp): string
    {
        $formatted = gmdate('Y-m-d H:i:s', $seconds);

        return $fsp > 0
            ? $formatted . '.' . substr(sprintf('%06d', $microseconds), 0, $fsp)
            : $formatted;
    }

    /**
     * Fractional suffix.
     *
     * @param PacketReader $reader
     * @param int $fsp
     *
     * @return string
     */
    private static function fractionalSuffix(PacketReader $reader, int $fsp): string
    {
        if ($fsp === 0) {
            return '';
        }

        return '.' . substr(sprintf('%06d', self::readFraction($reader, $fsp)), 0, $fsp);
    }

    /**
     * The fractional-seconds tail: 0-3 big-endian bytes scaled to microseconds.
     *
     * @param PacketReader $reader
     * @param int $fsp
     *
     * @return int
     */
    private static function readFraction(PacketReader $reader, int $fsp): int
    {
        return match (intdiv($fsp + 1, 2)) {
            1 => $reader->unsignedBigEndian(1) * 10000,
            2 => $reader->unsignedBigEndian(2) * 100,
            3 => $reader->unsignedBigEndian(3),
            default => 0,
        };
    }

    /**
     * BIT. Metadata holds whole bytes in its high byte and leftover bits in the
     * low byte.
     *
     * @param PacketReader $reader
     * @param int $metadata
     *
     * @return int
     */
    private static function decodeBit(PacketReader $reader, int $metadata): int
    {
        $bits = (($metadata >> 8) * 8) + ($metadata & 0xFF);

        return $reader->unsignedBigEndian(intdiv($bits + 7, 8));
    }

    /**
     * SET is a bitfield over the label list.
     *
     * @param PacketReader $reader
     * @param int $width
     * @param list<string> $labels
     *
     * @return list<string>
     */
    private static function decodeSet(PacketReader $reader, int $width, array $labels): array
    {
        $bits = $reader->unsignedLittleEndian($width);
        $members = [];

        foreach ($labels as $index => $label) {
            if (($bits & (1 << $index)) !== 0) {
                $members[] = $label;
            }
        }

        return $members;
    }

    /**
     * ENUM indexes are 1-based; 0 is MySQL's "invalid value" slot.
     *
     * @param int $index
     * @param list<string> $labels
     *
     * @return int|string
     */
    private static function labelFor(int $index, array $labels): int|string
    {
        if ($index === 0) {
            return '';
        }

        return $labels[$index - 1] ?? $index;
    }

    /**
     * VARCHAR/VARBINARY: a 1- or 2-byte length prefix, chosen by the declared
     * maximum rather than by the actual value.
     *
     * @param PacketReader $reader
     * @param int $maxLength
     *
     * @return string
     */
    private static function decodeVariableString(PacketReader $reader, int $maxLength): string
    {
        return $reader->take($maxLength > 255 ? $reader->uint16() : $reader->uint8());
    }

    /**
     * CHAR/BINARY, and the ENUM/SET columns that reach the row image disguised
     * as STRING with their real type folded into the metadata's high byte.
     *
     * @param PacketReader $reader
     * @param int $metadata
     * @param list<string> $labels
     *
     * @return mixed
     */
    private static function decodeString(PacketReader $reader, int $metadata, array $labels): mixed
    {
        $realType = $metadata >> 8;
        $width = $metadata & 0xFF;

        if ($realType === ColumnType::ENUM->value) {
            return self::labelFor($reader->unsignedLittleEndian($width), $labels);
        }

        if ($realType === ColumnType::SET->value) {
            return self::decodeSet($reader, $width, $labels);
        }

        // The 4.1 length hack: two spare bits of the type byte extend the
        // declared length past 255.
        $maxLength = ((($metadata >> 4) & 0x300) ^ 0x300) + $width;

        return self::decodeVariableString($reader, $maxLength);
    }

    /**
     * BLOB/TEXT/JSON/GEOMETRY: metadata is the width of the length prefix.
     *
     * @param PacketReader $reader
     * @param int $lengthBytes
     *
     * @return string
     */
    private static function decodeBlob(PacketReader $reader, int $lengthBytes): string
    {
        return $reader->take($reader->unsignedLittleEndian($lengthBytes));
    }

    /**
     * DECIMAL, stored as big-endian groups of 9 digits packed into 4 bytes,
     * with the sign carried in the top bit of the first byte.
     *
     * Returned as a string: the whole point of DECIMAL is that it is not a float.
     *
     * @param PacketReader $reader
     * @param int $precision
     * @param int $scale
     *
     * @return string
     */
    private static function decodeNewDecimal(PacketReader $reader, int $precision, int $scale): string
    {
        $integralDigits = $precision - $scale;
        $wholeIntegralGroups = intdiv($integralDigits, self::DECIMAL_DIGITS_PER_GROUP);
        $leadingDigits = $integralDigits % self::DECIMAL_DIGITS_PER_GROUP;
        $wholeFractionalGroups = intdiv($scale, self::DECIMAL_DIGITS_PER_GROUP);
        $trailingDigits = $scale % self::DECIMAL_DIGITS_PER_GROUP;

        $size = ($wholeIntegralGroups * 4)
            + self::DECIMAL_COMPRESSED_BYTES[$leadingDigits]
            + ($wholeFractionalGroups * 4)
            + self::DECIMAL_COMPRESSED_BYTES[$trailingDigits];

        $raw = $reader->take($size);

        // Top bit set means non-negative. For negatives every byte is stored
        // inverted, so XOR-ing with 0xFF restores the magnitude.
        $negative = (ord($raw[0]) & 0x80) === 0;
        $raw[0] = chr(ord($raw[0]) ^ 0x80);

        if ($negative) {
            $raw = ~$raw;
        }

        $groups = new PacketReader($raw);

        $integral = self::decimalGroup($groups, self::DECIMAL_COMPRESSED_BYTES[$leadingDigits], $leadingDigits, true);

        for ($i = 0; $i < $wholeIntegralGroups; ++$i) {
            $integral .= self::decimalGroup($groups, 4, self::DECIMAL_DIGITS_PER_GROUP, $integral === '');
        }

        $fractional = '';

        for ($i = 0; $i < $wholeFractionalGroups; ++$i) {
            $fractional .= self::decimalGroup($groups, 4, self::DECIMAL_DIGITS_PER_GROUP, false);
        }

        $fractional .= self::decimalGroup($groups, self::DECIMAL_COMPRESSED_BYTES[$trailingDigits], $trailingDigits, false);

        $value = ($integral === '' ? '0' : $integral) . ($fractional === '' ? '' : '.' . $fractional);

        return ($negative ? '-' : '') . $value;
    }

    /**
     * One DECIMAL group. Leading groups drop their zero padding; interior groups
     * keep it, since the padding is significant to the number.
     *
     * @param PacketReader $reader
     * @param int $width
     * @param int $digits
     * @param bool $leading
     *
     * @return string
     */
    private static function decimalGroup(PacketReader $reader, int $width, int $digits, bool $leading): string
    {
        if ($width === 0) {
            return '';
        }

        $value = $reader->unsignedBigEndian($width);

        return $leading ? ($value === 0 ? '' : (string) $value) : sprintf('%0' . $digits . 'd', $value);
    }
}
