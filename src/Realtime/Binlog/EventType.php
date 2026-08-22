<?php

declare(strict_types=1);

/**
 * Binlog event type codes.
 *
 * Only the events this client acts on are enumerated; anything else on the
 * stream is skipped by {@see EventType::tryFrom} returning null, which keeps a
 * newer server's events from stalling an older client.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Realtime\Binlog;

use PHPdot\Filesystem\Realtime\Change\ChangeKind;

enum EventType: int
{
    case QUERY = 2;
    case STOP = 3;
    case ROTATE = 4;
    case FORMAT_DESCRIPTION = 15;
    case XID = 16;
    case TABLE_MAP = 19;
    case WRITE_ROWS_V1 = 23;
    case UPDATE_ROWS_V1 = 24;
    case DELETE_ROWS_V1 = 25;
    case HEARTBEAT = 27;
    case ROWS_QUERY = 29;
    case WRITE_ROWS_V2 = 30;
    case UPDATE_ROWS_V2 = 31;
    case DELETE_ROWS_V2 = 32;
    case GTID = 33;
    case ANONYMOUS_GTID = 34;
    case PREVIOUS_GTIDS = 35;
    case PARTIAL_UPDATE_ROWS = 39;
    case TRANSACTION_PAYLOAD = 40;
    case HEARTBEAT_V2 = 41;

    /**
     * Whether this event carries row images.
     *
     * @return bool
     */
    public function isRowsEvent(): bool
    {
        return $this->hasBeforeImage() || $this->hasAfterImage();
    }

    /**
     * v2 row events carry an extra-data block that v1 events do not.
     *
     * @return bool
     */
    public function hasExtraRowData(): bool
    {
        return match ($this) {
            self::WRITE_ROWS_V2, self::UPDATE_ROWS_V2, self::DELETE_ROWS_V2,
            self::PARTIAL_UPDATE_ROWS => true,
            default => false,
        };
    }

    /**
     * Deletes and updates log the row as it was.
     *
     * @return bool
     */
    public function hasBeforeImage(): bool
    {
        return match ($this) {
            self::DELETE_ROWS_V1, self::DELETE_ROWS_V2,
            self::UPDATE_ROWS_V1, self::UPDATE_ROWS_V2, self::PARTIAL_UPDATE_ROWS => true,
            default => false,
        };
    }

    /**
     * Inserts and updates log the row as it became.
     *
     * @return bool
     */
    public function hasAfterImage(): bool
    {
        return match ($this) {
            self::WRITE_ROWS_V1, self::WRITE_ROWS_V2,
            self::UPDATE_ROWS_V1, self::UPDATE_ROWS_V2, self::PARTIAL_UPDATE_ROWS => true,
            default => false,
        };
    }

    /**
     * Updates are the only events that carry a second columns-present bitmap,
     * because they are the only ones logging two images per row.
     *
     * @return bool
     */
    public function hasSecondBitmap(): bool
    {
        return $this->hasBeforeImage() && $this->hasAfterImage();
    }

    /**
     * The change this event represents, for the row events.
     *
     * @return ChangeKind
     */
    public function changeKind(): ChangeKind
    {
        return match (true) {
            !$this->hasBeforeImage() => ChangeKind::INSERT,
            !$this->hasAfterImage() => ChangeKind::DELETE,
            default => ChangeKind::UPDATE,
        };
    }
}
