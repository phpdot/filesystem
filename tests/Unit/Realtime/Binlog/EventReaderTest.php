<?php

declare(strict_types=1);

namespace PHPdot\Filesystem\Tests\Unit\Realtime\Binlog;

use PHPdot\Filesystem\Exception\MalformedBinlogPacket;
use PHPdot\Filesystem\Exception\UnsupportedBinlogFeature;
use PHPdot\Filesystem\Realtime\Binlog\ColumnType;
use PHPdot\Filesystem\Realtime\Binlog\EventReader;
use PHPdot\Filesystem\Realtime\Binlog\EventType;
use PHPdot\Filesystem\Realtime\Change\ChangeEvent;
use PHPdot\Filesystem\Realtime\Change\ChangeKind;
use PHPdot\Filesystem\Realtime\Change\Checkpoint;
use PHPdot\Filesystem\Tests\Unit\Realtime\BinlogEventBuilder as Build;
use PHPUnit\Framework\TestCase;

/**
 * Drives the decoder over synthetic streams that mirror what a real server
 * sends, exercising the statefulness that makes the binlog awkward: the column
 * layout, file name and transaction identity all arrive in separate events
 * before the row event that needs them.
 */
final class EventReaderTest extends TestCase
{
    private const UUID = '3e11fa47-71ca-11e1-9e33-c80aa9429562';

    private const TABLE_ID = 42;

    /**
     * A three-column `shop.orders`: INT, VARCHAR(64), DECIMAL(10,4).
     */
    private static function ordersTableMap(): string
    {
        return Build::tableMap(
            self::TABLE_ID,
            'shop',
            'orders',
            [ColumnType::LONG, ColumnType::VARCHAR, ColumnType::NEWDECIMAL],
            [0, 64, 0x040A],
            [false, true, false],
            ['id', 'sku', 'total'],
            [0],
        );
    }

    /**
     * Values for one full row image.
     */
    private static function orderValues(int $id, string $sku): string
    {
        return pack('V', $id) . chr(strlen($sku)) . $sku . "\x80\x04\xD2\x16\x2E";
    }

    /**
     * Feed a reader a whole stream and collect what came out.
     *
     * @param list<string> $events
     *
     * @return list<ChangeEvent>
     */
    private static function drain(EventReader $reader, array $events): array
    {
        $changes = [];

        foreach ($events as $event) {
            foreach ($reader->consume($event) as $change) {
                $changes[] = $change;
            }
        }

        return $changes;
    }

    public function testDecodesAnInsertWithColumnNamesPrimaryKeyAndGtid(): void
    {
        $reader = new EventReader();

        $changes = self::drain($reader, [
            Build::formatDescription(),
            Build::rotate('binlog.000004'),
            Build::gtid(self::UUID, 7),
            Build::query('shop', 'BEGIN'),
            self::ordersTableMap(),
            Build::rows(EventType::WRITE_ROWS_V2, self::TABLE_ID, 3, [true, true, true], [
                Build::rowImage([false, false, false], self::orderValues(1, 'SKU-1')),
            ], 900),
            Build::commit(1, 1234),
        ]);

        self::assertCount(1, $changes);

        $change = $changes[0];
        self::assertSame(ChangeKind::INSERT, $change->kind);
        self::assertSame('shop.orders', $change->qualifiedName());
        self::assertSame([], $change->before);
        self::assertSame(['id' => 1, 'sku' => 'SKU-1', 'total' => '1234.5678'], $change->after);
        self::assertSame(['id' => 1], $change->primaryKey);
        self::assertSame(self::UUID . ':7', $change->gtid);
    }

