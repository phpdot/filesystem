<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Tests\Unit\Http;

use FilesystemIterator;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPdot\Filesystem\Adapter\LocalAdapter;
use PHPdot\Filesystem\FilesystemConfig;
use PHPdot\Filesystem\Http\ResumableUploadHandler;
use PHPdot\Filesystem\Upload\Store\LocalSessionStore;
use PHPdot\Filesystem\Upload\UploadManager;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ResumableUploadHandlerTest extends TestCase
{
    private string $root;
    private string $sessionDir;
    private Psr17Factory $http;
    private LocalAdapter $adapter;
    private ResumableUploadHandler $handler;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/phpdot-tus-root-' . bin2hex(random_bytes(6));
        $this->sessionDir = sys_get_temp_dir() . '/phpdot-tus-sess-' . bin2hex(random_bytes(6));

        // Any PSR-17 ResponseFactory/ServerRequestFactory/StreamFactory works — the
        // handler is decoupled via the PSR interfaces.
        $this->http = new Psr17Factory();
        $this->adapter = new LocalAdapter(new FilesystemConfig(root: $this->root), $this->http);
        $manager = new UploadManager(
            $this->adapter,
            new LocalSessionStore(new FilesystemConfig(sessionDirectory: $this->sessionDir)),
        );
        $this->handler = new ResumableUploadHandler($manager, $this->http);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->root);
        $this->deleteTree($this->sessionDir);
    }

    public function testFullResumableUploadFlowAssemblesTheFile(): void
    {
        $created = $this->handler->handle(
            $this->http->createServerRequest('POST', '/uploads')
                ->withHeader('Upload-Length', '8')
                ->withHeader('Upload-Metadata', 'filename ' . base64_encode('big.bin')),
        );
        self::assertSame(201, $created->getStatusCode());
        self::assertSame('0', $created->getHeaderLine('Upload-Offset'));

        $location = $created->getHeaderLine('Location');
        self::assertStringStartsWith('/uploads/', $location);

        $status = $this->handler->handle($this->http->createServerRequest('HEAD', $location));
        self::assertSame(200, $status->getStatusCode());
        self::assertSame('0', $status->getHeaderLine('Upload-Offset'));
        self::assertSame('8', $status->getHeaderLine('Upload-Length'));

        $first = $this->handler->handle(
            $this->http->createServerRequest('PATCH', $location)
                ->withHeader('Upload-Offset', '0')
                ->withBody($this->stream('AAAA')),
        );
        self::assertSame(204, $first->getStatusCode());
        self::assertSame('4', $first->getHeaderLine('Upload-Offset'));

        $second = $this->handler->handle(
            $this->http->createServerRequest('PATCH', $location)
                ->withHeader('Upload-Offset', '4')
                ->withBody($this->stream('BBBB')),
        );
        self::assertSame(204, $second->getStatusCode());
        self::assertSame('8', $second->getHeaderLine('Upload-Offset'));

        self::assertSame('AAAABBBB', $this->adapter->read('uploads/big.bin'));
    }

    public function testTusResumableHeaderIsAlwaysPresent(): void
    {
        $response = $this->handler->handle(
            $this->http->createServerRequest('POST', '/uploads')->withHeader('Upload-Length', '4'),
        );

        self::assertSame('1.0.0', $response->getHeaderLine('Tus-Resumable'));
    }

    public function testUnknownSessionReturns404(): void
    {
        $response = $this->handler->handle($this->http->createServerRequest('HEAD', '/uploads/does-not-exist'));

        self::assertSame(404, $response->getStatusCode());
    }

    public function testOffsetMismatchReturns409WithExpectedOffset(): void
    {
        $location = $this->createSession();

        $response = $this->handler->handle(
            $this->http->createServerRequest('PATCH', $location)
                ->withHeader('Upload-Offset', '99')
                ->withBody($this->stream('AAAA')),
        );

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('0', $response->getHeaderLine('Upload-Offset'));
    }

    public function testDeleteAbortsSession(): void
    {
        $location = $this->createSession();

        self::assertSame(204, $this->handler->handle($this->http->createServerRequest('DELETE', $location))->getStatusCode());
        self::assertSame(404, $this->handler->handle($this->http->createServerRequest('HEAD', $location))->getStatusCode());
    }

    public function testUnsupportedMethodReturns405(): void
    {
        $response = $this->handler->handle($this->http->createServerRequest('GET', '/uploads/x'));

        self::assertSame(405, $response->getStatusCode());
        self::assertStringContainsString('PATCH', $response->getHeaderLine('Allow'));
    }

    private function createSession(): string
    {
        return $this->handler->handle(
            $this->http->createServerRequest('POST', '/uploads')->withHeader('Upload-Length', '8'),
        )->getHeaderLine('Location');
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
