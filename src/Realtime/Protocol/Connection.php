<?php

declare(strict_types=1);

/**
 * A MySQL client connection, speaking just enough of the protocol to
 * authenticate, read server variables, and register as a replica.
 *
 * Registering as a replica is the whole trick behind realtime MySQL: rather
 * than polling tables, the client performs the handshake a real replica would
 * and issues COM_BINLOG_DUMP, after which the server pushes every committed row
 * change down the same socket until one side hangs up.
 *
 * Takes a {@see PacketStreamInterface} rather than opening its own socket, so
 * the exchange can be driven from a scripted stream in tests.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Realtime\Protocol;

use PHPdot\Filesystem\Exception\ReplicationAuthenticationFailed;
use PHPdot\Filesystem\Exception\ReplicationConnectionFailed;
use PHPdot\Filesystem\Realtime\Change\Checkpoint;
use PHPdot\Filesystem\Realtime\Change\GtidSet;
use PHPdot\Filesystem\Realtime\Contract\PacketStreamInterface;

final class Connection
{
    private int $serverCapabilities = 0;

    private string $serverVersion = '';

    /**
     * __construct.
     *
     * @param PacketStreamInterface $stream
     */
    public function __construct(private readonly PacketStreamInterface $stream) {}

    /**
     * Server version.
     *
     * @return string
     */
    public function serverVersion(): string
    {
        return $this->serverVersion;
    }

    /**
     * Perform the handshake.
     *
     * @param string $user
     * @param string $password
     * @param string $database
     * @param bool $useTls
     *
     * @return void
     */
    public function authenticate(string $user, string $password, string $database = '', bool $useTls = false): void
    {
        $handshake = $this->expectPacket();

        $reader = new PacketReader($handshake);
        $protocol = $reader->uint8();

        if ($protocol === Command::RESPONSE_ERR) {
            $this->throwError(new PacketReader($handshake, 1));
        }

        if ($protocol !== 10) {
            throw ReplicationConnectionFailed::lost("unsupported MySQL protocol version {$protocol}");
        }

        $this->serverVersion = $reader->nullTerminatedString();
        $reader->skip(4); // connection id
        $scramble = $reader->take(8);
        $reader->skip(1); // filler
        $capabilities = $reader->uint16();
        $plugin = Authentication::NATIVE_PASSWORD;

        if (!$reader->eof()) {
            $reader->skip(1); // character set
            $reader->skip(2); // status flags
            $capabilities |= $reader->uint16() << 16;
            $scrambleLength = $reader->uint8();
            $reader->skip(10); // reserved

            if (($capabilities & Capability::SECURE_CONNECTION) !== 0) {
                // The second half is at least 13 bytes on the wire, but only
                // the first 12 are scramble; the last is a NUL terminator.
                $scramble .= $reader->take(max(13, $scrambleLength - 8) - 1);
                $reader->skip(1);
            }

            if (($capabilities & Capability::PLUGIN_AUTH) !== 0 && !$reader->eof()) {
                $plugin = $reader->nullTerminatedString();
            }
        }

        $this->serverCapabilities = $capabilities;
        $flags = Capability::clientDefaults() & $capabilities;

        if ($database !== '') {
            $flags |= Capability::CONNECT_WITH_DB;
        }

        if ($useTls) {
            $flags = $this->startEncryption($flags, $capabilities);
        }

        $this->stream->writeReply($this->handshakeResponse($flags, $user, $password, $database, $plugin, $scramble));
        $this->finishAuthentication($user, $password, $plugin, $scramble);
    }

    /**
     * Run a statement and discard its result.
     *
     * @param string $sql
     *
     * @return void
     */
    public function execute(string $sql): void
    {
        $this->stream->writeCommand(chr(Command::QUERY) . $sql);
        $response = $this->expectPacket();

        if (ord($response[0]) === Command::RESPONSE_ERR) {
            $this->throwError(new PacketReader($response, 1));
        }

        // A statement that unexpectedly returns rows still has to be drained,
        // or its result set is mistaken for the next command's response.
        if (ord($response[0]) !== Command::RESPONSE_OK && ord($response[0]) !== Command::RESPONSE_EOF) {
            $this->drainResultSet();
        }
    }

    /**
     * Run a query and return its rows as strings, MySQL's text protocol being
     * untyped on the wire.
     *
     * @param string $sql
     *
     * @return list<list<?string>>
     */
    public function query(string $sql): array
    {
        $this->stream->writeCommand(chr(Command::QUERY) . $sql);
        $response = $this->expectPacket();
        $marker = ord($response[0]);

        if ($marker === Command::RESPONSE_ERR) {
            $this->throwError(new PacketReader($response, 1));
        }

        if ($marker === Command::RESPONSE_OK || $marker === Command::RESPONSE_EOF) {
            return [];
        }

        return $this->drainResultSet();
    }

    /**
     * Read one server variable, or null when it is not set.
     *
     * @param string $name
     *
     * @return ?string
     */
    public function serverVariable(string $name): null|string
    {
        $rows = $this->query("SELECT @@GLOBAL.{$name}");

        return $rows[0][0] ?? null;
    }

    /**
     * Announce this client as a replica.
     *
     * Optional for a dump to start, but it makes the connection visible in
     * `SHOW REPLICAS`, which is the difference between an operator seeing this
     * consumer and wondering what is holding a connection open.
     *
     * @param int $serverId
     *
     * @return void
     */
    public function registerReplica(int $serverId): void
    {
        $writer = new PacketWriter();
        $writer->uint8(Command::REGISTER_SLAVE)
            ->uint32($serverId)
            ->uint8(0)  // hostname
            ->uint8(0)  // user
            ->uint8(0)  // password
            ->uint16(0) // port
            ->uint32(0) // replication rank
            ->uint32(0); // master id

        $this->stream->writeCommand($writer->toString());
        $this->expectOk();
    }

    /**
     * Ask the server to start streaming binlog events.
     *
     * GTID mode asks for "everything except the transactions I already have",
     * which is why it survives a failover; file/position mode asks for a byte
     * offset in one specific file on one specific server.
     *
     * @param Checkpoint $checkpoint
     * @param int $serverId
     * @param bool $useGtid
     *
     * @return void
     */
    public function dumpBinlog(Checkpoint $checkpoint, int $serverId, bool $useGtid): void
    {
        $writer = new PacketWriter();

        if ($useGtid) {
            $gtids = GtidSet::parse($checkpoint->gtidSet ?? '')->toBinary();

            $writer->uint8(Command::BINLOG_DUMP_GTID)
                ->uint16(Command::BINLOG_THROUGH_GTID)
                ->uint32($serverId)
                ->uint32(0)  // empty file name: the GTID set decides the start
                ->uint64(4)
                ->uint32(strlen($gtids))
                ->bytes($gtids);
        } else {
            $writer->uint8(Command::BINLOG_DUMP)
                ->uint32($checkpoint->position)
                ->uint16(0)
                ->uint32($serverId)
                ->bytes($checkpoint->file);
        }

        $this->stream->writeCommand($writer->toString());
    }

    /**
     * Read the next binlog event, or null if the stream was idle.
     *
     * Events arrive behind a leading OK byte, which is stripped here so the
     * decoder sees an event starting at its own header.
     *
     * @return ?string
     */
    public function readEvent(): null|string
    {
        $packet = $this->stream->read();

        if ($packet === null || $packet === '') {
            return null;
        }

        $marker = ord($packet[0]);

        if ($marker === Command::RESPONSE_ERR) {
            $this->throwError(new PacketReader($packet, 1));
        }

        // A short 0xFE packet is an EOF, which only appears when the dump was
        // asked to stop at the end of the log rather than block.
        if ($marker === Command::RESPONSE_EOF && strlen($packet) < 9) {
            return null;
        }

        return substr($packet, 1);
    }

    /**
     * Close.
     *
     * @return void
     */
    public function close(): void
    {
        $this->stream->close();
    }

    /**
     * Send the truncated SSL request and upgrade the socket before any
     * credential is written.
     *
     * @param int $flags
     * @param int $capabilities
     *
     * @return int
     */
    private function startEncryption(int $flags, int $capabilities): int
    {
        if (($capabilities & Capability::SSL) === 0) {
            throw ReplicationConnectionFailed::lost('TLS was requested but the server does not offer it');
        }

        $flags |= Capability::SSL;

        $writer = new PacketWriter();
        $writer->uint32($flags)->uint32(0x1000000)->uint8(45)->filler(23);

        $this->stream->writeReply($writer->toString());
        $this->stream->enableEncryption();

        return $flags;
    }

    /**
     * Build HandshakeResponse41.
     *
     * @param int $flags
     * @param string $user
     * @param string $password
     * @param string $database
     * @param string $plugin
     * @param string $scramble
     *
     * @return string
     */
    private function handshakeResponse(
        int $flags,
        string $user,
        string $password,
        string $database,
        string $plugin,
        string $scramble,
    ): string {
        $writer = new PacketWriter();

        $writer->uint32($flags)
            ->uint32(0x1000000) // max packet size
            ->uint8(45)         // utf8mb4_general_ci
            ->filler(23)
            ->nullTerminatedString($user)
            ->lengthEncodedString(Authentication::scramble($plugin, $password, $scramble));

        if ($database !== '') {
            $writer->nullTerminatedString($database);
        }

        if (($flags & Capability::PLUGIN_AUTH) !== 0) {
            $writer->nullTerminatedString($plugin);
        }

        return $writer->toString();
    }

    /**
     * Drive whatever the server asks for after the first response: success, a
     * plugin switch, or caching_sha2's second round.
     *
     * @param string $user
     * @param string $password
     * @param string $plugin
     * @param string $scramble
     *
     * @return void
     */
    private function finishAuthentication(string $user, string $password, string $plugin, string $scramble): void
    {
        while (true) {
            $packet = $this->expectPacket();
            $marker = ord($packet[0]);

            if ($marker === Command::RESPONSE_OK) {
                return;
            }

            if ($marker === Command::RESPONSE_ERR) {
                $this->throwAuthError($user, new PacketReader($packet, 1));
            }

            if ($marker === Command::RESPONSE_EOF) {
                // AuthSwitchRequest: start over with the plugin the server named.
                $reader = new PacketReader($packet, 1);
                $plugin = $reader->nullTerminatedString();
                $scramble = rtrim($reader->rest(), "\0");

                $this->stream->writeReply(Authentication::scramble($plugin, $password, $scramble));

                continue;
            }

            if ($marker === Command::AUTH_MORE_DATA) {
                $this->continueCachingSha2($user, $password, $scramble, ord($packet[1] ?? "\0"));

                continue;
            }

            throw ReplicationAuthenticationFailed::rejected($user, "unexpected authentication packet 0x" . dechex($marker));
        }
    }

    /**
     * caching_sha2_password's second round.
     *
     * The fast path only works if the server already has the password cached.
     * Otherwise it demands the real password, which may only be sent over TLS
     * or encrypted to the server's RSA public key.
     *
     * @param string $user
     * @param string $password
     * @param string $scramble
     * @param int $status
     *
     * @return void
     */
    private function continueCachingSha2(string $user, string $password, string $scramble, int $status): void
    {
        if ($status === Command::CACHING_SHA2_FAST_AUTH_SUCCESS) {
            return; // An OK packet follows.
        }

        if ($status !== Command::CACHING_SHA2_FULL_AUTH_REQUIRED) {
            throw ReplicationAuthenticationFailed::rejected($user, 'unexpected caching_sha2_password state');
        }

        if ($this->stream->isEncrypted()) {
            $this->stream->writeReply($password . "\0");

            return;
        }

        $this->stream->writeReply(chr(Command::CACHING_SHA2_REQUEST_PUBLIC_KEY));
        $key = $this->expectPacket();

        if (ord($key[0]) !== Command::AUTH_MORE_DATA) {
            throw ReplicationAuthenticationFailed::insecureFullAuthentication();
        }

        $this->stream->writeReply(Authentication::encryptWithPublicKey($password, $scramble, substr($key, 1)));
    }

    /**
     * Read the column definitions and rows of a result set, through to the
     * terminating EOF.
     *
     * @return list<list<?string>>
     */
    private function drainResultSet(): array
    {
        // Column definitions, terminated by an EOF packet.
        while (true) {
            $packet = $this->expectPacket();

            if ($this->isEof($packet)) {
                break;
            }
        }

        $rows = [];

        while (true) {
            $packet = $this->expectPacket();

            if ($this->isEof($packet)) {
                break;
            }

            if (ord($packet[0]) === Command::RESPONSE_ERR) {
                $this->throwError(new PacketReader($packet, 1));
            }

            $reader = new PacketReader($packet);
            $row = [];

            while (!$reader->eof()) {
                $row[] = $reader->lengthEncodedString();
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * An EOF packet is 0xFE with a short payload — long 0xFE payloads are
     * length-encoded integers at the head of a row.
     *
     * @param string $packet
     *
     * @return bool
     */
    private function isEof(string $packet): bool
    {
        return ord($packet[0]) === Command::RESPONSE_EOF && strlen($packet) < 9;
    }

    /**
     * Expect ok.
     *
     * @return void
     */
    private function expectOk(): void
    {
        $packet = $this->expectPacket();

        if (ord($packet[0]) === Command::RESPONSE_ERR) {
            $this->throwError(new PacketReader($packet, 1));
        }
    }

    /**
     * Read a packet, treating a timeout as a dead connection — every call site
     * here is mid-exchange, where the server owes an immediate answer.
     *
     * @return string
     */
    private function expectPacket(): string
    {
        $packet = $this->stream->read();

        if ($packet === null || $packet === '') {
            throw ReplicationConnectionFailed::lost('the server sent no response');
        }

        return $packet;
    }

    /**
     * Throw error.
     *
     * @param PacketReader $reader
     *
     * @return never
     */
    private function throwError(PacketReader $reader): never
    {
        [$code, $message] = $this->readError($reader);

        throw ReplicationConnectionFailed::serverError($code, $message);
    }

    /**
     * Throw auth error.
     *
     * @param string $user
     * @param PacketReader $reader
     *
     * @return never
     */
    private function throwAuthError(string $user, PacketReader $reader): never
    {
        [$code, $message] = $this->readError($reader);

        throw ReplicationAuthenticationFailed::rejected($user, "{$message} (error {$code})");
    }

    /**
     * Read error.
     *
     * @param PacketReader $reader
     *
     * @return array{int, string}
     */
    private function readError(PacketReader $reader): array
    {
        $code = $reader->uint16();

        if (($this->serverCapabilities & Capability::PROTOCOL_41) !== 0 && $reader->remaining() >= 6) {
            $reader->skip(6); // '#' plus five characters of SQL state
        }

        return [$code, $reader->rest()];
    }
}
