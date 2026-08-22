<?php

declare(strict_types=1);

/**
 * The MySQL command bytes this client sends.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Realtime\Protocol;

final class Command
{
    public const QUERY = 0x03;
    public const PING = 0x0E;
    public const BINLOG_DUMP = 0x12;
    public const REGISTER_SLAVE = 0x15;
    public const BINLOG_DUMP_GTID = 0x1E;

    /**
     * Response markers shared by every command.
     */
    public const RESPONSE_OK = 0x00;
    public const RESPONSE_EOF = 0xFE;
    public const RESPONSE_ERR = 0xFF;
    public const RESPONSE_LOCAL_INFILE = 0xFB;

    /**
     * AuthMoreData sub-commands used by caching_sha2_password.
     */
    public const AUTH_MORE_DATA = 0x01;
    public const CACHING_SHA2_FAST_AUTH_SUCCESS = 0x03;
    public const CACHING_SHA2_FULL_AUTH_REQUIRED = 0x04;
    public const CACHING_SHA2_REQUEST_PUBLIC_KEY = 0x02;

    /**
     * COM_BINLOG_DUMP flags.
     */
    public const BINLOG_DUMP_NON_BLOCK = 0x01;
    public const BINLOG_THROUGH_GTID = 0x04;
}
