<?php

declare(strict_types=1);

namespace PHPdot\Filesystem\Tests\Unit\Upload;

use FilesystemIterator;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPdot\Filesystem\Adapter\LocalAdapter;
use PHPdot\Filesystem\Exception\MultipartUploadFailed;
use PHPdot\Filesystem\Exception\UploadOffsetMismatch;
use PHPdot\Filesystem\Exception\UploadSessionNotFound;
use PHPdot\Filesystem\Exception\UploadSizeMismatch;
use PHPdot\Filesystem\FilesystemConfig;
use PHPdot\Filesystem\Upload\Store\LocalSessionStore;
use PHPdot\Filesystem\Upload\UploadManager;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class UploadManagerLocalTest extends TestCase
{
    private string $root;
    private string $sessionDir;
    private LocalAdapter $adapter;
    private UploadManager $manager;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/phpdot-fs-up-' . bin2hex(random_bytes(6));
        $this->sessionDir = sys_get_temp_dir() . '/phpdot-fs-sess-' . bin2hex(random_bytes(6));
        $this->adapter = new LocalAdapter(new FilesystemConfig(root: $this->root), new Psr17Factory());
        $this->manager = new UploadManager(
            $this->adapter,
            new LocalSessionStore(new FilesystemConfig(sessionDirectory: $this->sessionDir)),
        );
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->root);
        $this->deleteTree($this->sessionDir);
    }

    public function testCreateWriteCompleteAssemblesFile(): void
    {
        $session = $this->manager->create('uploads/big.bin', 8);
        self::assertSame(0, $session->bytesReceived);

        $first = $this->manager->writeChunk($session->id, 0, $this->stream('AAAA'), 4);
        self::assertSame(4, $first->offset);
        self::assertFalse($first->complete);

        $second = $this->manager->writeChunk($session->id, 4, $this->stream('BBBB'), 4);
        self::assertSame(8, $second->offset);
        self::assertTrue($second->complete);

        $this->manager->complete($session->id);

        self::assertSame('AAAABBBB', $this->adapter->read('uploads/big.bin'));
    }

    public function testCompleteRefusesAnIncompleteDeclaredUpload(): void
    {
        $session = $this->manager->create('short.bin', 8);
        $this->manager->writeChunk($session->id, 0, $this->stream('AAAA'), 4);

        try {
            $this->manager->complete($session->id);
            self::fail('an incomplete upload must not complete');
        } catch (UploadSizeMismatch $mismatch) {
            self::assertSame(8, $mismatch->declaredBytes());
            self::assertSame(4, $mismatch->receivedBytes());
        }
    }

    public function testCompleteAbortsWhenStorageHoldsMoreThanDeclared(): void
    {
        $session = $this->manager->create('over.bin', 4);
        $this->manager->writeChunk($session->id, 0, $this->stream('AAAA'), 4);
        $this->manager->writeChunk($session->id, 4, $this->stream('BBBB'), 4);

        try {
            $this->manager->complete($session->id);
            self::fail('an over-sized upload must not complete');
        } catch (UploadSizeMismatch $mismatch) {
            self::assertSame(4, $mismatch->declaredBytes());
            self::assertSame(8, $mismatch->receivedBytes());
        }

        self::assertFalse($this->adapter->fileExists('over.bin'));
        $this->expectException(UploadSessionNotFound::class);
        $this->manager->status($session->id);
    }

    public function testCompleteBuildsFromTheStoragesOwnPartListNotTheSessions(): void
    {
        $session = $this->manager->create('truth.bin', 8);
        $this->manager->writeChunk($session->id, 0, $this->stream('AAAA'), 4);
        $this->manager->writeChunk($session->id, 4, $this->stream('BBBB'), 4);

        $partPath = $this->root . '/truth.bin.phpdot-mpu-' . $session->uploadId . '.1.part';
        unlink($partPath);

        $this->expectException(MultipartUploadFailed::class);
        $this->expectExceptionMessage('no parts');

        $this->manager->complete($session->id);
    }

    public function testCompleteMeasuresTheStoragesTruthNotSessionBookkeeping(): void
    {
        $session = $this->manager->create('direct.bin', 8);

        $partPath = $this->root . '/direct.bin.phpdot-mpu-' . $session->uploadId . '.1.part';
        file_put_contents($partPath, 'AAAABBBB');

        $this->manager->complete($session->id);

        self::assertSame('AAAABBBB', $this->adapter->read('direct.bin'), 'a client-uploaded part completes regardless of the session byte count');
    }

    public function testStatusReflectsProgressAndSupportsResume(): void
    {
        $session = $this->manager->create('r.bin', 6);
        $this->manager->writeChunk($session->id, 0, $this->stream('XYZ'), 3);

        // A fresh manager (e.g. another worker) resumes from the persisted session.
        $resumed = new UploadManager(
            $this->adapter,
            new LocalSessionStore(new FilesystemConfig(sessionDirectory: $this->sessionDir)),
        );
        self::assertSame(3, $resumed->status($session->id)->bytesReceived);

        $resumed->writeChunk($session->id, 3, $this->stream('123'), 3);
        $resumed->complete($session->id);

        self::assertSame('XYZ123', $this->adapter->read('r.bin'));
    }

    public function testOffsetMismatchThrows(): void
    {
        $session = $this->manager->create('m.bin', 8);
        $this->manager->writeChunk($session->id, 0, $this->stream('AAAA'), 4);

        $this->expectException(UploadOffsetMismatch::class);
        $this->manager->writeChunk($session->id, 99, $this->stream('BBBB'), 4);
    }

    public function testAbortDiscardsSessionAndFile(): void
    {
        $session = $this->manager->create('a.bin', 8);
        $this->manager->writeChunk($session->id, 0, $this->stream('AAAA'), 4);

        $this->manager->abort($session->id);
        self::assertFalse($this->adapter->fileExists('a.bin'));

        $this->expectException(UploadSessionNotFound::class);
        $this->manager->status($session->id);
    }

    public function testUnknownSessionThrows(): void
    {
        $this->expectException(UploadSessionNotFound::class);
        $this->manager->status('does-not-exist');
    }

    private function stream(string $contents): StreamInterface
    {
        $stream = (new Psr17Factory())->createStream($contents);
        $stream->rewind();

        return $stream;
    }

    private function deleteTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }

            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }
}
