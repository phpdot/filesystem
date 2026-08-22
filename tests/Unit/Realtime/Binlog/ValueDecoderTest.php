<?php

declare(strict_types=1);

namespace PHPdot\Filesystem\Tests\Unit\Realtime\Binlog;

use PHPdot\Filesystem\Exception\UnsupportedBinlogFeature;
use PHPdot\Filesystem\Realtime\Binlog\ColumnType;
use PHPdot\Filesystem\Realtime\Binlog\ValueDecoder;
use PHPdot\Filesystem\Realtime\Protocol\PacketReader;
use PHPUnit\Framework\TestCase;

/**
 * Byte-level vectors for MySQL's column encodings.
 *
 * The inputs are hand-derived from the documented layouts rather than produced
 * by an encoder of ours, so a decoder that is self-consistently wrong still
 * fails here.
 */
final class ValueDecoderTest extends TestCase
{
    /**
     * Decode.
     *
     * @param list<string> $labels
     */
    private static function decode(string $bytes, ColumnType $type, int $metadata = 0, bool $unsigned = false, array $labels = []): mixed
    {
        return ValueDecoder::decode(new PacketReader($bytes), $type, $metadata, $unsigned, $labels);
    }

    public function testSignedAndUnsignedIntegersDifferOnTheSameBytes(): void
    {
        self::assertSame(-1, self::decode("\xFF", ColumnType::TINY));
        self::assertSame(255, self::decode("\xFF", ColumnType::TINY, unsigned: true));
        self::assertSame(-1, self::decode("\xFF\xFF\xFF\xFF", ColumnType::LONG));
        self::assertSame(4294967295, self::decode("\xFF\xFF\xFF\xFF", ColumnType::LONG, unsigned: true));
    }

    public function testBigintUnsignedBeyondIntMaxBecomesAnExactString(): void
    {
        $bytes = str_repeat("\xFF", 8);

        self::assertSame(-1, self::decode($bytes, ColumnType::LONGLONG));
        self::assertSame('18446744073709551615', self::decode($bytes, ColumnType::LONGLONG, unsigned: true));
    }

    public function testDecimalKeepsExactDigitsRatherThanBecomingAFloat(): void
    {
        // DECIMAL(10,4) = 1234.5678. Sign bit set in the leading byte; the
        // integral group is 3 bytes big-endian, the fractional group 2.
        self::assertSame('1234.5678', self::decode("\x80\x04\xD2\x16\x2E", ColumnType::NEWDECIMAL, 0x040A));
    }

    public function testNegativeDecimalIsStoredInverted(): void
    {
        // The same value negated: every byte is the one's complement.
        self::assertSame('-1234.5678', self::decode("\x7F\xFB\x2D\xE9\xD1", ColumnType::NEWDECIMAL, 0x040A));
    }

    public function testDecimalWithFullNineDigitGroups(): void
    {
        // DECIMAL(18,9) = 123456789.123456789 — one whole group each side.
        self::assertSame(
            '123456789.123456789',
            self::decode("\x87\x5B\xCD\x15\x07\x5B\xCD\x15", ColumnType::NEWDECIMAL, 0x0912),
        );
    }

    public function testDecimalKeepsLeadingZerosInTheFraction(): void
    {
        // DECIMAL(10,4) = 0.0001: the fractional group must not lose its padding.
        self::assertSame('0.0001', self::decode("\x80\x00\x00\x00\x01", ColumnType::NEWDECIMAL, 0x040A));
    }

    public function testDateUnpacksTheYearMonthDayBitfield(): void
    {
        self::assertSame('2026-08-22', self::decode("\x16\xD5\x0F", ColumnType::DATE));
    }

    public function testDateTime2UnpacksFortyBitsOfPackedFields(): void
    {
        self::assertSame('2026-08-22 13:45:01', self::decode("\x99\xBA\xAC\xDB\x41", ColumnType::DATETIME2));
    }

    public function testDateTime2CarriesFractionalSeconds(): void
    {
        self::assertSame(
            '2026-08-22 13:45:01.123',
            self::decode("\x99\xBA\xAC\xDB\x41\x04\xCE", ColumnType::DATETIME2, 3),
        );
    }

    public function testTime2HandlesBothSigns(): void
    {
        self::assertSame('13:45:01', self::decode("\x80\xDB\x41", ColumnType::TIME2));
        self::assertSame('-13:45:01', self::decode("\x7F\x24\xBF", ColumnType::TIME2));
    }

