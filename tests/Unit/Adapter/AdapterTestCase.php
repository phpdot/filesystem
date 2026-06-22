<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Tests\Unit\Adapter;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPdot\Filesystem\Config;
use PHPdot\Filesystem\Contract\AdapterInterface;
use PHPdot\Filesystem\Contract\StorageAttributes;
use PHPdot\Filesystem\Exception\UnableToCopyFile;
use PHPdot\Filesystem\Exception\UnableToMoveFile;
use PHPdot\Filesystem\Exception\UnableToReadFile;
use PHPdot\Filesystem\Exception\UnableToRetrieveMetadata;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

/**
 * The behavioral contract every adapter must satisfy.
 *
 * Designed around S3's constraints — directories are implicit, visibility is
 * optional — so the same suite holds for Local, InMemory and S3. Backends that
 * genuinely diverge override the capability hooks.
 */
abstract class AdapterTestCase extends TestCase
{
    protected AdapterInterface $adapter;
    private ?StreamFactoryInterface $streams = null;

    abstract protected function createAdapter(): AdapterInterface;

    protected function setUp(): void
    {
        $this->adapter = $this->createAdapter();
    }

    /** Whether an empty directory can exist on its own (false for S3). */
    protected function supportsEmptyDirectories(): bool
    {
        return true;
    }

    /** Whether visibility is meaningful and round-trips (false for ACL-less S3). */
    protected function supportsVisibility(): bool
    {
        return true;
    }

    // ---- write / read ----

    public function testWriteAndReadRoundTrip(): void
    {
        $this->writeString('a/b/file.txt', 'the contents');

        self::assertTrue($this->adapter->fileExists('a/b/file.txt'));
        self::assertSame('the contents', $this->adapter->read('a/b/file.txt'));
    }

    public function testWriteAndReadStream(): void
    {
        $this->writeString('stream.txt', 'streamed bytes');

        self::assertSame('streamed bytes', $this->adapter->readStream('stream.txt')->getContents());
    }

    public function testOverwriteReplacesSilently(): void
    {
        $this->writeString('over.txt', 'first');
        $this->writeString('over.txt', 'second');

        self::assertSame('second', $this->adapter->read('over.txt'));
    }

    public function testFileExistsIsFalseForMissingFile(): void
    {
        self::assertFalse($this->adapter->fileExists('nope.txt'));
    }

    public function testFileExistsIsFalseForDirectoryPath(): void
    {
        $this->writeString('dir/child.txt', 'x');

        self::assertFalse($this->adapter->fileExists('dir'));
    }

    public function testReadingMissingFileThrows(): void
    {
        $this->expectException(UnableToReadFile::class);

        $this->adapter->read('missing.txt');
    }

    // ---- metadata ----

    public function testFileSizeReflectsContent(): void
    {
        $this->writeString('size.txt', 'hello');

        self::assertSame(5, $this->adapter->fileSize('size.txt')->fileSize());
    }

    public function testLastModifiedIsPositive(): void
    {
        $this->writeString('time.txt', 'x');

        self::assertGreaterThan(0, $this->adapter->lastModified('time.txt')->lastModified());
    }

    public function testMimeTypeIsDetected(): void
    {
        $this->writeString('doc.txt', "plain text content\n");

        $mime = $this->adapter->mimeType('doc.txt')->mimeType();
        self::assertNotNull($mime);
        self::assertStringStartsWith('text/', $mime);
    }

    public function testFileSizeOfMissingFileThrows(): void
    {
        $this->expectException(UnableToRetrieveMetadata::class);

        $this->adapter->fileSize('missing.txt');
    }

    public function testMimeTypeOfMissingFileThrows(): void
    {
        $this->expectException(UnableToRetrieveMetadata::class);

        $this->adapter->mimeType('missing.txt');
    }

    public function testLastModifiedOfMissingFileThrows(): void
    {
        $this->expectException(UnableToRetrieveMetadata::class);

        $this->adapter->lastModified('missing.txt');
    }

    // ---- delete / copy / move ----

    public function testDelete(): void
    {
        $this->writeString('del.txt', 'x');
        $this->adapter->delete('del.txt');

        self::assertFalse($this->adapter->fileExists('del.txt'));
    }

    public function testDeleteIsIdempotent(): void
    {
        $this->adapter->delete('never-existed.txt');

        self::assertFalse($this->adapter->fileExists('never-existed.txt'));
    }

    public function testCopyDuplicatesContent(): void
    {
        $this->writeString('src.txt', 'payload');
        $this->adapter->copy('src.txt', 'dst.txt', new Config());

        self::assertSame('payload', $this->adapter->read('src.txt'));
        self::assertSame('payload', $this->adapter->read('dst.txt'));
    }

    public function testCopyMissingSourceThrows(): void
    {
        $this->expectException(UnableToCopyFile::class);

        $this->adapter->copy('missing.txt', 'dst.txt', new Config());
    }

