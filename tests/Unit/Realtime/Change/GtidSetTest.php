<?php

declare(strict_types=1);

namespace PHPdot\Filesystem\Tests\Unit\Realtime\Change;

use PHPdot\Filesystem\Realtime\Change\GtidSet;
use PHPdot\Filesystem\Realtime\Protocol\PacketReader;
use PHPUnit\Framework\TestCase;

final class GtidSetTest extends TestCase
{
    private const UUID = '3e11fa47-71ca-11e1-9e33-c80aa9429562';

    public function testParsesAndRendersMysqlTextForm(): void
    {
        self::assertSame(self::UUID . ':1-5:8', GtidSet::parse(self::UUID . ':1-5:8')->toString());
    }

    public function testParsingIsCaseInsensitiveAndToleratesWrappedWhitespace(): void
    {
        // @@GLOBAL.gtid_executed wraps long sets across lines.
        $set = GtidSet::parse(strtoupper(self::UUID) . ":1-5,\n " . self::UUID . ':7');

        self::assertSame(self::UUID . ':1-5:7', $set->toString());
    }

    public function testAdjacentTransactionsMergeIntoOneInterval(): void
    {
        self::assertSame(self::UUID . ':1-6', GtidSet::parse(self::UUID . ':1-5')->with(self::UUID, 6)->toString());
    }

    public function testAGapLeavesTwoIntervals(): void
    {
        self::assertSame(self::UUID . ':1-5:8', GtidSet::parse(self::UUID . ':1-5')->with(self::UUID, 8)->toString());
    }

    public function testAddingIntoAGapClosesIt(): void
    {
        $set = GtidSet::parse(self::UUID . ':1-5:8')->with(self::UUID, 6)->with(self::UUID, 7);

        self::assertSame(self::UUID . ':1-8', $set->toString());
    }

    public function testReAddingAKnownTransactionIsIdempotent(): void
    {
        self::assertSame(self::UUID . ':1-5', GtidSet::parse(self::UUID . ':1-5')->with(self::UUID, 3)->toString());
    }

    public function testEmptySetsAreRecognised(): void
    {
        self::assertTrue(GtidSet::empty()->isEmpty());
        self::assertTrue(GtidSet::parse('')->isEmpty());
        self::assertSame('', GtidSet::parse('')->toString());
    }

    public function testBinaryEncodingUsesExclusiveIntervalEnds(): void
    {
        // Text says 1-5 inclusive; the wire says [1, 6).
        $reader = new PacketReader(GtidSet::parse(self::UUID . ':1-5')->toBinary());

        self::assertSame(1, $reader->uint64());
        self::assertSame(self::UUID, self::formatUuid($reader->take(16)));
        self::assertSame(1, $reader->uint64());
        self::assertSame(1, $reader->uint64());
        self::assertSame(6, $reader->uint64());
        self::assertTrue($reader->eof());
    }

    public function testBinaryEncodingCoversEverySourceAndInterval(): void
    {
        $other = '1234abcd-0000-0000-0000-000000000000';
        $reader = new PacketReader(GtidSet::parse(self::UUID . ':1-5:8,' . $other . ':1')->toBinary());

        self::assertSame(2, $reader->uint64());
        // Sources are emitted in sorted order, so the numeric UUID comes first.
        self::assertSame($other, self::formatUuid($reader->take(16)));
        self::assertSame(1, $reader->uint64());
        $reader->skip(16);
        self::assertSame(self::UUID, self::formatUuid($reader->take(16)));
        self::assertSame(2, $reader->uint64());
    }

    private static function formatUuid(string $packed): string
    {
        $hex = bin2hex($packed);

        return implode('-', [
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        ]);
    }
}
