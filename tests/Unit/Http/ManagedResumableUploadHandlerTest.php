<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Tests\Unit\Http;

use FilesystemIterator;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPdot\Filesystem\Adapter\LocalAdapter;
use PHPdot\Filesystem\Filesystem;
use PHPdot\Filesystem\FilesystemConfig;
use PHPdot\Filesystem\Http\ManagedResumableUploadHandler;
use PHPdot\Filesystem\ManagedFiles\Files;
use PHPdot\Filesystem\Path\PathGenerator;
use PHPdot\Filesystem\Tests\Unit\ManagedFiles\InMemoryFileRepository;
use PHPdot\Filesystem\Upload\Store\LocalSessionStore;
use PHPdot\Filesystem\Upload\UploadManager;
use PHPdot\Filesystem\Write\WriteContents;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ManagedResumableUploadHandlerTest extends TestCase
{
    private string $root;
    private string $sessionDir;
    private Psr17Factory $http;
    private InMemoryFileRepository $repo;
    private ManagedResumableUploadHandler $handler;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/phpdot-mtus-root-' . bin2hex(random_bytes(6));
        $this->sessionDir = sys_get_temp_dir() . '/phpdot-mtus-sess-' . bin2hex(random_bytes(6));

        $this->http = new Psr17Factory();
        $config = new FilesystemConfig(root: $this->root, sessionDirectory: $this->sessionDir);
        $adapter = new LocalAdapter($config, $this->http);
        $manager = new UploadManager($adapter, new LocalSessionStore($config));
        $fs = new Filesystem($adapter, new WriteContents($this->http), null, null, $config);
        $this->repo = new InMemoryFileRepository();
        $files = new Files($fs, $this->repo, new WriteContents($this->http), $this->http, new PathGenerator(), $config);

        $this->handler = new ManagedResumableUploadHandler($manager, $files, $this->http);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->root);
        $this->deleteTree($this->sessionDir);
    }

    public function testUploadCreatesDraftThenFinalizesRecordOnCompletion(): void
    {
        $location = $this->handler->handle(
            $this->http->createServerRequest('POST', '/uploads')
                ->withHeader('Upload-Length', '8')
                ->withHeader('Upload-Metadata', 'filename ' . base64_encode('big.bin')),
        )->getHeaderLine('Location');

        // A draft record exists immediately, before any bytes land.
        $draft = $this->repo->findByPath('uploads/big.bin');
        self::assertNotNull($draft);
        self::assertTrue($draft->isDraft);
        self::assertSame('big.bin', $draft->originalName);

        $this->handler->handle(
            $this->http->createServerRequest('PATCH', $location)
                ->withHeader('Upload-Offset', '0')
                ->withBody($this->stream('AAAA')),
        );
        $this->handler->handle(
            $this->http->createServerRequest('PATCH', $location)
                ->withHeader('Upload-Offset', '4')
                ->withBody($this->stream('BBBB')),
        );

        $final = $this->repo->findByPath('uploads/big.bin');
        self::assertNotNull($final);
        self::assertFalse($final->isDraft, 'Completed upload must be published.');
        self::assertSame(8, $final->size);
        self::assertSame(hash('sha256', 'AAAABBBB'), $final->checksum);
    }

    public function testIncompleteUploadLeavesADraftToBeSwept(): void
    {
        $this->handler->handle(
            $this->http->createServerRequest('POST', '/uploads')
                ->withHeader('Upload-Length', '8')
                ->withHeader('Upload-Metadata', 'filename ' . base64_encode('partial.bin')),
        );

        $draft = $this->repo->findByPath('uploads/partial.bin');
        self::assertNotNull($draft);
        self::assertTrue($draft->isDraft);
        self::assertNotNull($draft->expiresAt);
    }

    private function stream(string $contents): StreamInterface
    {
        $stream = $this->http->createStream($contents);
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
