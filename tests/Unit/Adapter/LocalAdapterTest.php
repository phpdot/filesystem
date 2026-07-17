<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Tests\Unit\Adapter;

use FilesystemIterator;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPdot\Filesystem\Adapter\LocalAdapter;
use PHPdot\Filesystem\Config;
use PHPdot\Filesystem\Contract\AdapterInterface;
use PHPdot\Filesystem\Exception\UnableToGeneratePublicUrl;
use PHPdot\Filesystem\FilesystemConfig;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class LocalAdapterTest extends AdapterTestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/phpdot-fs-local-' . bin2hex(random_bytes(6));
        parent::setUp();
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->root);
    }

    protected function createAdapter(): AdapterInterface
    {
        return $this->createLocalAdapter();
    }

    public function testChecksumHashesFileContent(): void
    {
        $adapter = $this->createLocalAdapter();
        $adapter->write('sum.txt', $this->stream('hash me'), new Config());

        self::assertSame(hash('sha256', 'hash me'), $adapter->checksum('sum.txt', 'sha256'));
    }

    public function testPublicUrlJoinsBaseAndPath(): void
    {
        $adapter = $this->createLocalAdapter();

        self::assertSame('https://cdn.example.test/a/b.txt', $adapter->publicUrl('a/b.txt', new Config()));
    }

    public function testPublicUrlThrowsWhenNotConfigured(): void
    {
        $adapter = new LocalAdapter(new FilesystemConfig(root: $this->root), new Psr17Factory());

        $this->expectException(UnableToGeneratePublicUrl::class);

        $adapter->publicUrl('a.txt', new Config());
    }

    public function testMultipartConcatenatesPartsInOrder(): void
    {
        $adapter = $this->createLocalAdapter();
        $uploadId = $adapter->createMultipart('big/file.bin', new Config());

        // Upload out of order to prove completion sorts by part number.
        $adapter->uploadPart('big/file.bin', $uploadId, 2, $this->stream('BBBB'), 4);
        $adapter->uploadPart('big/file.bin', $uploadId, 1, $this->stream('AAAA'), 4);
        $adapter->completeMultipart('big/file.bin', $uploadId, [1 => 'm1', 2 => 'm2']);

        self::assertSame('AAAABBBB', $adapter->read('big/file.bin'));
    }

    public function testMultipartAbortLeavesNoFinalFile(): void
    {
        $adapter = $this->createLocalAdapter();
        $uploadId = $adapter->createMultipart('x/y.bin', new Config());
        $adapter->uploadPart('x/y.bin', $uploadId, 1, $this->stream('data'), 4);

        $adapter->abortMultipart('x/y.bin', $uploadId);

        self::assertFalse($adapter->fileExists('x/y.bin'));
    }

    private function createLocalAdapter(): LocalAdapter
    {
        return new LocalAdapter(
            new FilesystemConfig(root: $this->root, publicUrl: 'https://cdn.example.test'),
            new Psr17Factory(),
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

            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }
}
