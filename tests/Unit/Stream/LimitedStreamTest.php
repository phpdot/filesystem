<?php

declare(strict_types=1);

/**
 * A limited stream yields its window of the wrapped stream and nothing beyond, seeks only inside it, and writes never.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Tests\Unit\Stream;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPdot\Filesystem\Exception\StreamOperationFailed;
use PHPdot\Filesystem\Stream\LimitedStream;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LimitedStreamTest extends TestCase
{
    #[Test]
    public function itEndsAfterItsLimitCountedFromWhereTheStreamStood(): void
    {
        $inner = (new Psr17Factory())->createStream('0123456789');
        $inner->seek(2);
        $stream = new LimitedStream($inner, 5);

        self::assertSame('234', $stream->read(3));
        self::assertSame('56', $stream->read(10));
        self::assertTrue($stream->eof());
        self::assertSame('', $stream->read(1));
        self::assertSame(5, $stream->getSize());
    }

    #[Test]
    public function itSeeksOnlyInsideItsWindow(): void
    {
        $stream = new LimitedStream((new Psr17Factory())->createStream('0123456789'), 4);

        $stream->seek(2);
        self::assertSame('23', $stream->getContents());
        self::assertSame('0123', (string) $stream);

        $this->expectException(StreamOperationFailed::class);
        $stream->seek(5);
    }

    #[Test]
    public function itRefusesWrites(): void
    {
        $stream = new LimitedStream((new Psr17Factory())->createStream('abc'), 2);

        self::assertFalse($stream->isWritable());

        $this->expectException(StreamOperationFailed::class);
        $stream->write('x');
    }
}
