<?php

declare(strict_types=1);

namespace PHPdot\Filesystem\Tests\Unit\Realtime\Protocol;

use PHPdot\Filesystem\Exception\MalformedBinlogPacket;
use PHPdot\Filesystem\Realtime\Protocol\PacketReader;
use PHPdot\Filesystem\Realtime\Protocol\PacketWriter;
use PHPUnit\Framework\TestCase;

final class PacketReaderTest extends TestCase
{
    public function testReadsLittleEndianUnsignedIntegers(): void
    {
        $reader = new PacketReader("\x01\x02\x03\x04\x05\x06\x07\x08");

        self::assertSame(0x01, $reader->uint8());
        self::assertSame(0x0302, $reader->uint16());
        self::assertSame(0x060504, $reader->uint24());
        self::assertSame(2, $reader->remaining());
    }

    public function testReadsSignedIntegersWithSignExtension(): void
    {
        self::assertSame(-1, (new PacketReader("\xFF"))->int8());
        self::assertSame(-1, (new PacketReader("\xFF\xFF"))->int16());
        self::assertSame(-1, (new PacketReader("\xFF\xFF\xFF"))->int24());
        self::assertSame(-1, (new PacketReader("\xFF\xFF\xFF\xFF"))->int32());
        self::assertSame(127, (new PacketReader("\x7F"))->int8());
        self::assertSame(-128, (new PacketReader("\x80"))->int8());
    }

    public function testUint64AboveIntMaxRendersAsAnExactDecimalString(): void
    {
        // 0xFFFFFFFFFFFFFFFF is PHP_INT_MAX * 2 + 1, which cannot be held in a
        // PHP int — a BIGINT UNSIGNED column really can reach this.
        self::assertSame('18446744073709551615', (new PacketReader(str_repeat("\xFF", 8)))->uint64String());
        self::assertSame('42', (new PacketReader("\x2A\0\0\0\0\0\0\0"))->uint64String());
    }

    public function testReadsBigEndianIntegers(): void
    {
        self::assertSame(0x010203, (new PacketReader("\x01\x02\x03"))->unsignedBigEndian(3));
        self::assertSame(0x030201, (new PacketReader("\x01\x02\x03"))->unsignedLittleEndian(3));
    }

    public function testReadsLengthEncodedIntegersAcrossEveryWidth(): void
    {
        self::assertSame(10, (new PacketReader("\x0A"))->lengthEncodedInt());
        self::assertNull((new PacketReader("\xFB"))->lengthEncodedInt());
        self::assertSame(0x0201, (new PacketReader("\xFC\x01\x02"))->lengthEncodedInt());
        self::assertSame(0x030201, (new PacketReader("\xFD\x01\x02\x03"))->lengthEncodedInt());
        self::assertSame(1, (new PacketReader("\xFE\x01\0\0\0\0\0\0\0"))->lengthEncodedInt());
    }

    public function testReadsStrings(): void
    {
        $reader = new PacketReader("\x05hello" . "world\0rest");

        self::assertSame('hello', $reader->lengthEncodedString());
        self::assertSame('world', $reader->nullTerminatedString());
        self::assertSame('rest', $reader->rest());
        self::assertTrue($reader->eof());
    }

    public function testUnterminatedStringConsumesTheRemainder(): void
    {
        self::assertSame('tail', (new PacketReader('tail'))->nullTerminatedString());
    }

    public function testBitmapIsLeastSignificantBitFirst(): void
    {
        self::assertSame(
            [true, false, true, false, false, false, false, false, true],
            (new PacketReader("\x05\x01"))->bitmap(9),
        );
    }

    public function testReadingPastTheEndThrowsRatherThanReturningJunk(): void
    {
        $reader = new PacketReader("\x01\x02");

        $this->expectException(MalformedBinlogPacket::class);

        $reader->uint32();
    }

    public function testPeekDoesNotAdvance(): void
    {
        $reader = new PacketReader("\x07");

        self::assertSame(7, $reader->peekUInt8());
        self::assertSame(7, $reader->uint8());
    }

    public function testRoundTripsEverythingTheWriterProduces(): void
    {
        $writer = new PacketWriter();
        $writer->uint8(0x12)
            ->uint16(0x3456)
            ->uint32(0x789ABCDE)
            ->lengthEncodedInt(300)
            ->lengthEncodedString('phpdot')
            ->nullTerminatedString('realtime')
            ->filler(3);

        $reader = new PacketReader($writer->toString());

        self::assertSame(0x12, $reader->uint8());
        self::assertSame(0x3456, $reader->uint16());
        self::assertSame(0x789ABCDE, $reader->uint32());
        self::assertSame(300, $reader->lengthEncodedInt());
        self::assertSame('phpdot', $reader->lengthEncodedString());
        self::assertSame('realtime', $reader->nullTerminatedString());
        self::assertSame("\0\0\0", $reader->rest());
    }

    public function testFloatsRoundTrip(): void
    {
        self::assertEqualsWithDelta(1.5, (new PacketReader(pack('g', 1.5)))->float32(), 0.0001);
        self::assertSame(1.25, (new PacketReader(pack('e', 1.25)))->float64());
    }
}
