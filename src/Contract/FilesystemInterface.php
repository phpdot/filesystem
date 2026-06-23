<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Contract;

use DateTimeInterface;
use PHPdot\Filesystem\DirectoryListing;
use PHPdot\Filesystem\Visibility;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * The friendly operator the application uses. Accepts the write-input union and
 * array config at the public edge, returns scalars and value objects.
 */
interface FilesystemInterface
{
    public function fileExists(string $path): bool;

    public function directoryExists(string $path): bool;

    public function has(string $path): bool;

    public function read(string $path): string;

    public function readStream(string $path): StreamInterface;

    public function listContents(string $path, bool $deep = false): DirectoryListing;

    public function fileSize(string $path): int;

    public function lastModified(string $path): int;

    public function mimeType(string $path): string;

    public function checksum(string $path, string $algo = 'sha256'): string;

    public function visibility(string $path): Visibility;

    /**
     * @param string|StreamInterface|UploadedFileInterface $contents
     * @param array<string,mixed>                          $config
     */
    public function write(string $path, string|StreamInterface|UploadedFileInterface $contents, array $config = []): void;

    public function setVisibility(string $path, Visibility $visibility): void;

    public function delete(string $path): void;

    public function deleteDirectory(string $path): void;

    /**
     * @param array<string,mixed> $config
     */
    public function createDirectory(string $path, array $config = []): void;

    /**
     * @param array<string,mixed> $config
     */
    public function move(string $source, string $destination, array $config = []): void;

    /**
     * @param array<string,mixed> $config
     */
    public function copy(string $source, string $destination, array $config = []): void;

    /**
     * @param array<string,mixed> $config
     */
    public function publicUrl(string $path, array $config = []): string;

    /**
     * @param array<string,mixed> $config
     */
    public function temporaryUrl(string $path, DateTimeInterface $expiresAt, array $config = []): string;

    /**
     * A visibility-aware URL: a {@see publicUrl} for a public object, otherwise a
     * {@see temporaryUrl}. Pass a {@see \PHPdot\Filesystem\Config::EXPIRES_AT}
     * ({@see DateTimeInterface}) in $config to override the default expiry.
     *
     * @param array<string,mixed> $config
     */
    public function url(string $path, array $config = []): string;

    /**
     * Whether the backend can generate public URLs — i.e. {@see publicUrl} will
     * not throw. Lets callers branch on capability without catching exceptions.
     */
    public function supportsPublicUrls(): bool;

    /**
     * Whether the backend can generate presigned/temporary URLs — i.e.
     * {@see temporaryUrl} will not throw.
     */
    public function supportsTemporaryUrls(): bool;
}
