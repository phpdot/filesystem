<?php

declare(strict_types=1);

/**
 * A cursor over a MySQL wire payload.
 *
 * MySQL is little-endian everywhere, so the integer readers are hand-rolled
 * byte arithmetic rather than {@see unpack} — it keeps the types concrete for
 * static analysis and avoids a `false` branch on every field. Every read is
 * bounds-checked: a short buffer raises {@see MalformedBinlogPacket} instead of
 * silently decoding whatever follows.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Realtime\Protocol;

use PHPdot\Filesystem\Exception\MalformedBinlogPacket;

final class PacketReader
{
    private int $offset;

    private readonly int $length;

    /**
     * __construct.
     *
     * @param string $buffer
     * @param int $offset
     */
    public function __construct(private readonly string $buffer, int $offset = 0)
    {
        $this->offset = $offset;
        $this->length = strlen($buffer);
    }

    /**
     * Offset.
     *
     * @return int
     */
    public function offset(): int
    {
        return $this->offset;
    }

    /**
     * Remaining.
     *
     * @return int
     */
    public function remaining(): int
    {
        return $this->length - $this->offset;
    }

    /**
     * Eof.
     *
     * @return bool
     */
    public function eof(): bool
    {
        return $this->offset >= $this->length;
    }

    /**
     * Skip.
     *
     * @param int $count
     *
     * @return void
     */
    public function skip(int $count): void
    {
        $this->guard($count);

        $this->offset += $count;
    }

    /**
     * Read a fixed run of raw bytes.
     *
     * @param int $count
     *
     * @return string
     */
    public function take(int $count): string
    {
        $this->guard($count);

        $slice = substr($this->buffer, $this->offset, $count);
        $this->offset += $count;

        return $slice;
    }

    /**
     * Everything from the cursor to the end of the buffer.
     *
     * @return string
     */
    public function rest(): string
    {
        return $this->take($this->remaining());
    }

    /**
     * Peek at the next byte without advancing.
     *
     * @return int
     */
    public function peekUInt8(): int
    {
        $this->guard(1);

        return ord($this->buffer[$this->offset]);
    }

    /**
     * Uint8.
     *
     * @return int
     */
    public function uint8(): int
    {
        $this->guard(1);

        return ord($this->buffer[$this->offset++]);
    }

    /**
     * Uint16.
     *
     * @return int
     */
    public function uint16(): int
    {
        return $this->unsigned(2);
    }

    /**
     * Uint24.
     *
     * @return int
     */
    public function uint24(): int
    {
        return $this->unsigned(3);
    }

    /**
     * Uint32.
     *
     * @return int
     */
    public function uint32(): int
    {
        return $this->unsigned(4);
    }

    /**
     * Uint48 — the width MySQL uses for binlog table ids.
     *
     * @return int
     */
    public function uint48(): int
    {
        return $this->unsigned(6);
    }

    /**
     * Uint64, as a PHP int. Values above PHP_INT_MAX come back negative; use
     * {@see uint64String} where the full unsigned range is reachable.
     *
     * @return int
     */
    public function uint64(): int
    {
        $low = $this->unsigned(4);
        $high = $this->unsigned(4);

        return $low | ($high << 32);
    }

    /**
     * Uint64 rendered as a decimal string, exact across the whole unsigned range.
     *
     * @return string
     */
    public function uint64String(): string
    {
        $value = $this->uint64();

        return $value >= 0 ? (string) $value : sprintf('%u', $value);
    }

    /**
     * Int8.
     *
     * @return int
     */
    public function int8(): int
    {
        return $this->signed($this->uint8(), 1);
    }

    /**
     * Int16.
     *
     * @return int
     */
    public function int16(): int
    {
        return $this->signed($this->unsigned(2), 2);
    }

    /**
     * Int24.
     *
     * @return int
     */
    public function int24(): int
    {
        return $this->signed($this->unsigned(3), 3);
    }

    /**
     * Int32.
     *
     * @return int
     */
    public function int32(): int
    {
        return $this->signed($this->unsigned(4), 4);
    }

    /**
     * Int64.
     *
     * @return int
     */
    public function int64(): int
    {
        return $this->uint64();
    }

    /**
     * Little-endian unsigned integer of an arbitrary width, for the row-image
     * fields whose width is only known from the TABLE_MAP metadata.
     *
     * @param int $width
     *
     * @return int
     */
    public function unsignedLittleEndian(int $width): int
    {
        return $this->unsigned($width);
    }

    /**
     * Big-endian unsigned integer of the given width — used by the handful of
     * column encodings (DECIMAL, DATETIME2, TIME2, TIMESTAMP2) that MySQL stores
     * network-order inside an otherwise little-endian stream.
     *
     * @param int $width
     *
     * @return int
     */
    public function unsignedBigEndian(int $width): int
    {
        $bytes = $this->take($width);
        $value = 0;

        for ($i = 0; $i < $width; ++$i) {
            $value = ($value << 8) | ord($bytes[$i]);
        }

        return $value;
    }

    /**
     * Float32.
     *
     * @return float
     */
    public function float32(): float
    {
        /** @var array{1: float}|false $parsed */
        $parsed = unpack('g', $this->take(4));

        if ($parsed === false) {
            throw MalformedBinlogPacket::unexpected('float', 'unpackable 4-byte run');
        }

        return $parsed[1];
    }

    /**
     * Float64.
     *
     * @return float
     */
    public function float64(): float
    {
        /** @var array{1: float}|false $parsed */
        $parsed = unpack('e', $this->take(8));

        if ($parsed === false) {
            throw MalformedBinlogPacket::unexpected('double', 'unpackable 8-byte run');
        }

        return $parsed[1];
    }

    /**
     * A length-encoded integer. Null (0xFB) is a legal value in result-set rows
     * and comes back as null.
     *
     * @return ?int
     */
    public function lengthEncodedInt(): null|int
    {
        $first = $this->uint8();

        return match (true) {
            $first < 0xFB => $first,
            $first === 0xFB => null,
            $first === 0xFC => $this->unsigned(2),
            $first === 0xFD => $this->unsigned(3),
            $first === 0xFE => $this->uint64(),
            default => throw MalformedBinlogPacket::unexpected('length-encoded integer', "leading byte 0x{$this->hex($first)}"),
        };
    }

    /**
     * Length encoded string.
     *
     * @return ?string
     */
    public function lengthEncodedString(): null|string
    {
        $length = $this->lengthEncodedInt();

        return $length === null ? null : $this->take($length);
    }

    /**
     * A NUL-terminated string. The terminator is consumed but not returned; an
     * unterminated run consumes the rest of the buffer.
     *
     * @return string
     */
    public function nullTerminatedString(): string
    {
        $end = strpos($this->buffer, "\0", $this->offset);

        if ($end === false) {
            return $this->rest();
        }

        $value = substr($this->buffer, $this->offset, $end - $this->offset);
        $this->offset = $end + 1;

        return $value;
    }

    /**
     * Read a MySQL bitmap of the given bit width, least-significant bit first.
     *
     * @param int $bits
     *
     * @return list<bool>
     */
    public function bitmap(int $bits): array
    {
        $bytes = $this->take(intdiv($bits + 7, 8));
        $flags = [];

        for ($i = 0; $i < $bits; ++$i) {
            $flags[] = (ord($bytes[$i >> 3]) & (1 << ($i & 7))) !== 0;
        }

        return $flags;
    }

    /**
     * Unsigned.
     *
     * @param int $width
     *
     * @return int
     */
    private function unsigned(int $width): int
    {
        $bytes = $this->take($width);
        $value = 0;

        for ($i = $width - 1; $i >= 0; --$i) {
            $value = ($value << 8) | ord($bytes[$i]);
        }

        return $value;
    }

    /**
     * Sign-extend a value already read as unsigned.
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
     * Guard.
     *
     * @param int $count
     *
     * @return void
     */
    private function guard(int $count): void
    {
        if ($count < 0 || $this->offset + $count > $this->length) {
            throw MalformedBinlogPacket::truncated($count, $this->remaining(), $this->offset);
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
