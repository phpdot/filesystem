<?php

declare(strict_types=1);

/**
 * MySQL column type codes, plus the per-type metadata width the TABLE_MAP event
 * uses to describe them.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Realtime\Binlog;

use PHPdot\Filesystem\Exception\UnsupportedBinlogFeature;

enum ColumnType: int
{
    case DECIMAL = 0x00;
    case TINY = 0x01;
    case SHORT = 0x02;
    case LONG = 0x03;
    case FLOAT = 0x04;
    case DOUBLE = 0x05;
    case NULL = 0x06;
    case TIMESTAMP = 0x07;
    case LONGLONG = 0x08;
    case INT24 = 0x09;
    case DATE = 0x0A;
    case TIME = 0x0B;
    case DATETIME = 0x0C;
    case YEAR = 0x0D;
    case NEWDATE = 0x0E;
    case VARCHAR = 0x0F;
    case BIT = 0x10;
    case TIMESTAMP2 = 0x11;
    case DATETIME2 = 0x12;
    case TIME2 = 0x13;
    case JSON = 0xF5;
    case NEWDECIMAL = 0xF6;
    case ENUM = 0xF7;
    case SET = 0xF8;
    case TINY_BLOB = 0xF9;
    case MEDIUM_BLOB = 0xFA;
    case LONG_BLOB = 0xFB;
    case BLOB = 0xFC;
    case VAR_STRING = 0xFD;
    case STRING = 0xFE;
    case GEOMETRY = 0xFF;

    /**
     * How many metadata bytes the TABLE_MAP event spends describing this type.
     *
     * @return int
     */
    public function metadataLength(): int
    {
        return match ($this) {
            self::FLOAT, self::DOUBLE, self::BLOB, self::TINY_BLOB, self::MEDIUM_BLOB,
            self::LONG_BLOB, self::GEOMETRY, self::JSON, self::TIME2, self::DATETIME2,
            self::TIMESTAMP2 => 1,
            self::VARCHAR, self::BIT, self::NEWDECIMAL, self::DECIMAL, self::STRING,
            self::VAR_STRING, self::ENUM, self::SET => 2,
            default => 0,
        };
    }

    /**
     * Resolve a raw type byte, naming the type in the failure.
     *
     * @param int $type
     *
     * @return self
     */
    public static function fromByte(int $type): self
    {
        return self::tryFrom($type) ?? throw UnsupportedBinlogFeature::columnType($type);
    }
}