    public function testTimestampRendersAsUtcBecauseThatIsHowItIsStored(): void
    {
        // TIMESTAMP2 stores epoch seconds big-endian; plain TIMESTAMP stores
        // them little-endian. Same instant, opposite byte order.
        self::assertSame('2025-12-17 19:33:20', self::decode(pack('N', 1_766_000_000), ColumnType::TIMESTAMP2, 0));
        self::assertSame('2025-12-17 19:33:20', self::decode(pack('V', 1_766_000_000), ColumnType::TIMESTAMP));
    }

    public function testYearIsOffsetFromNineteenHundred(): void
    {
        self::assertSame(2026, self::decode("\x7E", ColumnType::YEAR));
        self::assertSame(0, self::decode("\x00", ColumnType::YEAR));
    }

    public function testLegacyTemporalsAreDecimalDigitsNotBitfields(): void
    {
        // Pre-5.6 TIME 13:45:01 is literally the integer 134501.
        self::assertSame('13:45:01', self::decode(substr(pack('V', 134_501), 0, 3), ColumnType::TIME));
        self::assertSame(
            '2026-08-22 13:45:01',
            self::decode(pack('P', 20_260_822_134_501), ColumnType::DATETIME),
        );
    }

    public function testVarcharPrefixWidthFollowsTheDeclaredMaximumNotTheValue(): void
    {
        // maxLength <= 255 means a one-byte prefix even for a short value...
        self::assertSame('hi', self::decode("\x02hi", ColumnType::VARCHAR, 64));
        // ...and a two-byte prefix once the column is declared wider.
        self::assertSame('hi', self::decode("\x02\x00hi", ColumnType::VARCHAR, 300));
    }

    public function testBlobLengthPrefixWidthComesFromMetadata(): void
    {
        self::assertSame('abc', self::decode("\x03abc", ColumnType::BLOB, 1));
        self::assertSame('abc', self::decode("\x03\x00\x00\x00abc", ColumnType::BLOB, 4));
    }

    public function testEnumResolvesToItsLabel(): void
    {
        $labels = ['draft', 'published', 'archived'];

        self::assertSame('published', self::decode("\x02", ColumnType::ENUM, 1, labels: $labels));
        // Index zero is MySQL's invalid-value slot, not the first label.
        self::assertSame('', self::decode("\x00", ColumnType::ENUM, 1, labels: $labels));
    }

    public function testEnumArrivingDisguisedAsStringIsStillResolved(): void
    {
        // MySQL logs ENUM columns as STRING with the real type in the high byte.
        $metadata = (ColumnType::ENUM->value << 8) | 1;

        self::assertSame('archived', self::decode("\x03", ColumnType::STRING, $metadata, labels: ['draft', 'published', 'archived']));
    }

    public function testSetIsABitfieldOverTheLabels(): void
    {
        $labels = ['read', 'write', 'admin'];

        self::assertSame(['read', 'admin'], self::decode("\x05", ColumnType::SET, 1, labels: $labels));
        self::assertSame([], self::decode("\x00", ColumnType::SET, 1, labels: $labels));
    }

    public function testBitWidthComesFromBothMetadataBytes(): void
    {
        // metadata high byte = whole bytes, low byte = leftover bits.
        self::assertSame(0x0102, self::decode("\x01\x02", ColumnType::BIT, 0x0200));
        self::assertSame(0b101, self::decode("\x05", ColumnType::BIT, 0x0003));
    }

    public function testJsonColumnsDecodeToPhpValues(): void
    {
        $json = "\x00\x01\x00\x0C\x00\x0B\x00\x01\x00\x05\x01\x00a";

        self::assertSame(['a' => 1], self::decode(chr(strlen($json)) . $json, ColumnType::JSON, 1));
    }

    public function testAnUndecodableColumnFailsLoudlyRatherThanSilently(): void
    {
        $this->expectException(UnsupportedBinlogFeature::class);

        self::decode("\x00", ColumnType::NEWDATE);
    }

    public function testConsecutiveValuesLeaveTheCursorInTheRightPlace(): void
    {
        // Row images have no delimiters, so a mis-sized read corrupts the rest
        // of the row rather than failing.
        $reader = new PacketReader("\x2A" . "\x02hi" . "\x16\xD5\x0F" . "\xFF");

        self::assertSame(42, ValueDecoder::decode($reader, ColumnType::TINY, 0, true));
        self::assertSame('hi', ValueDecoder::decode($reader, ColumnType::VARCHAR, 64, false));
        self::assertSame('2026-08-22', ValueDecoder::decode($reader, ColumnType::DATE, 0, false));
        self::assertSame(255, ValueDecoder::decode($reader, ColumnType::TINY, 0, true));
        self::assertTrue($reader->eof());
    }
}
