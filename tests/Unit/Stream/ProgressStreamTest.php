<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Tests\Unit\Stream;

use Closure;
use Nyholm\Psr7\Stream;
use PHPdot\Filesystem\Stream\ProgressStream;
use PHPUnit\Framework\TestCase;

final class ProgressStreamTest extends TestCase
{
    /**
     * @var list<array{int,?int}>
     */
    private array $events = [];

    public function testCountsAcrossIncrementalReads(): void
    {
        $inner = Stream::create('hello world');
        $inner->rewind();
        $progress = new ProgressStream($inner, $this->recorder(), 11);

        self::assertSame('hello', $progress->read(5));
        self::assertSame(' worl', $progress->read(5));
        self::assertSame('d', $progress->read(5));

        self::assertSame([[5, 11], [10, 11], [11, 11]], $this->events);
        self::assertSame(11, $progress->bytesSeen());
    }

    public function testCountsWhenBodyPulledViaGetContents(): void
    {
        $inner = Stream::create('abcdef');
        $inner->rewind();
        $progress = new ProgressStream($inner, $this->recorder(), 6);

        self::assertSame('abcdef', $progress->getContents());
        self::assertSame([[6, 6]], $this->events);
    }

    public function testCountsWhenBodyStringified(): void
    {
        $inner = Stream::create('payload');
        $progress = new ProgressStream($inner, $this->recorder(), 7);

        self::assertSame('payload', (string)$progress);
        self::assertSame([[7, 7]], $this->events);
    }

    public function testNullableTotalIsForwarded(): void
    {
        $inner = Stream::create('xyz');
        $inner->rewind();
        $progress = new ProgressStream($inner, $this->recorder());

        $progress->read(3);

        self::assertSame([[3, null]], $this->events);
    }

    public function testDelegatesStreamMethodsToInner(): void
    {
        $inner = Stream::create('delegated');
        $inner->rewind();
        $progress = new ProgressStream($inner, $this->recorder());

        self::assertSame(9, $progress->getSize());
        self::assertTrue($progress->isReadable());
        self::assertTrue($progress->isSeekable());
        self::assertFalse($progress->eof());

        $progress->seek(4);
        self::assertSame(4, $progress->tell());
        $progress->rewind();
        self::assertSame(0, $progress->tell());
    }

    private function recorder(): Closure
    {
        return function (int $soFar, ?int $total): void {
            $this->events[] = [$soFar, $total];
        };
    }
}
