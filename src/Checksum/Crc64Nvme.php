<?php

declare(strict_types=1);

/**
 * CRC-64/NVME, the checksum family the wire names x-amz-checksum-crc64nvme —
 * the part-digest family every S3-compatible storage verifies against.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Checksum;

final class Crc64Nvme
{
    /**
     * The NVMe polynomial (0xAD93D23594C93659, MSB-first) in reflected form,
     * held as the signed int of its bit pattern.
     */
    private const REFLECTED_POLYNOMIAL = -7319313487190308427;

    /**
     * Checksum.
     *
     * @param string $data
     *
     * @return int The checksum as the signed int of its 64-bit pattern
     */
    public static function checksum(string $data): int
    {
        $crc = -1;
        $length = strlen($data);
        for ($offset = 0; $offset < $length; $offset++) {
            $crc ^= ord($data[$offset]);
            for ($bit = 0; $bit < 8; $bit++) {
                $low = $crc & 1;
                $crc = (($crc >> 1) & 0x7FFFFFFFFFFFFFFF) ^ ($low === 1 ? self::REFLECTED_POLYNOMIAL : 0);
            }
        }

        return $crc ^ -1;
    }

    /**
     * The digest the wire wants: the checksum as eight big-endian bytes,
     * base64 — the endianness S3 and R2 both verify against.
     *
     * @param string $data
     *
     * @return string
     */
    public static function base64Digest(string $data): string
    {
        return base64_encode(pack('J', self::checksum($data)));
    }

    /**
     * Hex checksum.
     *
     * @param string $data
     *
     * @return string
     */
    public static function hexChecksum(string $data): string
    {
        return sprintf('%016x', self::checksum($data));
    }
}
