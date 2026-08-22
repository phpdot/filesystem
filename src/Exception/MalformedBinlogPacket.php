<?php

declare(strict_types=1);

/**
 * A packet or binlog event ended earlier than its own framing promised, or
 * carried a field this decoder cannot make sense of.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Exception;

use RuntimeException;

final class MalformedBinlogPacket extends RuntimeException implements FilesystemException
{
    public function errorCode(): string
    {
        return 'filesystem.malformed_binlog_packet';
    }

    /**
     * Truncated.
     *
     * @param int $needed
     * @param int $available
     * @param int $offset
     *
     * @return self
     */
    public static function truncated(int $needed, int $available, int $offset): self
    {
        return new self("Malformed packet: needed {$needed} bytes at offset {$offset}, {$available} remain.");
    }

    /**
     * Unexpected value.
     *
     * @param string $field
     * @param string $detail
     *
     * @return self
     */
    public static function unexpected(string $field, string $detail): self
    {
        return new self("Malformed packet: unexpected {$field} ({$detail}).");
    }

    /**
     * A TABLE_MAP for the row event's table id was never seen on this stream.
     *
     * @param int $tableId
     *
     * @return self
     */
    public static function unmappedTable(int $tableId): self
    {
        return new self("Row event references table id {$tableId} with no preceding TABLE_MAP event.");
    }
}
