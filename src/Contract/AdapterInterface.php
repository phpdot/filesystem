<?php

declare(strict_types=1);

/**
 * What a storage backend implements. Strictly typed: writes consume a stream,
 * metadata reads return {@see FileAttributes}, config arrives as {@see Config}.
 *
 * Adapters implement capability interfaces ({@see ChecksumProvider},
 * {@see PublicUrlGenerator}, {@see TemporaryUrlGenerator}, {@see MultipartCapable})
 * only for what they actually support; the operator probes with `instanceof`
 * and falls back when a capability is absent.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Contract;

use PHPdot\Filesystem\Attributes\FileAttributes;
use PHPdot\Filesystem\Config;
use Psr\Http\Message\StreamInterface;

interface AdapterInterface
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
     * Write.
     *
     * @param string $path
     * @param StreamInterface $contents
     * @param Config $config
     *
     * @return void
     */
    public function write(string $path, StreamInterface $contents, Config $config): void;

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
     * Create directory.
     *
     * @param string $path
     * @param Config $config
     *
     * @return void
     */
    public function createDirectory(string $path, Config $config): void;

    /**
     * Set visibility.
     *
     * @param string $path
     * @param string $visibility
     *
     * @return void
     */
    public function setVisibility(string $path, string $visibility): void;

    /**
     * Visibility.
     *
     * @param string $path
     *
     * @return FileAttributes
     */
    public function visibility(string $path): FileAttributes;

    /**
     * Mime type.
     *
     * @param string $path
     *
     * @return FileAttributes
     */
    public function mimeType(string $path): FileAttributes;

    /**
     * Last modified.
     *
     * @param string $path
     *
     * @return FileAttributes
     */
    public function lastModified(string $path): FileAttributes;

    /**
     * File size.
     *
     * @param string $path
     *
     * @return FileAttributes
     */
    public function fileSize(string $path): FileAttributes;

    /**
     * List a directory's contents, optionally recursively.
     *
     * @param bool $deep
     * @param string $path
     *
     * @return iterable<StorageAttributes>
     */
    public function listContents(string $path, bool $deep): iterable;

    /**
     * Move.
     *
     * @param string $source
     * @param string $destination
     * @param Config $config
     *
     * @return void
     */
    public function move(string $source, string $destination, Config $config): void;

    /**
     * Copy.
     *
     * @param string $source
     * @param string $destination
     * @param Config $config
     *
     * @return void
     */
    public function copy(string $source, string $destination, Config $config): void;
}
