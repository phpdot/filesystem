<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Contract;

use PHPdot\Filesystem\Attributes\FileAttributes;
use PHPdot\Filesystem\Config;
use Psr\Http\Message\StreamInterface;

/**
 * What a storage backend implements. Strictly typed: writes consume a stream,
 * metadata reads return {@see FileAttributes}, config arrives as {@see Config}.
 *
 * Adapters implement capability interfaces ({@see ChecksumProvider},
 * {@see PublicUrlGenerator}, {@see TemporaryUrlGenerator}, {@see MultipartCapable})
 * only for what they actually support; the operator probes with `instanceof`
 * and falls back when a capability is absent.
 */
interface AdapterInterface
{
    public function fileExists(string $path): bool;

    public function directoryExists(string $path): bool;

    public function write(string $path, StreamInterface $contents, Config $config): void;

    public function read(string $path): string;

    public function readStream(string $path): StreamInterface;

    public function delete(string $path): void;

    public function deleteDirectory(string $path): void;

    public function createDirectory(string $path, Config $config): void;

    public function setVisibility(string $path, string $visibility): void;

    public function visibility(string $path): FileAttributes;

    public function mimeType(string $path): FileAttributes;

    public function lastModified(string $path): FileAttributes;

    public function fileSize(string $path): FileAttributes;

    /**
     * @return iterable<StorageAttributes>
     */
    public function listContents(string $path, bool $deep): iterable;

    public function move(string $source, string $destination, Config $config): void;

    public function copy(string $source, string $destination, Config $config): void;
}
