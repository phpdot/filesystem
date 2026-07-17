<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Tests\Unit\Write;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Stream;
use PHPdot\Filesystem\Exception\InvalidStreamProvided;
use PHPdot\Filesystem\Write\WriteContents;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WriteContentsTest extends TestCase
{
    private Psr17Factory $factory;
    private WriteContents $writeContents;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
        $this->writeContents = new WriteContents($this->factory);
    }

    public function testNormalizesString(): void
    {
        $stream = $this->writeContents->normalize('hello');

        self::assertTrue($stream->isReadable());
        self::assertSame('hello', $stream->getContents());
    }

    public function testReturnsStreamAsIsButRewound(): void
    {
        $stream = $this->factory->createStream('content');
        $stream->seek(3);

        $normalized = $this->writeContents->normalize($stream);

        self::assertSame($stream, $normalized);
        self::assertSame(0, $normalized->tell());
        self::assertSame('content', $normalized->getContents());
    }

    public function testNormalizesUploadedFile(): void
    {
        $inner = $this->factory->createStream('uploaded-bytes');
        $uploaded = $this->factory->createUploadedFile($inner);

        $normalized = $this->writeContents->normalize($uploaded);

        self::assertSame('uploaded-bytes', $normalized->getContents());
    }

    public function testThrowsOnNonReadableStream(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'phpdot-fs-');
        if ($path === false) {
            throw new RuntimeException('Unable to create a temp file for the test.');
        }

        // Append mode is writable but not readable, giving a genuinely
        // non-readable PSR-7 stream (php://temp would still report readable).
        $resource = fopen($path, 'a');
        if (!is_resource($resource)) {
            @unlink($path);

            throw new RuntimeException('Unable to open an append-only temp stream for the test.');
        }

        $writeOnly = Stream::create($resource);
        self::assertFalse($writeOnly->isReadable());

        try {
            $this->expectException(InvalidStreamProvided::class);
            $this->writeContents->normalize($writeOnly);
        } finally {
            $writeOnly->close();
            @unlink($path);
        }
    }
}