    public function testCheckpointTracksFilePositionAndCommittedGtids(): void
    {
        $reader = new EventReader();

        self::drain($reader, [
            Build::formatDescription(),
            Build::rotate('binlog.000004'),
            Build::gtid(self::UUID, 7),
            self::ordersTableMap(),
            Build::rows(EventType::WRITE_ROWS_V2, self::TABLE_ID, 3, [true, true, true], [
                Build::rowImage([false, false, false], self::orderValues(1, 'SKU-1')),
            ], 900),
            Build::commit(1, 1234),
        ]);

        $checkpoint = $reader->checkpoint();

        self::assertSame('binlog.000004', $checkpoint->file);
        // The header's log position names the *next* event, which is exactly
        // where a resume should ask to restart.
        self::assertSame(1234, $checkpoint->position);
        self::assertSame(self::UUID . ':7', $checkpoint->gtidSet);
    }

    public function testAnUncommittedTransactionDoesNotEnterTheGtidSet(): void
    {
        $reader = new EventReader();

        self::drain($reader, [
            Build::formatDescription(),
            Build::rotate('binlog.000004'),
            Build::gtid(self::UUID, 7),
            Build::query('shop', 'BEGIN'),
            self::ordersTableMap(),
            Build::rows(EventType::WRITE_ROWS_V2, self::TABLE_ID, 3, [true, true, true], [
                Build::rowImage([false, false, false], self::orderValues(1, 'SKU-1')),
            ], 900),
        ]);

        // Resuming from a GTID that never committed would skip the transaction.
        self::assertNull($reader->checkpoint()->gtidSet);
    }

    public function testGtidSetAccumulatesAcrossTransactions(): void
    {
        $reader = new EventReader();
        $events = [Build::formatDescription(), Build::rotate('binlog.000004')];

        foreach ([7, 8, 9] as $number) {
            $events[] = Build::gtid(self::UUID, $number);
            $events[] = Build::commit($number);
        }

        self::drain($reader, $events);

        self::assertSame(self::UUID . ':7-9', $reader->checkpoint()->gtidSet);
    }

    public function testDecodesAnUpdateWithBothImagesAndReportsChangedColumns(): void
    {
        $reader = new EventReader();

        $changes = self::drain($reader, [
            Build::formatDescription(),
            self::ordersTableMap(),
            Build::rows(EventType::UPDATE_ROWS_V2, self::TABLE_ID, 3, [true, true, true], [
                Build::rowImage([false, false, false], self::orderValues(1, 'OLD')),
                Build::rowImage([false, false, false], self::orderValues(1, 'NEW')),
            ]),
        ]);

        self::assertCount(1, $changes);

        $change = $changes[0];
        self::assertSame(ChangeKind::UPDATE, $change->kind);
        self::assertSame('OLD', $change->before['sku']);
        self::assertSame('NEW', $change->after['sku']);
        self::assertSame(['sku'], $change->changedColumns());
        self::assertTrue($change->touches('sku'));
        self::assertFalse($change->touches('id'));
    }

    public function testDecodesADeleteAsABeforeImageOnly(): void
    {
        $reader = new EventReader();

        $changes = self::drain($reader, [
            Build::formatDescription(),
            self::ordersTableMap(),
            Build::rows(EventType::DELETE_ROWS_V2, self::TABLE_ID, 3, [true, true, true], [
                Build::rowImage([false, false, false], self::orderValues(9, 'GONE')),
            ]),
        ]);

        $change = $changes[0];
        self::assertSame(ChangeKind::DELETE, $change->kind);
        self::assertSame([], $change->after);
        self::assertSame('GONE', $change->before['sku']);
        // A delete's surviving row is its before image.
        self::assertSame('GONE', $change->row()['sku']);
        self::assertSame(['id' => 9], $change->primaryKey);
    }

    public function testDecodesMultipleRowsFromASingleEvent(): void
    {
        $reader = new EventReader();

        $changes = self::drain($reader, [
            Build::formatDescription(),
            self::ordersTableMap(),
            Build::rows(EventType::WRITE_ROWS_V2, self::TABLE_ID, 3, [true, true, true], [
                Build::rowImage([false, false, false], self::orderValues(1, 'A')),
                Build::rowImage([false, false, false], self::orderValues(2, 'B')),
                Build::rowImage([false, false, false], self::orderValues(3, 'C')),
            ]),
        ]);

        self::assertCount(3, $changes);
        self::assertSame(['A', 'B', 'C'], array_map(static fn(ChangeEvent $c): mixed => $c->after['sku'], $changes));
    }

