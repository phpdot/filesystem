<?php

declare(strict_types=1);

namespace PHPdot\Filesystem\Tests\Unit\Realtime;

use PHPdot\Filesystem\Exception\ReplicationConnectionFailed;
use PHPdot\Filesystem\Realtime\Contract\PacketStreamInterface;

/**
 * A packet stream that replays a scripted server, so the connection and client
 * layers can be tested without MySQL.
 *
 * An exhausted script reads as an idle timeout, which is what a quiet binlog
 * looks like on a real socket.
 */
final class ScriptedPacketStream implements PacketStreamInterface
{
    private const FAILURE = "\x00__fail__";

    /**
     * @var list<string>
     */
    private array $packets = [];

    /**
     * @var list<string>
     */
    private array $writes = [];

    private bool $encrypted = false;

    private bool $closed = false;

    /**
     * Queue raw packets.
     */
    public function push(string ...$packets): self
    {
        foreach ($packets as $packet) {
            $this->packets[] = $packet;
        }

        return $this;
    }

    /**
     * Queue an OK packet.
     */
    public function pushOk(): self
    {
        return $this->push("\x00\x00\x00\x02\x00\x00\x00");
    }

    /**
     * Queue an ERR packet.
     */
    public function pushError(int $code, string $message): self
    {
        return $this->push("\xFF" . pack('v', $code) . '#HY000' . $message);
    }

    /**
     * Queue a single-column result set.
     */
    public function pushResultSet(string ...$values): self
    {
        $this->push("\x01");              // one column
        $this->push("\x03def");           // column definition
        $this->push("\xFE\x00\x00\x02\x00"); // EOF

        foreach ($values as $value) {
            $this->push(chr(strlen($value)) . $value);
        }

        return $this->push("\xFE\x00\x00\x02\x00");
    }

    /**
     * Queue a binlog event, behind the leading OK byte the server prefixes.
     */
    public function pushEvent(string $event): self
    {
        return $this->push("\x00" . $event);
    }

    /**
     * Queue a transport failure at this point in the script.
     */
    public function pushFailure(): self
    {
        return $this->push(self::FAILURE);
    }

    /**
     * Everything the client wrote.
     *
     * @return list<string>
     */
    public function writes(): array
    {
        return $this->writes;
    }

    /**
     * Whether close() was called.
     */
    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function read(): null|string
    {
        $packet = array_shift($this->packets);

        if ($packet === null) {
            return null;
        }

        if ($packet === self::FAILURE) {
            throw ReplicationConnectionFailed::lost('scripted failure');
        }

        return $packet;
    }

    public function writeCommand(string $payload): void
    {
        $this->writes[] = $payload;
    }

    public function writeReply(string $payload): void
    {
        $this->writes[] = $payload;
    }

    public function enableEncryption(): void
    {
        $this->encrypted = true;
    }

    public function isEncrypted(): bool
    {
        return $this->encrypted;
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
