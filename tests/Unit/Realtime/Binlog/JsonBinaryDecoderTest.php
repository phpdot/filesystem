<?php

declare(strict_types=1);

namespace PHPdot\Filesystem\Tests\Unit\Realtime\Binlog;

use PHPdot\Filesystem\Exception\MalformedBinlogPacket;
use PHPdot\Filesystem\Realtime\Binlog\JsonBinaryDecoder;
use PHPUnit\Framework\TestCase;

/**
 * MySQL stores JSON columns as a random-access tree, not as text. These vectors
 * are hand-assembled from that layout.
 */
final class JsonBinaryDecoderTest extends TestCase
{
    private static function bytes(string $hex): string
    {
        $binary = hex2bin(str_replace(' ', '', $hex));

        self::assertIsString($binary);

        return $binary;
    }

    public function testAnEmptyPayloadIsSqlNull(): void
    {
        self::assertNull(JsonBinaryDecoder::decode(''));
    }

    public function testSmallObjectWithAnInlinedInteger(): void
    {
        // {"a": 1} — the int16 lives inside its entry slot rather than out of line.
        self::assertSame(['a' => 1], JsonBinaryDecoder::decode(self::bytes('00 0100 0C00 0B00 0100 05 0100 61')));
    }

    public function testSmallArrayOfInlinedLiterals(): void
    {
        self::assertSame([true, null], JsonBinaryDecoder::decode(self::bytes('02 0200 0A00 04 0100 04 0000')));
    }

    public function testStringsAreStoredOutOfLineBehindAVarint(): void
    {
        self::assertSame(['a' => 'hi'], JsonBinaryDecoder::decode(self::bytes('00 0100 0F00 0B00 0100 0C 0C00 61 02 6869')));
    }

    public function testFalseLiteral(): void
    {
        self::assertSame([false], JsonBinaryDecoder::decode(self::bytes('02 0100 0700 04 0200')));
    }

    public function testNestedContainersResolveRelativeOffsets(): void
    {
        // [[1]] — the inner array's own offsets are relative to itself, not to
        // the start of the document.
        $inner = self::bytes('0100 0700 05 0100');
        $outer = self::bytes('0100') . pack('v', 4 + 3 + strlen($inner)) . self::bytes('02') . pack('v', 7) . $inner;

        self::assertSame([[1]], JsonBinaryDecoder::decode("\x02" . $outer));
    }

    public function testResultsSurviveJsonEncode(): void
    {
        $decoded = JsonBinaryDecoder::decode(self::bytes('00 0100 0C00 0B00 0100 05 0100 61'));

        self::assertSame('{"a":1}', json_encode($decoded));
    }

    public function testAnUnknownValueTypeIsRejected(): void
    {
        $this->expectException(MalformedBinlogPacket::class);

        JsonBinaryDecoder::decode("\x7F\x00");
    }

    public function testATruncatedPayloadIsRejectedRatherThanReadPastTheEnd(): void
    {
        $this->expectException(MalformedBinlogPacket::class);

        JsonBinaryDecoder::decode(self::bytes('00 0100'));
    }
}