    public function testNullColumnsAreReportedAsNullAndConsumeNoBytes(): void
    {
        $reader = new EventReader();

        $changes = self::drain($reader, [
            Build::formatDescription(),
            self::ordersTableMap(),
            Build::rows(EventType::WRITE_ROWS_V2, self::TABLE_ID, 3, [true, true, true], [
                // sku is NULL, so its value is absent from the image entirely.
                Build::rowImage([false, true, false], pack('V', 5) . "\x80\x04\xD2\x16\x2E"),
            ]),
        ]);

        self::assertSame(['id' => 5, 'sku' => null, 'total' => '1234.5678'], $changes[0]->after);
    }

    public function testPartialRowImagesDecodeOnlyThePresentColumns(): void
    {
        $reader = new EventReader();

        $changes = self::drain($reader, [
            Build::formatDescription(),
            self::ordersTableMap(),
            // binlog_row_image = MINIMAL logs only some columns.
            Build::rows(EventType::WRITE_ROWS_V2, self::TABLE_ID, 3, [true, false, false], [
                Build::rowImage([false], pack('V', 77)),
            ]),
        ]);

        self::assertSame(['id' => 77], $changes[0]->after);
    }

    public function testColumnsFallBackToPositionalKeysWithoutFullRowMetadata(): void
    {
        $reader = new EventReader();

        $changes = self::drain($reader, [
            Build::formatDescription(),
            // No names: the server is running binlog_row_metadata = MINIMAL.
            Build::tableMap(self::TABLE_ID, 'shop', 'orders', [ColumnType::LONG], [0]),
            Build::rows(EventType::WRITE_ROWS_V2, self::TABLE_ID, 1, [true], [
                Build::rowImage([false], pack('V', 3)),
            ]),
        ]);

        self::assertSame(['@0' => 3], $changes[0]->after);
        self::assertSame([], $changes[0]->primaryKey);
    }

    public function testEnumLabelsFromOptionalMetadataAreApplied(): void
    {
        $reader = new EventReader();

        $map = Build::tableMap(
            self::TABLE_ID,
            'shop',
            'orders',
            [ColumnType::STRING],
            [(ColumnType::ENUM->value << 8) | 1],
            [false],
            ['status'],
            [],
            [0 => ['draft', 'paid', 'shipped']],
        );

        $changes = self::drain($reader, [
            Build::formatDescription(),
            $map,
            Build::rows(EventType::WRITE_ROWS_V2, self::TABLE_ID, 1, [true], [
                Build::rowImage([false], "\x02"),
            ]),
        ]);

        self::assertSame(['status' => 'paid'], $changes[0]->after);
    }

    public function testTableFilterDropsUnsubscribedTables(): void
    {
        $reader = new EventReader(new Checkpoint(), ['shop.customers']);

        $changes = self::drain($reader, [
            Build::formatDescription(),
            self::ordersTableMap(),
            Build::rows(EventType::WRITE_ROWS_V2, self::TABLE_ID, 3, [true, true, true], [
                Build::rowImage([false, false, false], self::orderValues(1, 'SKU-1')),
            ]),
        ]);

        self::assertSame([], $changes);
    }

    public function testTableFilterAcceptsWholeSchemaWildcards(): void
    {
        $reader = new EventReader(new Checkpoint(), ['shop.*']);

        $changes = self::drain($reader, [
            Build::formatDescription(),
            self::ordersTableMap(),
            Build::rows(EventType::WRITE_ROWS_V2, self::TABLE_ID, 3, [true, true, true], [
                Build::rowImage([false, false, false], self::orderValues(1, 'SKU-1')),
            ]),
        ]);

        self::assertCount(1, $changes);
    }

