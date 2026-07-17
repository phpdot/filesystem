<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Tests\Unit\Upload\Store;

use DateTimeImmutable;
use DateTimeZone;
use FilesystemIterator;
use PHPdot\Filesystem\FilesystemConfig;
use PHPdot\Filesystem\Upload\Store\LocalSessionStore;
use PHPdot\Filesystem\Upload\UploadSession;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class LocalSessionStoreTest extends TestCase
{
    private string $dir;
    private LocalSessionStore $store;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/phpdot-fs-store-' . bin2hex(random_bytes(6));
        $this->store = new LocalSessionStore(new FilesystemConfig(sessionDirectory: $this->dir));
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->dir);
    }

    public function testPutFindRoundTrip(): void
    {
        $expires = new DateTimeImmutable('+1 hour', new DateTimeZone('UTC'));
        $this->store->put($this->session('abc', $expires, 42));

        $found = $this->store->find('abc');

        self::assertNotNull($found);
        self::assertSame('abc', $found->id);
        self::assertSame('p/abc.bin', $found->path);
        self::assertSame('UPID-abc', $found->uploadId);
        self::assertSame(42, $found->bytesReceived);
        self::assertSame(100, $found->totalSize);
        self::assertSame([1 => 'etag1', 2 => 'etag2'], $found->parts);
        self::assertSame(1024, $found->chunkSize);
        self::assertSame($expires->getTimestamp(), $found->expiresAt->getTimestamp());
    }

    public function testFindMissingReturnsNull(): void
    {
        self::assertNull($this->store->find('nope'));
    }

    public function testDeleteRemovesSession(): void
    {
        $this->store->put($this->session('del', new DateTimeImmutable('+1 hour', new DateTimeZone('UTC'))));
        $this->store->delete('del');

        self::assertNull($this->store->find('del'));
    }

    public function testExpiredYieldsOnlyExpiredSessions(): void
    {
        $past = new DateTimeImmutable('-1 hour', new DateTimeZone('UTC'));
        $future = new DateTimeImmutable('+1 hour', new DateTimeZone('UTC'));
        $this->store->put($this->session('old1', $past));
        $this->store->put($this->session('old2', $past));
        $this->store->put($this->session('fresh', $future));

        $expiredIds = array_map(
            static fn(UploadSession $session): string => $session->id,
            iterator_to_array($this->store->expired(new DateTimeImmutable('now', new DateTimeZone('UTC'))), false),
        );
        sort($expiredIds);

        self::assertSame(['old1', 'old2'], $expiredIds);
    }

    private function session(string $id, DateTimeImmutable $expiresAt, int $bytesReceived = 0): UploadSession
    {
        return new UploadSession(
            id: $id,
            path: 'p/' . $id . '.bin',
            uploadId: 'UPID-' . $id,
            totalSize: 100,
            bytesReceived: $bytesReceived,
            parts: [1 => 'etag1', 2 => 'etag2'],
            chunkSize: 1024,
            expiresAt: $expiresAt,
        );
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
