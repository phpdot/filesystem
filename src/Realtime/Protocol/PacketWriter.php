<?php

declare(strict_types=1);

/**
 * Builds a MySQL wire payload, little-endian, mirroring {@see PacketReader}.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Realtime\Protocol;

final class PacketWriter
{
    private string $buffer = '';

    /**
     * Uint8.
     *
     * @param int $value
     *
     * @return self
     */
    public function uint8(int $value): self
    {
        $this->buffer .= chr($value & 0xFF);

        return $this;
    }

    /**
     * Uint16.
     *
     * @param int $value
     *
     * @return self
     */
    public function uint16(int $value): self
    {
        return $this->unsigned($value, 2);
    }

    /**
     * Uint32.
     *
     * @param int $value
     *
     * @return self
     */
    public function uint32(int $value): self
    {
        return $this->unsigned($value, 4);
    }

    /**
     * Uint64.
     *
     * @param int $value
     *
     * @return self
     */
    public function uint64(int $value): self
    {
        return $this->unsigned($value, 8);
    }

    /**
     * Raw bytes, written verbatim.
     *
     * @param string $bytes
     *
     * @return self
     */
    public function bytes(string $bytes): self
    {
        $this->buffer .= $bytes;

        return $this;
    }

    /**
     * A run of NUL bytes.
     *
     * @param int $count
     *
     * @return self
     */
    public function filler(int $count): self
    {
        $this->buffer .= str_repeat("\0", $count);

        return $this;
    }

    /**
     * Null terminated string.
     *
     * @param string $value
     *
     * @return self
     */
    public function nullTerminatedString(string $value): self
    {
        $this->buffer .= $value . "\0";

        return $this;
    }

    /**
     * Length encoded int.
     *
     * @param int $value
     *
     * @return self
     */
    public function lengthEncodedInt(int $value): self
    {
        return match (true) {
            $value < 0xFB => $this->uint8($value),
            $value < 0x10000 => $this->uint8(0xFC)->unsigned($value, 2),
            $value < 0x1000000 => $this->uint8(0xFD)->unsigned($value, 3),
            default => $this->uint8(0xFE)->unsigned($value, 8),
        };
    }

    /**
     * Length encoded string.
     *
     * @param string $value
     *
     * @return self
     */
    public function lengthEncodedString(string $value): self
    {
        return $this->lengthEncodedInt(strlen($value))->bytes($value);
    }

    /**
     * Length.
     *
     * @return int
     */
    public function length(): int
    {
        return strlen($this->buffer);
    }

    /**
     * The assembled payload.
     *
     * @return string
     */
    public function toString(): string
    {
        return $this->buffer;
    }

    /**
     * Unsigned.
     *
     * @param int $value
     * @param int $width
     *
     * @return self
     */
    private function unsigned(int $value, int $width): self
    {
        for ($i = 0; $i < $width; ++$i) {
            $this->buffer .= chr(($value >> ($i * 8)) & 0xFF);
        }

        return $this;
    }
}