    public function testBeginDoesNotInvalidateTheTableMapButDdlDoes(): void
    {
        $reader = new EventReader();

        $rows = Build::rows(EventType::WRITE_ROWS_V2, self::TABLE_ID, 3, [true, true, true], [
            Build::rowImage([false, false, false], self::orderValues(1, 'SKU-1')),
        ]);

        // BEGIN sits between the map and the rows in every real transaction.
        self::assertCount(1, self::drain($reader, [
            Build::formatDescription(),
            self::ordersTableMap(),
            Build::query('shop', 'BEGIN'),
            $rows,
        ]));

        // DDL can change the column layout, so cached maps must be dropped.
        $this->expectException(MalformedBinlogPacket::class);

        self::drain($reader, [
            Build::query('shop', 'ALTER TABLE orders ADD COLUMN note TEXT'),
            $rows,
        ]);
    }

    public function testRowsWithoutATableMapFailRatherThanDecodeGarbage(): void
    {
        $reader = new EventReader();

        $this->expectException(MalformedBinlogPacket::class);

        self::drain($reader, [
            Build::formatDescription(),
            Build::rows(EventType::WRITE_ROWS_V2, self::TABLE_ID, 3, [true, true, true], [
                Build::rowImage([false, false, false], self::orderValues(1, 'SKU-1')),
            ]),
        ]);
    }

    public function testRotateSwitchesTheCheckpointFile(): void
    {
        $reader = new EventReader();

        self::drain($reader, [Build::rotate('binlog.000004'), Build::rotate('binlog.000005', 4)]);

        self::assertSame('binlog.000005', $reader->checkpoint()->file);
    }

    public function testUnknownEventTypesAreSkippedNotFatal(): void
    {
        $reader = new EventReader();

        // Event type 99 does not exist today; a newer server must not stall us.
        self::assertSame([], $reader->consume(
            pack('V', 1) . chr(99) . pack('V', 1) . pack('V', 23) . pack('V', 500) . pack('v', 0) . 'abcd',
        ));

        self::assertSame(500, $reader->checkpoint()->position);
    }

    public function testCompressedTransactionsAreRefusedWithAnActionableError(): void
    {
        $reader = new EventReader();

        $this->expectException(UnsupportedBinlogFeature::class);
        $this->expectExceptionMessageMatches('/binlog_transaction_compression/');

        $reader->consume(Build::event(EventType::TRANSACTION_PAYLOAD, 'compressed'));
    }

    public function testPartialJsonUpdatesAreRefusedRatherThanMisdecoded(): void
    {
        $reader = new EventReader();

        $this->expectException(UnsupportedBinlogFeature::class);
        $this->expectExceptionMessageMatches('/binlog_row_value_options/');

        $reader->consume(Build::event(EventType::PARTIAL_UPDATE_ROWS, 'partial'));
    }

    public function testChecksumTailIsStrippedWhenTheServerUsesCrc32(): void
    {
        $reader = new EventReader();
        $reader->useChecksum(true);

        $map = self::ordersTableMap();
        $rows = Build::rows(EventType::WRITE_ROWS_V2, self::TABLE_ID, 3, [true, true, true], [
            Build::rowImage([false, false, false], self::orderValues(1, 'SKU-1')),
        ]);

        // A CRC32 server appends four bytes to every event and counts them in
        // the header's size. Reproduce that by re-stamping the size.
        $changes = self::drain($reader, array_map(self::withChecksum(...), [Build::formatDescription(), $map, $rows]));

        self::assertCount(1, $changes);
        self::assertSame('SKU-1', $changes[0]->after['sku']);
    }

    /**
     * Append a CRC32 tail and grow the header's event size to match.
     */
    private static function withChecksum(string $event): string
    {
        $withTail = $event . pack('V', crc32($event));

        return substr_replace($withTail, pack('V', strlen($withTail)), 9, 4);
    }
}