    public function testMove(): void
    {
        $this->writeString('from.txt', 'payload');
        $this->adapter->move('from.txt', 'to.txt', new Config());

        self::assertFalse($this->adapter->fileExists('from.txt'));
        self::assertSame('payload', $this->adapter->read('to.txt'));
    }

    public function testMoveMissingSourceThrows(): void
    {
        $this->expectException(UnableToMoveFile::class);

        $this->adapter->move('missing.txt', 'to.txt', new Config());
    }

    // ---- directories (implicit) ----

    public function testImplicitDirectoryExistsForWrittenFile(): void
    {
        $this->writeString('nested/deep/file.txt', 'x');

        self::assertTrue($this->adapter->directoryExists('nested'));
        self::assertTrue($this->adapter->directoryExists('nested/deep'));
        self::assertFalse($this->adapter->directoryExists('nested/missing'));
    }

    public function testDeleteDirectoryRemovesEverythingUnderPrefix(): void
    {
        $this->writeString('tree/1.txt', 'a');
        $this->writeString('tree/2.txt', 'b');
        $this->writeString('tree/sub/3.txt', 'c');
        $this->writeString('keep.txt', 'd');

        $this->adapter->deleteDirectory('tree');

        self::assertFalse($this->adapter->fileExists('tree/1.txt'));
        self::assertFalse($this->adapter->fileExists('tree/2.txt'));
        self::assertFalse($this->adapter->fileExists('tree/sub/3.txt'));
        self::assertFalse($this->adapter->directoryExists('tree'));
        self::assertTrue($this->adapter->fileExists('keep.txt'));
    }

    public function testListContentsDeepReturnsAllFiles(): void
    {
        $this->writeString('a/1.txt', 'a');
        $this->writeString('a/b/2.txt', 'b');
        $this->writeString('c.txt', 'c');

        self::assertSame(
            ['a/1.txt', 'a/b/2.txt', 'c.txt'],
            $this->filePaths($this->adapter->listContents('', true)),
        );
    }

    public function testListContentsShallowReturnsImmediateChildren(): void
    {
        $this->writeString('a/1.txt', 'a');
        $this->writeString('a/b/2.txt', 'b');

        self::assertSame(['a/1.txt'], $this->filePaths($this->adapter->listContents('a', false)));
        self::assertContains('a/b', $this->directoryPaths($this->adapter->listContents('a', false)));
    }

    public function testCreateAndDetectEmptyDirectory(): void
    {
        if (!$this->supportsEmptyDirectories()) {
            self::markTestSkipped('Adapter does not support standalone empty directories.');
        }

        $this->adapter->createDirectory('empty/dir', new Config());

        self::assertTrue($this->adapter->directoryExists('empty/dir'));
    }

    // ---- visibility ----

    public function testWriteRespectsVisibilityConfig(): void
    {
        if (!$this->supportsVisibility()) {
            self::markTestSkipped('Adapter does not support visibility.');
        }

        $this->adapter->write('pub.txt', $this->stream('x'), new Config([Config::VISIBILITY => 'public']));

        self::assertSame('public', $this->adapter->visibility('pub.txt')->visibility());
    }

    public function testSetVisibilityRoundTrips(): void
    {
        if (!$this->supportsVisibility()) {
            self::markTestSkipped('Adapter does not support visibility.');
        }

        $this->writeString('v.txt', 'data');
        $this->adapter->setVisibility('v.txt', 'public');
        self::assertSame('public', $this->adapter->visibility('v.txt')->visibility());

        $this->adapter->setVisibility('v.txt', 'private');
        self::assertSame('private', $this->adapter->visibility('v.txt')->visibility());
    }

    // ---- helpers ----

    protected function streamFactory(): StreamFactoryInterface
    {
        return $this->streams ??= new Psr17Factory();
    }

    protected function stream(string $contents): StreamInterface
    {
        $stream = $this->streamFactory()->createStream($contents);
        $stream->rewind();

        return $stream;
    }

    /**
     * @param array<string,mixed> $config
     */
    protected function writeString(string $path, string $contents, array $config = []): void
    {
        $this->adapter->write($path, $this->stream($contents), new Config($config));
    }

    /**
     * @param iterable<StorageAttributes> $listing
     *
     * @return list<string>
     */
    private function filePaths(iterable $listing): array
    {
        $paths = [];

        foreach ($listing as $entry) {
            if ($entry->isFile()) {
                $paths[] = $entry->path();
            }
        }

        sort($paths);

        return $paths;
    }

    /**
     * @param iterable<StorageAttributes> $listing
     *
     * @return list<string>
     */
    private function directoryPaths(iterable $listing): array
    {
        $paths = [];

        foreach ($listing as $entry) {
            if ($entry->isDir()) {
                $paths[] = $entry->path();
            }
        }

        sort($paths);

        return $paths;
    }
}
