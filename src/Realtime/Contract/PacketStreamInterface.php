<?php

declare(strict_types=1);

/**
 * The MySQL packet transport, abstracted so the protocol and client layers can
 * be exercised against a scripted stream instead of a server.
 *
 * Implementations own MySQL's 4-byte framing and its per-command sequence
 * counter; callers deal only in payloads.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Realtime\Contract;

interface PacketStreamInterface
{
    /**
     * Read one reassembled packet payload, or null if the read timed out with
     * nothing received. A timeout is not an error: a quiet binlog is normal.
     *
     * @return ?string
     */
    public function read(): null|string;

    /**
     * Write the first packet of a new command, restarting the sequence counter.
     *
     * @param string $payload
     *
     * @return void
     */
    public function writeCommand(string $payload): void;

    /**
     * Write a continuation packet, advancing the sequence counter — used by the
     * multi-step authentication exchanges.
     *
     * @param string $payload
     *
     * @return void
     */
    public function writeReply(string $payload): void;

    /**
     * Upgrade the connection to TLS in place, after the SSL request packet.
     *
     * @return void
     */
    public function enableEncryption(): void;

    /**
     * Whether bytes on this stream are already encrypted.
     *
     * @return bool
     */
    public function isEncrypted(): bool;

    /**
     * Close.
     *
     * @return void
     */
    public function close(): void;
}
