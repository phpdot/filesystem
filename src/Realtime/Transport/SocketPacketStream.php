<?php

declare(strict_types=1);

/**
 * MySQL packet framing over a plain PHP stream socket.
 *
 * Every packet is a 3-byte little-endian length, a 1-byte sequence id, then the
 * payload. Payloads of exactly 0xFFFFFF bytes mean "more follows", so both
 * directions have to split and reassemble rather than trusting one header.
 *
 * Uses the native stream functions rather than ext-sockets on purpose: under
 * Swoole those are hooked, so the same code yields the coroutine instead of
 * blocking the worker, with no `ext-swoole` dependency here.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Realtime\Transport;

use PHPdot\Filesystem\Exception\ReplicationConnectionFailed;
use PHPdot\Filesystem\Realtime\Contract\PacketStreamInterface;

final class SocketPacketStream implements PacketStreamInterface
{
    private const MAX_PAYLOAD = 0xFFFFFF;

    /**
     * @var resource
     */
    private $socket;

    private int $sequence = 0;

    private bool $encrypted = false;

    /**
     * __construct.
     *
     * @param resource $socket
     * @param bool $verifyPeer
     */
    private function __construct($socket, private readonly bool $verifyPeer)
    {
        $this->socket = $socket;
    }

    /**
     * Open a connection to a MySQL server.
     *
     * @param string $host
     * @param int $port
     * @param float $connectTimeout
     * @param float $readTimeout
     * @param bool $verifyPeer
     *
     * @return self
     */
    public static function connect(
        string $host,
        int $port,
        float $connectTimeout = 10.0,
        float $readTimeout = 60.0,
        bool $verifyPeer = true,
    ): self {
        $errorCode = 0;
        $errorMessage = '';

        $socket = @stream_socket_client(
            "tcp://{$host}:{$port}",
            $errorCode,
            $errorMessage,
            $connectTimeout,
            STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => [
                'verify_peer' => $verifyPeer,
                'verify_peer_name' => $verifyPeer,
                'peer_name' => $host,
            ]]),
        );

        if ($socket === false) {
            // Both diagnostics come back by reference, so neither is typed.
            $detail = is_string($errorMessage) && $errorMessage !== ''
                ? $errorMessage
                : 'error ' . (is_int($errorCode) ? $errorCode : 0);

            throw ReplicationConnectionFailed::connect($host, $port, $detail);
        }

        $seconds = (int) $readTimeout;
        stream_set_timeout($socket, $seconds, (int) (($readTimeout - $seconds) * 1_000_000));

        return new self($socket, $verifyPeer);
    }

    public function read(): null|string
    {
        $payload = '';

        do {
            $header = $this->readBytes(4, $payload === '');

            if ($header === null) {
                return null;
            }

            $length = ord($header[0]) | (ord($header[1]) << 8) | (ord($header[2]) << 16);
            $this->sequence = ord($header[3]);

            if ($length > 0) {
                $chunk = $this->readBytes($length, false);
                $payload .= $chunk ?? '';
            }
        } while ($length === self::MAX_PAYLOAD);

        return $payload;
    }

    public function writeCommand(string $payload): void
    {
        $this->sequence = 0;
        $this->writePackets($payload);
    }

    public function writeReply(string $payload): void
    {
        ++$this->sequence;
        $this->writePackets($payload);
    }

    public function enableEncryption(): void
    {
        $method = STREAM_CRYPTO_METHOD_TLS_CLIENT;

        if (@stream_socket_enable_crypto($this->socket, true, $method) !== true) {
            throw ReplicationConnectionFailed::lost('the TLS handshake failed');
        }

        $this->encrypted = true;
    }

    public function isEncrypted(): bool
    {
        return $this->encrypted;
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }

    /**
     * Whether the stream was opened with peer verification.
     *
     * @return bool
     */
    public function verifiesPeer(): bool
    {
        return $this->verifyPeer;
    }

    /**
     * Split a payload across packets. A payload that is an exact multiple of the
     * maximum needs a trailing empty packet, or the server waits forever for a
     * continuation that never comes.
     *
     * @param string $payload
     *
     * @return void
     */
    private function writePackets(string $payload): void
    {
        $offset = 0;
        $total = strlen($payload);

        do {
            $chunk = substr($payload, $offset, self::MAX_PAYLOAD);
            $length = strlen($chunk);

            $header = chr($length & 0xFF)
                . chr(($length >> 8) & 0xFF)
                . chr(($length >> 16) & 0xFF)
                . chr($this->sequence & 0xFF);

            $this->writeAll($header . $chunk);

            $offset += $length;
            ++$this->sequence;
        } while ($length === self::MAX_PAYLOAD || $offset < $total);

        // The counter is bumped once past the final packet above; step back so
        // the next reply continues the exchange correctly.
        --$this->sequence;
    }

    /**
     * Write all.
     *
     * @param string $bytes
     *
     * @return void
     */
    private function writeAll(string $bytes): void
    {
        $remaining = strlen($bytes);

        while ($remaining > 0) {
            $written = @fwrite($this->socket, $bytes);

            if ($written === false || $written === 0) {
                throw ReplicationConnectionFailed::lost('the connection closed while writing');
            }

            $bytes = substr($bytes, $written);
            $remaining -= $written;
        }
    }

    /**
     * Read exactly this many bytes.
     *
     * A timeout is only tolerable before any byte of a packet has arrived; once
     * a header is in hand, a short read means the packet is torn and the stream
     * can no longer be trusted.
     *
     * @param int $length
     * @param bool $timeoutAllowed
     *
     * @return ?string
     */
    private function readBytes(int $length, bool $timeoutAllowed): null|string
    {
        $buffer = '';

        while (strlen($buffer) < $length) {
            $chunk = @fread($this->socket, max(1, $length - strlen($buffer)));

            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->socket);

                if ($meta['timed_out'] === true) {
                    if ($timeoutAllowed && $buffer === '') {
                        return null;
                    }

                    throw ReplicationConnectionFailed::lost('the read timed out mid-packet');
                }

                throw ReplicationConnectionFailed::lost('the server closed the connection');
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }
}
