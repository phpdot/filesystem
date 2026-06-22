<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Tests\Unit;

use PHPdot\Filesystem\Adapter\InMemoryAdapter;
use PHPdot\Filesystem\Attributes\FileAttributes;
use PHPdot\Filesystem\Config;
use PHPdot\Filesystem\Contract\AdapterInterface;
use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * A bare {@see AdapterInterface} — deliberately implements none of the
 * capability interfaces — used to exercise the operator's capability fallbacks.
 * Optionally fails writes to drive the failure path.
 */
final class StubAdapter implements AdapterInterface
{
    public function __construct(
        private readonly InMemoryAdapter $inner,
        private readonly ?Throwable $writeError = null,
    ) {}

    public function write(string $path, StreamInterface $contents, Config $config): void
    {
        if ($this->writeError !== null) {
            throw $this->writeError;
        }

        $this->inner->write($path, $contents, $config);
    }

    public function fileExists(string $path): bool
    {
        return $this->inner->fileExists($path);
    }

    public function directoryExists(string $path): bool
    {
        return $this->inner->directoryExists($path);
    }

    public function read(string $path): string
    {
        return $this->inner->read($path);
    }

    public function readStream(string $path): StreamInterface
    {
        return $this->inner->readStream($path);
    }

    public function delete(string $path): void
    {
        $this->inner->delete($path);
    }

    public function deleteDirectory(string $path): void
    {
        $this->inner->deleteDirectory($path);
    }

    public function createDirectory(string $path, Config $config): void
    {
        $this->inner->createDirectory($path, $config);
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $this->inner->setVisibility($path, $visibility);
    }

    public function visibility(string $path): FileAttributes
    {
        return $this->inner->visibility($path);
    }

    public function mimeType(string $path): FileAttributes
    {
        return $this->inner->mimeType($path);
    }

    public function lastModified(string $path): FileAttributes
    {
        return $this->inner->lastModified($path);
    }

    public function fileSize(string $path): FileAttributes
    {
        return $this->inner->fileSize($path);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        return $this->inner->listContents($path, $deep);
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $this->inner->move($source, $destination, $config);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $this->inner->copy($source, $destination, $config);
    }
}
