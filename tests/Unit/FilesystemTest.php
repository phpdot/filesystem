<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPdot\Filesystem\Adapter\InMemoryAdapter;
use PHPdot\Filesystem\Config;
use PHPdot\Filesystem\Event\UploadCompleted;
use PHPdot\Filesystem\Event\UploadFailed;
use PHPdot\Filesystem\Event\UploadProgressed;
use PHPdot\Filesystem\Exception\PathTraversalDetected;
use PHPdot\Filesystem\Exception\UnableToGeneratePublicUrl;
use PHPdot\Filesystem\Exception\UnableToGenerateTemporaryUrl;
use PHPdot\Filesystem\Exception\UnableToWriteFile;
use PHPdot\Filesystem\Filesystem;
use PHPdot\Filesystem\Write\WriteContents;
use PHPUnit\Framework\TestCase;

final class FilesystemTest extends TestCase
{
    public function testWritesAndReadsStringInput(): void
    {
        $fs = $this->filesystem();
        $fs->write('a.txt', 'hello');

        self::assertSame('hello', $fs->read('a.txt'));
        self::assertTrue($fs->fileExists('a.txt'));
    }

    public function testAcceptsStreamInput(): void
    {
        $fs = $this->filesystem();
        $fs->write('s.txt', (new Psr17Factory())->createStream('stream body'));

        self::assertSame('stream body', $fs->read('s.txt'));
    }

    public function testAcceptsUploadedFileInput(): void
    {
        $factory = new Psr17Factory();
        $uploaded = $factory->createUploadedFile($factory->createStream('uploaded body'));

        $fs = $this->filesystem();
        $fs->write('u.txt', $uploaded);

        self::assertSame('uploaded body', $fs->read('u.txt'));
    }

    public function testProgressCallbackFiresThroughTheRealWrite(): void
    {
        $events = [];
        $fs = $this->filesystem();

        $fs->write('p.txt', 'hello world', [
            Config::PROGRESS => static function (int $soFar, ?int $total) use (&$events): void {
                $events[] = [$soFar, $total];
            },
        ]);

        self::assertSame([[11, 11]], $events);
    }

    public function testDispatchesProgressAndCompletedEvents(): void
    {
        $dispatcher = new RecordingDispatcher();
        $fs = new Filesystem($this->adapter(), $this->writeContents(), null, $dispatcher);

        $fs->write('done.txt', 'hello');

        self::assertCount(2, $dispatcher->events);
        self::assertInstanceOf(UploadProgressed::class, $dispatcher->events[0]);
        $completed = $dispatcher->events[1];
        self::assertInstanceOf(UploadCompleted::class, $completed);
        self::assertSame('done.txt', $completed->path);
        self::assertSame(5, $completed->bytesWritten);
    }

    public function testDispatchesUploadFailedAndRethrows(): void
    {
        $dispatcher = new RecordingDispatcher();
        $error = UnableToWriteFile::atLocation('x', 'boom');
        $fs = new Filesystem(new StubAdapter($this->adapter(), $error), $this->writeContents(), null, $dispatcher);

        try {
            $fs->write('fail.txt', 'data');
            self::fail('Expected the write to throw.');
        } catch (UnableToWriteFile $thrown) {
            self::assertSame($error, $thrown);
        }

        self::assertCount(1, $dispatcher->events);
        self::assertInstanceOf(UploadFailed::class, $dispatcher->events[0]);
    }

    public function testChecksumUsesProviderWhenAvailable(): void
    {
        $fs = $this->filesystem();
        $fs->write('sum.txt', 'hash me');

        self::assertSame(hash('sha256', 'hash me'), $fs->checksum('sum.txt', 'sha256'));
    }

    public function testChecksumFallsBackToStreamingHash(): void
    {
        $fs = new Filesystem(new StubAdapter($this->adapter()), $this->writeContents());
        $fs->write('sum.txt', 'hash me');

        self::assertSame(hash('sha256', 'hash me'), $fs->checksum('sum.txt', 'sha256'));
    }

    public function testPublicUrlThrowsWhenAdapterCannotGenerate(): void
    {
        $fs = new Filesystem(new StubAdapter($this->adapter()), $this->writeContents());

        $this->expectException(UnableToGeneratePublicUrl::class);
        $fs->publicUrl('x.txt');
    }

    public function testTemporaryUrlThrowsWhenUnsupported(): void
    {
        $fs = new Filesystem(new StubAdapter($this->adapter()), $this->writeContents());

        $this->expectException(UnableToGenerateTemporaryUrl::class);
        $fs->temporaryUrl('x.txt', new DateTimeImmutable('+1 hour', new DateTimeZone('UTC')));
    }

    public function testUrlReturnsPublicUrlForPublicObject(): void
    {
        $fs = new Filesystem(new UrlCapableAdapter($this->adapter()), $this->writeContents());
        $fs->write('pub.txt', 'x', [Config::VISIBILITY => 'public']);

        self::assertSame('https://cdn.example/pub.txt', $fs->url('pub.txt'));
    }

    public function testUrlReturnsTemporaryUrlForPrivateObject(): void
    {
        $fs = new Filesystem(new UrlCapableAdapter($this->adapter()), $this->writeContents());
        $fs->write('priv.txt', 'x', [Config::VISIBILITY => 'private']);

        $expires = new DateTimeImmutable('2030-01-01T00:00:00Z');
        $url = $fs->url('priv.txt', [Config::EXPIRES_AT => $expires]);

        self::assertSame('https://cdn.example/priv.txt?expires=' . $expires->getTimestamp(), $url);
    }

    public function testUrlFallsBackToPublicUrlWhenTemporaryUnsupported(): void
    {
        // InMemoryAdapter supports neither capability: url() falls through to
        // publicUrl(), which throws.
        $fs = $this->filesystem();
        $fs->write('x.txt', 'x');

        $this->expectException(UnableToGeneratePublicUrl::class);
        $fs->url('x.txt');
    }

    public function testPathNormalizationCollapsesSegments(): void
    {
        $fs = $this->filesystem();
        $fs->write('./docs/../docs/a.txt', 'x');

        self::assertSame('x', $fs->read('docs/a.txt'));
        self::assertTrue($fs->fileExists('docs/a.txt'));
    }

    public function testPathTraversalIsRejected(): void
    {
        $this->expectException(PathTraversalDetected::class);
        $this->filesystem()->read('../etc/passwd');
    }

    public function testMetadataUnwrapsToScalars(): void
    {
        $fs = $this->filesystem();
        $fs->write('m.txt', 'hello');

        self::assertSame(5, $fs->fileSize('m.txt'));
        self::assertGreaterThan(0, $fs->lastModified('m.txt'));
        self::assertStringStartsWith('text/', $fs->mimeType('m.txt'));
    }

    public function testListContentsReturnsLazyListing(): void
    {
        $fs = $this->filesystem();
        $fs->write('dir/a.txt', 'a');
        $fs->write('dir/b.txt', 'b');

        $paths = [];
        foreach ($fs->listContents('dir', false) as $entry) {
            if ($entry->isFile()) {
                $paths[] = $entry->path();
            }
        }
        sort($paths);

        self::assertSame(['dir/a.txt', 'dir/b.txt'], $paths);
    }

    private function filesystem(): Filesystem
    {
        return new Filesystem($this->adapter(), $this->writeContents());
    }

    private function adapter(): InMemoryAdapter
    {
        return new InMemoryAdapter(new Psr17Factory());
    }

    private function writeContents(): WriteContents
    {
        return new WriteContents(new Psr17Factory());
    }
}
