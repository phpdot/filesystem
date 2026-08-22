<?php

declare(strict_types=1);

/**
 * MySQL client/server capability flags, as exchanged in the handshake.
 *
 * Only the flags this client actually negotiates are listed.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Realtime\Protocol;

final class Capability
{
    public const LONG_PASSWORD = 0x00000001;
    public const FOUND_ROWS = 0x00000002;
    public const LONG_FLAG = 0x00000004;
    public const CONNECT_WITH_DB = 0x00000008;
    public const LOCAL_FILES = 0x00000080;
    public const PROTOCOL_41 = 0x00000200;
    public const SSL = 0x00000800;
    public const TRANSACTIONS = 0x00002000;
    public const SECURE_CONNECTION = 0x00008000;
    public const MULTI_STATEMENTS = 0x00010000;
    public const MULTI_RESULTS = 0x00020000;
    public const PLUGIN_AUTH = 0x00080000;
    public const CONNECT_ATTRS = 0x00100000;
    public const PLUGIN_AUTH_LENENC_CLIENT_DATA = 0x00200000;
    public const DEPRECATE_EOF = 0x01000000;

    /**
     * The flags this client asks for, before intersecting with the server's.
     *
     * DEPRECATE_EOF is deliberately absent: the binlog dump loop wants the
     * classic EOF packet to recognise the end of a non-blocking stream.
     *
     * @return int
     */
    public static function clientDefaults(): int
    {
        return self::LONG_PASSWORD
            | self::LONG_FLAG
            | self::PROTOCOL_41
            | self::TRANSACTIONS
            | self::SECURE_CONNECTION
            | self::MULTI_RESULTS
            | self::PLUGIN_AUTH
            | self::PLUGIN_AUTH_LENENC_CLIENT_DATA;
    }
}
