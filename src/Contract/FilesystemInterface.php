<?php

declare(strict_types=1);

/**
 * The friendly operator the application uses. Accepts the write-input union and
 * array config at the public edge, returns scalars and value objects.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Contract;

use DateTimeInterface;
use PHPdot\Filesystem\DirectoryListing;
use PHPdot\Filesystem\Visibility;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;

interface FilesystemInterface
{
    /**
     * File exists.
     *
     * @param string $path
     *
     * @return bool
     */
    public function fileExists(string $path): bool;

    /**
     * Directory exists.
     *
     * @param string $path
     *
     * @return bool
     */
    public function directoryExists(string $path): bool;

    /**
     * Has.
     *
     * @param string $path
     *
     * @return bool
     */
    public function has(string $path): bool;

    /**
     * Read.
     *
     * @param string $path
     *
     * @return string
     */
    public function read(string $path): string;

    /**
     * Read stream.
     *
     * @param string $path
     *
     * @return StreamInterface
     */
    public function readStream(string $path): StreamInterface;

    /**
     * List contents.
     *
     * @param string $path
     * @param bool $deep
     *
     * @return DirectoryListing
     */
    public function listContents(string $path, bool $deep = false): DirectoryListing;

    /**
     * File size.
     *
     * @param string $path
     *
     * @return int
     */
    public function fileSize(string $path): int;

    /**
     * Last modified.
     *
     * @param string $path
     *
     * @return int
     */
    public function lastModified(string $path): int;

    /**
     * Mime type.
     *
     * @param string $path
     *
     * @return string
     */
    public function mimeType(string $path): string;

    /**
     * Checksum.
     *
     * @param string $path
     * @param string $algo
     *
     * @return string
     */
    public function checksum(string $path, string $algo = 'sha256'): string;

    /**
     * Visibility.
     *
     * @param string $path
     *
     * @return Visibility
     */
    public function visibility(string $path): Visibility;

    /**
     * Write a stream to the given path.
     *
     * @param string|StreamInterface|UploadedFileInterface $contents
     * @param array<string,mixed> $config
     * @param string $path
     *
     * @return void
     */
    public function write(string $path, string|StreamInterface|UploadedFileInterface $contents, array $config = []): void;

    /**
     * Set visibility.
     *
     * @param string $path
     * @param Visibility $visibility
     *
     * @return void
     */
    public function setVisibility(string $path, Visibility $visibility): void;

    /**
     * Delete.
     *
     * @param string $path
     *
     * @return void
     */
    public function delete(string $path): void;

    /**
     * Delete directory.
     *
     * @param string $path
     *
     * @return void
     */
    public function deleteDirectory(string $path): void;

    /**
     * Create a directory at the given path.
     *
     * @param array<string,mixed> $config
     * @param string $path
     *
     * @return void
     */
    public function createDirectory(string $path, array $config = []): void;

    /**
     * Move (rename) a file to a new path.
     *
     * @param array<string,mixed> $config
     * @param string $source
     * @param string $destination
     *
     * @return void
     */
    public function move(string $source, string $destination, array $config = []): void;

    /**
     * Copy a file to a new path.
     *
     * @param array<string,mixed> $config
     * @param string $source
     * @param string $destination
     *
     * @return void
     */
    public function copy(string $source, string $destination, array $config = []): void;

    /**
     * Return a public URL for the given path.
     *
     * @param array<string,mixed> $config
     * @param string $path
     *
     * @return string
     */
    public function publicUrl(string $path, array $config = []): string;

    /**
     * Return a temporary (signed) URL for the given path.
     *
     * @param array<string,mixed> $config
     * @param DateTimeInterface $expiresAt
     * @param string $path
     *
     * @return string
     */
    public function temporaryUrl(string $path, DateTimeInterface $expiresAt, array $config = []): string;

    /**
     * A visibility-aware URL: a {@see publicUrl} for a public object, otherwise a
     * {@see temporaryUrl}. Pass a {@see \PHPdot\Filesystem\Config::EXPIRES_AT}
     * ({@see DateTimeInterface}) in $config to override the default expiry.
     *
     * @param array<string,mixed> $config
     * @param string $path
     *
     * @return string
     */
    public function url(string $path, array $config = []): string;

    /**
     * Whether the backend can generate public URLs — i.e. {@see publicUrl} will
     * not throw. Lets callers branch on capability without catching exceptions.
     *
     * @return bool
     */
    public function supportsPublicUrls(): bool;

    /**
     * Whether the backend can generate presigned/temporary URLs — i.e.
     * {@see temporaryUrl} will not throw.
     *
     * @return bool
     */
    public function supportsTemporaryUrls(): bool;
}
