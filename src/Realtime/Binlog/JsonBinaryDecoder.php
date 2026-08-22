<?php

declare(strict_types=1);

/**
 * Decodes MySQL's binary JSON representation into PHP values.
 *
 * A JSON column does not reach the binlog as text. MySQL stores it as a
 * random-access tree: each object or array carries a table of entries pointing
 * at offsets elsewhere in its own blob, and small scalars are inlined into the
 * entry slot instead of being stored out of line. Decoding therefore has to
 * jump around the buffer rather than read it front to back.
 *
 * Objects decode to associative arrays and arrays to lists, so the result feeds
 * straight into `json_encode`.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Realtime\Binlog;

use PHPdot\Filesystem\Exception\MalformedBinlogPacket;

final class JsonBinaryDecoder
{
    private const TYPE_SMALL_OBJECT = 0x00;
    private const TYPE_LARGE_OBJECT = 0x01;
    private const TYPE_SMALL_ARRAY = 0x02;
    private const TYPE_LARGE_ARRAY = 0x03;
    private const TYPE_LITERAL = 0x04;
    private const TYPE_INT16 = 0x05;
    private const TYPE_UINT16 = 0x06;
    private const TYPE_INT32 = 0x07;
    private const TYPE_UINT32 = 0x08;
    private const TYPE_INT64 = 0x09;
    private const TYPE_UINT64 = 0x0A;
    private const TYPE_DOUBLE = 0x0B;
    private const TYPE_STRING = 0x0C;
    private const TYPE_OPAQUE = 0x0F;

    private const LITERAL_NULL = 0x00;
    private const LITERAL_TRUE = 0x01;
    private const LITERAL_FALSE = 0x02;

    private readonly int $length;

    /**
     * __construct.
     *
     * @param string $buffer
     */
    private function __construct(private readonly string $buffer)
    {
        $this->length = strlen($buffer);
    }

    /**
     * Decode a JSON column's binary payload. An empty payload is SQL NULL.
     *
     * @param string $binary
     *
     * @return mixed
     */
    public static function decode(string $binary): mixed
    {
        if ($binary === '') {
            return null;
        }

        $decoder = new self($binary);

        return $decoder->value($decoder->byteAt(0), 1);
    }

    /**
     * Read one value of the given type starting at an absolute offset.
     *
     * @param int $type
     * @param int $offset
     *
     * @return mixed
     */
    private function value(int $type, int $offset): mixed
    {
        return match ($type) {
            self::TYPE_SMALL_OBJECT => $this->container($offset, 2, true),
            self::TYPE_LARGE_OBJECT => $this->container($offset, 4, true),
            self::TYPE_SMALL_ARRAY => $this->container($offset, 2, false),
            self::TYPE_LARGE_ARRAY => $this->container($offset, 4, false),
            self::TYPE_LITERAL => $this->literal($this->byteAt($offset)),
            self::TYPE_INT16 => $this->signed($this->unsigned($offset, 2), 2),
            self::TYPE_UINT16 => $this->unsigned($offset, 2),
            self::TYPE_INT32 => $this->signed($this->unsigned($offset, 4), 4),
            self::TYPE_UINT32 => $this->unsigned($offset, 4),
            self::TYPE_INT64 => $this->unsigned($offset, 8),
            self::TYPE_UINT64 => $this->unsignedBig($offset),
            self::TYPE_DOUBLE => $this->double($offset),
            self::TYPE_STRING => $this->string($offset),
            self::TYPE_OPAQUE => $this->opaque($offset),
            default => throw MalformedBinlogPacket::unexpected('JSON value type', "0x{$this->hex($type)}"),
        };
    }

    /**
     * An object or array. Both share a header of (count, size) followed by an
     * entry table; objects prepend a parallel table of key pointers.
     *
     * Offsets inside are relative to the container's own start, not the buffer's.
     *
     * @param int $start
     * @param int $slot
     * @param bool $isObject
     *
     * @return array<array-key,mixed>
     */
    private function container(int $start, int $slot, bool $isObject): array
    {
        $count = $this->unsigned($start, $slot);
        $keyEntries = $start + (2 * $slot);
        $valueEntries = $isObject ? $keyEntries + ($count * ($slot + 2)) : $keyEntries;

        $result = [];

        for ($i = 0; $i < $count; ++$i) {
            $entry = $valueEntries + ($i * ($slot + 1));
            $type = $this->byteAt($entry);

            $value = $this->isInlined($type, $slot)
                ? $this->value($type, $entry + 1)
                : $this->value($type, $start + $this->unsigned($entry + 1, $slot));

            if (!$isObject) {
                $result[] = $value;

                continue;
            }

            $keyEntry = $keyEntries + ($i * ($slot + 2));
            $key = substr(
                $this->buffer,
                $start + $this->unsigned($keyEntry, $slot),
                $this->unsigned($keyEntry + $slot, 2),
            );

            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * Whether a value of this type is stored inside its entry slot rather than
     * out of line. The slot is only wide enough for 4-byte scalars in large
     * containers.
     *
     * @param int $type
     * @param int $slot
     *
     * @return bool
     */
    private function isInlined(int $type, int $slot): bool
    {
        return match ($type) {
            self::TYPE_LITERAL, self::TYPE_INT16, self::TYPE_UINT16 => true,
            self::TYPE_INT32, self::TYPE_UINT32 => $slot === 4,
            default => false,
        };
    }

    /**
     * Literal.
     *
     * @param int $code
     *
     * @return ?bool
     */
    private function literal(int $code): null|bool
    {
        return match ($code) {
            self::LITERAL_NULL => null,
            self::LITERAL_TRUE => true,
            self::LITERAL_FALSE => false,
            default => throw MalformedBinlogPacket::unexpected('JSON literal', "0x{$this->hex($code)}"),
        };
    }

    /**
     * A UTF-8 string behind a varint length prefix.
     *
     * @param int $offset
     *
     * @return string
     */
    private function string(int $offset): string
    {
        $length = 0;
        $shift = 0;
        $cursor = $offset;

        do {
            $byte = $this->byteAt($cursor++);
            $length |= ($byte & 0x7F) << $shift;
            $shift += 7;
        } while (($byte & 0x80) !== 0);

        return substr($this->buffer, $cursor, $length);
    }

    /**
     * Opaque values carry a MySQL column type this format has no native slot
     * for (DECIMAL, temporal, geometry). They are handed back as raw bytes
     * rather than guessed at.
     *
     * @param int $offset
     *
     * @return string
     */
    private function opaque(int $offset): string
    {
        return $this->string($offset + 1);
    }

    /**
     * Double.
     *
     * @param int $offset
     *
     * @return float
     */
    private function double(int $offset): float
    {
        $this->guard($offset, 8);

        /** @var array{1: float}|false $parsed */
        $parsed = unpack('e', substr($this->buffer, $offset, 8));

        if ($parsed === false) {
            throw MalformedBinlogPacket::unexpected('JSON double', 'unpackable 8-byte run');
        }

        return $parsed[1];
    }

    /**
     * Unsigned 64-bit, widened to a string when it exceeds PHP_INT_MAX.
     *
     * @param int $offset
     *
     * @return int|string
     */
    private function unsignedBig(int $offset): int|string
    {
        $value = $this->unsigned($offset, 8);

        return $value < 0 ? sprintf('%u', $value) : $value;
    }

    /**
     * Unsigned.
     *
     * @param int $offset
     * @param int $width
     *
     * @return int
     */
    private function unsigned(int $offset, int $width): int
    {
        $this->guard($offset, $width);

        $value = 0;

        for ($i = $width - 1; $i >= 0; --$i) {
            $value = ($value << 8) | ord($this->buffer[$offset + $i]);
        }

        return $value;
    }

    /**
     * Signed.
     *
     * @param int $value
     * @param int $width
     *
     * @return int
     */
    private function signed(int $value, int $width): int
    {
        $signBit = 1 << (($width * 8) - 1);

        return ($value & $signBit) !== 0 ? $value - ($signBit << 1) : $value;
    }

    /**
     * Byte at.
     *
     * @param int $offset
     *
     * @return int
     */
    private function byteAt(int $offset): int
    {
        $this->guard($offset, 1);

        return ord($this->buffer[$offset]);
    }

    /**
     * Guard.
     *
     * @param int $offset
     * @param int $width
     *
     * @return void
     */
    private function guard(int $offset, int $width): void
    {
        if ($offset < 0 || $offset + $width > $this->length) {
            throw MalformedBinlogPacket::truncated($width, max(0, $this->length - $offset), $offset);
        }
    }

    /**
     * Hex.
     *
     * @param int $value
     *
     * @return string
     */
    private function hex(int $value): string
    {
        return str_pad(strtoupper(dechex($value)), 2, '0', STR_PAD_LEFT);
    }
}
