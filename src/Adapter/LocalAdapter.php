<?php

declare(strict_types=1);

/**
 * The local-disk adapter.
 *
 * All I/O uses native functions (fopen/fread/fwrite/mkdir/rename/unlink), so it
 * is transparently coroutine-safe under Swoole's SWOOLE_HOOK_FILE and plain
 * blocking otherwise — the adapter never enables hooks itself. A raw resource
 * appears only at this floor and is wrapped into a PSR-7 stream immediately.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Adapter;

use FilesystemIterator;
use Generator;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use League\MimeTypeDetection\MimeTypeDetector;
use PHPdot\Container\Attribute\Binds;
use PHPdot\Container\Attribute\Singleton;
use PHPdot\Filesystem\Attributes\DirectoryAttributes;
use PHPdot\Filesystem\Attributes\FileAttributes;
use PHPdot\Filesystem\Config;
use PHPdot\Filesystem\Contract\AdapterInterface;
use PHPdot\Filesystem\Contract\ChecksumProvider;
use PHPdot\Filesystem\Contract\MultipartCapable;
use PHPdot\Filesystem\Contract\PublicUrlGenerator;
use PHPdot\Filesystem\Exception\UnableToCopyFile;
use PHPdot\Filesystem\Exception\UnableToCreateDirectory;
use PHPdot\Filesystem\Exception\UnableToDeleteDirectory;
use PHPdot\Filesystem\Exception\UnableToDeleteFile;
use PHPdot\Filesystem\Exception\UnableToGeneratePublicUrl;
use PHPdot\Filesystem\Exception\UnableToMoveFile;
use PHPdot\Filesystem\Exception\UnableToReadFile;
use PHPdot\Filesystem\Exception\UnableToRetrieveMetadata;
use PHPdot\Filesystem\Exception\UnableToSetVisibility;
use PHPdot\Filesystem\Exception\UnableToWriteFile;
use PHPdot\Filesystem\FilesystemConfig;
use PHPdot\Filesystem\Path\PathPrefixer;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

#[Singleton]
#[Binds(AdapterInterface::class)]
final class LocalAdapter implements AdapterInterface, ChecksumProvider, MultipartCapable, PublicUrlGenerator
{
    private const WRITE_BUFFER = 1048576;

    private readonly PathPrefixer $prefixer;
    private readonly PortableVisibility $visibilityConverter;
    private readonly MimeTypeDetector $mimeDetector;
    private readonly string $defaultVisibility;
    private readonly ?string $publicUrl;

    /**
     * __construct.
     *
     * @param FilesystemConfig $config
     * @param StreamFactoryInterface $streamFactory
     * @param ?PortableVisibility $visibilityConverter
     */
    public function __construct(
        FilesystemConfig $config,
        private readonly StreamFactoryInterface $streamFactory,
        ?PortableVisibility $visibilityConverter = null,
    ) {
        $this->prefixer = new PathPrefixer($config->root);
        $this->defaultVisibility = $config->visibility;
        $this->publicUrl = $config->publicUrl;
        $this->mimeDetector = new FinfoMimeTypeDetector();
        $this->visibilityConverter = $visibilityConverter ?? new PortableVisibility();
        $this->ensureDirectoryExists($config->root, $this->visibilityConverter->forDirectory($config->visibility));
    }

    public function fileExists(string $path): bool
    {
        return is_file($this->prefixer->prefixPath($path));
    }

    public function directoryExists(string $path): bool
    {
        return is_dir($this->prefixer->prefixDirectoryPath($path));
    }

    public function write(string $path, StreamInterface $contents, Config $config): void
    {
        $location = $this->prefixer->prefixPath($path);
        $this->ensureDirectoryExists(
            dirname($location),
            $this->visibilityConverter->forDirectory($config->getNullableString(Config::DIRECTORY_VISIBILITY) ?? $this->defaultVisibility),
        );

        error_clear_last();
        $handle = @fopen($location, 'w+b');
        if ($handle === false) {
            throw UnableToWriteFile::atLocation($path, $this->lastError());
        }

        try {
            $this->pumpInto($handle, $contents, $path);
        } finally {
            fclose($handle);
        }

        $visibility = $config->getNullableString(Config::VISIBILITY) ?? $this->defaultVisibility;
        @chmod($location, $this->visibilityConverter->forFile($visibility));
    }

    public function read(string $path): string
    {
        $location = $this->prefixer->prefixPath($path);

        error_clear_last();
        $contents = @file_get_contents($location);
        if ($contents === false) {
            throw UnableToReadFile::fromLocation($path, $this->lastError());
        }

        return $contents;
    }

    public function readStream(string $path): StreamInterface
    {
        $location = $this->prefixer->prefixPath($path);

        error_clear_last();
        $handle = @fopen($location, 'rb');
        if ($handle === false) {
            throw UnableToReadFile::fromLocation($path, $this->lastError());
        }

        return $this->streamFactory->createStreamFromResource($handle);
    }

    public function delete(string $path): void
    {
        $location = $this->prefixer->prefixPath($path);
        if (!file_exists($location)) {
            return;
        }

        error_clear_last();
        if (!@unlink($location)) {
            throw UnableToDeleteFile::atLocation($path, $this->lastError());
        }
    }

    public function deleteDirectory(string $path): void
    {
        $location = $this->prefixer->prefixDirectoryPath($path);
        if (!is_dir($location)) {
            return;
        }

        $contents = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($location, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($contents as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }

            $ok = $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            if (!$ok) {
                throw UnableToDeleteDirectory::atLocation($path, $this->lastError());
            }
        }

        if (!@rmdir($location)) {
            throw UnableToDeleteDirectory::atLocation($path, $this->lastError());
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
        $visibility = $config->getNullableString(Config::DIRECTORY_VISIBILITY) ?? $this->defaultVisibility;
        $this->ensureDirectoryExists(
            $this->prefixer->prefixDirectoryPath($path),
            $this->visibilityConverter->forDirectory($visibility),
        );
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $location = $this->prefixer->prefixPath($path);
        $mode = is_dir($location)
            ? $this->visibilityConverter->forDirectory($visibility)
            : $this->visibilityConverter->forFile($visibility);

        error_clear_last();
        if (!@chmod($location, $mode)) {
            throw UnableToSetVisibility::atLocation($path, $this->lastError());
        }
    }

    public function visibility(string $path): FileAttributes
    {
        $location = $this->prefixer->prefixPath($path);
        clearstatcache(false, $location);

        error_clear_last();
        $permissions = @fileperms($location);
        if ($permissions === false) {
            throw UnableToRetrieveMetadata::visibility($path, $this->lastError());
        }

        return new FileAttributes($path, visibility: $this->visibilityConverter->inverseForFile($permissions & 0777));
    }

    public function mimeType(string $path): FileAttributes
    {
        $location = $this->prefixer->prefixPath($path);
        if (!is_file($location)) {
            throw UnableToRetrieveMetadata::mimeType($path, 'File does not exist.');
        }

        $mimeType = $this->mimeDetector->detectMimeTypeFromFile($location);
        if ($mimeType === null) {
            throw UnableToRetrieveMetadata::mimeType($path, 'Unable to determine the mime type.');
        }

        return new FileAttributes($path, mimeType: $mimeType);
    }

    public function lastModified(string $path): FileAttributes
    {
        $location = $this->prefixer->prefixPath($path);
        clearstatcache(false, $location);

        error_clear_last();
        $lastModified = @filemtime($location);
        if ($lastModified === false) {
            throw UnableToRetrieveMetadata::lastModified($path, $this->lastError());
        }

        return new FileAttributes($path, lastModified: $lastModified);
    }

    public function fileSize(string $path): FileAttributes
    {
        $location = $this->prefixer->prefixPath($path);
        if (is_dir($location)) {
            throw UnableToRetrieveMetadata::fileSize($path, 'Path is a directory.');
        }

        clearstatcache(false, $location);

        error_clear_last();
        $fileSize = @filesize($location);
        if ($fileSize === false) {
            throw UnableToRetrieveMetadata::fileSize($path, $this->lastError());
        }

        return new FileAttributes($path, fileSize: $fileSize);
    }

    /**
     * @return Generator<int,DirectoryAttributes|FileAttributes>
     */
    public function listContents(string $path, bool $deep): iterable
    {
        $location = $this->prefixer->prefixDirectoryPath($path);
        if (!is_dir($location)) {
            return;
        }

        $iterator = $deep
            ? new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($location, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            )
            : new FilesystemIterator($location, FilesystemIterator::SKIP_DOTS);

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof SplFileInfo) {
                continue;
            }

            $relative = $this->prefixer->stripPrefix($fileInfo->getPathname());

            if ($fileInfo->isDir()) {
                yield new DirectoryAttributes($relative);

                continue;
            }

            $size = $fileInfo->getSize();
            $mtime = $fileInfo->getMTime();

            yield new FileAttributes($relative, $size === false ? null : $size, null, $mtime === false ? null : $mtime);
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $from = $this->prefixer->prefixPath($source);
        $to = $this->prefixer->prefixPath($destination);
        $this->ensureDirectoryExists(dirname($to), $this->visibilityConverter->forDirectory($this->defaultVisibility));

        error_clear_last();
        if (!@rename($from, $to)) {
            throw UnableToMoveFile::fromTo($source, $destination, $this->lastError());
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $from = $this->prefixer->prefixPath($source);
        $to = $this->prefixer->prefixPath($destination);
        $this->ensureDirectoryExists(dirname($to), $this->visibilityConverter->forDirectory($this->defaultVisibility));

        error_clear_last();
        if (!@copy($from, $to)) {
            throw UnableToCopyFile::fromTo($source, $destination, $this->lastError());
        }
    }

    public function checksum(string $path, string $algo): string
    {
        $location = $this->prefixer->prefixPath($path);

        error_clear_last();
        $hash = @hash_file($algo, $location);
        if ($hash === false) {
            throw UnableToRetrieveMetadata::checksum($path, $this->lastError());
        }

        return $hash;
    }

    public function publicUrl(string $path, Config $config): string
    {
        if ($this->publicUrl === null) {
            throw UnableToGeneratePublicUrl::noGeneratorConfigured($path);
        }

        return rtrim($this->publicUrl, '/') . '/' . ltrim($path, '/');
    }

    public function createMultipart(string $path, Config $config): string
    {
        $uploadId = bin2hex(random_bytes(16));
        $this->ensureDirectoryExists(
            dirname($this->prefixer->prefixPath($path)),
            $this->visibilityConverter->forDirectory($this->defaultVisibility),
        );

        return $uploadId;
    }

    public function uploadPart(string $path, string $uploadId, int $partNumber, StreamInterface $chunk, int $length): string
    {
        $partFile = $this->partFile($path, $uploadId, $partNumber);

        error_clear_last();
        $handle = @fopen($partFile, 'w+b');
        if ($handle === false) {
            throw UnableToWriteFile::atLocation($partFile, $this->lastError());
        }

        try {
            $this->pumpInto($handle, $chunk, $partFile);
        } finally {
            fclose($handle);
        }

        return (string) $partNumber;
    }

    public function completeMultipart(string $path, string $uploadId, array $parts): void
    {
        $location = $this->prefixer->prefixPath($path);
        $assembled = $location . '.' . $uploadId . '.assembled';

        error_clear_last();
        $out = @fopen($assembled, 'w+b');
        if ($out === false) {
            throw UnableToWriteFile::atLocation($path, $this->lastError());
        }

        $partNumbers = array_keys($parts);
        sort($partNumbers);

        try {
            foreach ($partNumbers as $partNumber) {
                $this->appendPart($out, $this->partFile($path, $uploadId, $partNumber), $path);
            }
        } finally {
            fclose($out);
        }

        if (!@rename($assembled, $location)) {
            @unlink($assembled);

            throw UnableToWriteFile::atLocation($path, $this->lastError());
        }

        @chmod($location, $this->visibilityConverter->forFile($this->defaultVisibility));
        $this->abortMultipart($path, $uploadId);
    }

    public function abortMultipart(string $path, string $uploadId): void
    {
        $pattern = $this->prefixer->prefixPath($path) . '.phpdot-mpu-' . $uploadId . '.*.part';
        $partFiles = glob($pattern);
        if ($partFiles !== false) {
            foreach ($partFiles as $partFile) {
                @unlink($partFile);
            }
        }

        @unlink($this->prefixer->prefixPath($path) . '.' . $uploadId . '.assembled');
    }

    /**
     * Part file.
     *
     * @param string $path
     * @param string $uploadId
     * @param int $partNumber
     *
     * @return string
     */
    private function partFile(string $path, string $uploadId, int $partNumber): string
    {
        return $this->prefixer->prefixPath($path) . '.phpdot-mpu-' . $uploadId . '.' . $partNumber . '.part';
    }

    /**
     * Append an uploaded part's bytes to the target file.
     *
     * @param resource $out
     * @param string $partFile
     * @param string $path
     *
     * @return void
     */
    private function appendPart($out, string $partFile, string $path): void
    {
        error_clear_last();
        $in = @fopen($partFile, 'rb');
        if ($in === false) {
            throw UnableToWriteFile::atLocation($path, 'Missing upload part: ' . $partFile);
        }

        try {
            if (@stream_copy_to_stream($in, $out) === false) {
                throw UnableToWriteFile::atLocation($path, $this->lastError());
            }
        } finally {
            fclose($in);
        }
    }

    /**
     * Copy a source stream into a destination resource in chunks.
     *
     * @param resource $handle
     * @param StreamInterface $source
     * @param string $path
     *
     * @return void
     */
    private function pumpInto($handle, StreamInterface $source, string $path): void
    {
        while (!$source->eof()) {
            $chunk = $source->read(self::WRITE_BUFFER);
            if ($chunk === '') {
                break;
            }

            if (@fwrite($handle, $chunk) === false) {
                throw UnableToWriteFile::atLocation($path, $this->lastError());
            }
        }
    }

    /**
     * Ensure directory exists.
     *
     * @param string $directory
     * @param int $mode
     *
     * @return void
     */
    private function ensureDirectoryExists(string $directory, int $mode): void
    {
        if (is_dir($directory)) {
            return;
        }

        error_clear_last();
        if (!@mkdir($directory, $mode, true) && !is_dir($directory)) {
            throw UnableToCreateDirectory::atLocation($directory, $this->lastError());
        }

        @chmod($directory, $mode);
    }

    /**
     * Last error.
     *
     * @return string
     */
    private function lastError(): string
    {
        $error = error_get_last();

        return $error['message'] ?? '';
    }
}
