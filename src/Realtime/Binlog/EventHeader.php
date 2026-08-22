<?php

declare(strict_types=1);

/**
 * The fixed 19-byte header in front of every binlog event.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Realtime\Binlog;

use DateTimeImmutable;
use DateTimeZone;
use PHPdot\Filesystem\Realtime\Protocol\PacketReader;

final readonly class EventHeader
{
    public const LENGTH = 19;

    /**
     * __construct.
     *
     * @param int $timestamp
     * @param int $typeCode
     * @param int $serverId
     * @param int $eventSize
     * @param int $logPosition
     * @param int $flags
     */
    public function __construct(
        public int $timestamp,
        public int $typeCode,
        public int $serverId,
        public int $eventSize,
        public int $logPosition,
        public int $flags,
    ) {}

    /**
     * Decode.
     *
     * @param PacketReader $reader
     *
     * @return self
     */
    public static function decode(PacketReader $reader): self
    {
        return new self(
            $reader->uint32(),
            $reader->uint8(),
            $reader->uint32(),
            $reader->uint32(),
            $reader->uint32(),
            $reader->uint16(),
        );
    }

    /**
     * The decoded event type, or null for an event this client does not model.
     *
     * @return ?EventType
     */
    public function type(): null|EventType
    {
        return EventType::tryFrom($this->typeCode);
    }

    /**
     * Binlog timestamps are UTC epoch seconds.
     *
     * @return DateTimeImmutable
     */
    public function occurredAt(): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $this->timestamp))->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Bytes of body, once the header and any checksum tail are removed.
     *
     * @param int $checksumLength
     *
     * @return int
     */
    public function bodyLength(int $checksumLength): int
    {
        return max(0, $this->eventSize - self::LENGTH - $checksumLength);
    }
}
