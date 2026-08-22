<?php

declare(strict_types=1);

/**
 * The stream used a binlog feature this client does not decode — usually a
 * server configured outside the supported matrix (row format, checksum, image).
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Exception;

use RuntimeException;

final class UnsupportedBinlogFeature extends RuntimeException implements FilesystemException
{
    public function errorCode(): string
    {
        return 'filesystem.unsupported_binlog_feature';
    }

    /**
     * Column type.
     *
     * @param int $type
     *
     * @return self
     */
    public static function columnType(int $type): self
    {
        $hex = strtoupper(dechex($type));

        return new self("Unsupported MySQL column type 0x{$hex} in a row event.");
    }

    /**
     * Checksum algorithm.
     *
     * @param int $algorithm
     *
     * @return self
     */
    public static function checksumAlgorithm(int $algorithm): self
    {
        return new self("Unsupported binlog checksum algorithm {$algorithm}; expected NONE (0) or CRC32 (1).");
    }

    /**
     * Server setting.
     *
     * @param string $setting
     * @param string $actual
     * @param string $required
     *
     * @return self
     */
    public static function serverSetting(string $setting, string $actual, string $required): self
    {
        return new self("MySQL {$setting} is \"{$actual}\"; realtime replication requires \"{$required}\".");
    }
}
