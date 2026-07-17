<?php

declare(strict_types=1);

/**
 * A fully in-memory adapter: the reference implementation that proves the
 * adapter contract, and a fast, side-effect-free double for tests.
 *
 * Directories are implicit — a directory "exists" when any object lives under
 * its prefix — plus an explicit set for empty directories.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Adapter;

use Generator;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use League\MimeTypeDetection\MimeTypeDetector;
use PHPdot\Filesystem\Attributes\DirectoryAttributes;
use PHPdot\Filesystem\Attributes\FileAttributes;
use PHPdot\Filesystem\Config;
use PHPdot\Filesystem\Contract\AdapterInterface;
use PHPdot\Filesystem\Contract\ChecksumProvider;
use PHPdot\Filesystem\Exception\UnableToCopyFile;
use PHPdot\Filesystem\Exception\UnableToMoveFile;
use PHPdot\Filesystem\Exception\UnableToReadFile;
use PHPdot\Filesystem\Exception\UnableToRetrieveMetadata;
use PHPdot\Filesystem\Exception\UnableToSetVisibility;
use PHPdot\Filesystem\Visibility;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

final class InMemoryAdapter implements AdapterInterface, ChecksumProvider
{
    /**
     * @var array<string,array{contents:string,visibility:string,lastModified:int}>
     */
    private array $files = [];

    /**
     * @var array<string,bool>
     */
    private array $directories = [];

    private readonly MimeTypeDetector $mimeDetector;

    /**
     * __construct.
     *
     * @param StreamFactoryInterface $streamFactory
     * @param string $defaultVisibility
     */
    public function __construct(
        private readonly StreamFactoryInterface $streamFactory,
        private readonly string $defaultVisibility = 'private',
    ) {
        $this->mimeDetector = new FinfoMimeTypeDetector();
    }

    public function fileExists(string $path): bool
    {
        return isset($this->files[$path]);
    }

    public function directoryExists(string $path): bool
    {
        if ($path === '') {
            return true;
        }

        if (isset($this->directories[$path])) {
            return true;
        }

        $prefix = $path . '/';

        foreach (array_keys($this->files) as $key) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        foreach (array_keys($this->directories) as $dir) {
            if (str_starts_with($dir, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public function write(string $path, StreamInterface $contents, Config $config): void
    {
        $this->files[$path] = [
            'contents' => $contents->getContents(),
            'visibility' => $config->getNullableString(Config::VISIBILITY) ?? $this->defaultVisibility,
            'lastModified' => time(),
        ];
    }

    public function read(string $path): string
    {
        if (!isset($this->files[$path])) {
            throw UnableToReadFile::fromLocation($path, 'File does not exist.');
        }

        return $this->files[$path]['contents'];
    }

    public function readStream(string $path): StreamInterface
    {
        $stream = $this->streamFactory->createStream($this->read($path));
        $stream->rewind();

        return $stream;
    }

    public function delete(string $path): void
    {
        unset($this->files[$path]);
    }

    public function deleteDirectory(string $path): void
    {
        $prefix = $path === '' ? '' : rtrim($path, '/') . '/';

        foreach (array_keys($this->files) as $key) {
            if ($prefix === '' || str_starts_with($key, $prefix)) {
                unset($this->files[$key]);
            }
        }

        foreach (array_keys($this->directories) as $dir) {
            if ($prefix === '' || $dir === $path || str_starts_with($dir, $prefix)) {
                unset($this->directories[$dir]);
            }
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
        if ($path !== '') {
            $this->directories[$path] = true;
        }
    }

    public function setVisibility(string $path, string $visibility): void
    {
        if (!isset($this->files[$path])) {
            throw UnableToSetVisibility::atLocation($path, 'File does not exist.');
        }

        $this->files[$path]['visibility'] = Visibility::parse($visibility)->value;
    }

    public function visibility(string $path): FileAttributes
    {
        $file = $this->files[$path] ?? throw UnableToRetrieveMetadata::visibility($path, 'File does not exist.');

        return new FileAttributes($path, visibility: $file['visibility']);
    }

    public function mimeType(string $path): FileAttributes
    {
        $file = $this->files[$path] ?? throw UnableToRetrieveMetadata::mimeType($path, 'File does not exist.');

        return new FileAttributes($path, mimeType: $this->mimeDetector->detectMimeType($path, $file['contents']));
    }

    public function lastModified(string $path): FileAttributes
    {
        $file = $this->files[$path] ?? throw UnableToRetrieveMetadata::lastModified($path, 'File does not exist.');

        return new FileAttributes($path, lastModified: $file['lastModified']);
    }

    public function fileSize(string $path): FileAttributes
    {
        $file = $this->files[$path] ?? throw UnableToRetrieveMetadata::fileSize($path, 'File does not exist.');

        return new FileAttributes($path, fileSize: strlen($file['contents']));
    }

    public function listContents(string $path, bool $deep): iterable
    {
        $prefix = $path === '' ? '' : rtrim($path, '/') . '/';

        return $deep ? $this->listDeep($prefix) : $this->listShallow($prefix);
    }

    public function move(string $source, string $destination, Config $config): void
    {
        if (!isset($this->files[$source])) {
            throw UnableToMoveFile::fromTo($source, $destination, 'Source file does not exist.');
        }

        $this->files[$destination] = $this->files[$source];
        unset($this->files[$source]);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        if (!isset($this->files[$source])) {
            throw UnableToCopyFile::fromTo($source, $destination, 'Source file does not exist.');
        }

        $this->files[$destination] = $this->files[$source];
    }

    public function checksum(string $path, string $algo): string
    {
        $file = $this->files[$path] ?? throw UnableToRetrieveMetadata::checksum($path, 'File does not exist.');

        return hash($algo, $file['contents']);
    }

    /**
     * Return the storage attributes for a stored path.
     *
     * @param array{contents:string,visibility:string,lastModified:int} $file
     * @param string $key
     *
     * @return FileAttributes
     */
    private function fileAttributes(string $key, array $file): FileAttributes
    {
        return new FileAttributes($key, strlen($file['contents']), $file['visibility'], $file['lastModified']);
    }

    /**
     * List a directory's immediate contents.
     *
     * @param string $prefix
     *
     * @return Generator<int,FileAttributes|DirectoryAttributes>
     */
    private function listShallow(string $prefix): Generator
    {
        $emittedDirs = [];

        foreach ($this->files as $key => $file) {
            if ($prefix !== '' && !str_starts_with($key, $prefix)) {
                continue;
            }

            $relative = substr($key, strlen($prefix));
            if ($relative === '') {
                continue;
            }

            $slash = strpos($relative, '/');
            if ($slash === false) {
                yield $this->fileAttributes($key, $file);

                continue;
            }

            $dir = $prefix . substr($relative, 0, $slash);
            if (!isset($emittedDirs[$dir])) {
                $emittedDirs[$dir] = true;

                yield new DirectoryAttributes($dir);
            }
        }

        foreach (array_keys($this->directories) as $explicit) {
            if ($prefix !== '' && !str_starts_with($explicit, $prefix)) {
                continue;
            }

            $relative = substr($explicit, strlen($prefix));
            if ($relative === '' || str_contains($relative, '/') || isset($emittedDirs[$explicit])) {
                continue;
            }

            $emittedDirs[$explicit] = true;

            yield new DirectoryAttributes($explicit);
        }
    }

    /**
     * List a directory's contents recursively.
     *
     * @param string $prefix
     *
     * @return Generator<int,FileAttributes|DirectoryAttributes>
     */
    private function listDeep(string $prefix): Generator
    {
        $emittedDirs = [];

        foreach ($this->files as $key => $file) {
            if ($prefix !== '' && !str_starts_with($key, $prefix)) {
                continue;
            }

            $relative = substr($key, strlen($prefix));
            if ($relative === '') {
                continue;
            }

            $segments = explode('/', $relative);
            $ancestor = rtrim($prefix, '/');

            for ($i = 0, $n = count($segments) - 1; $i < $n; ++$i) {
                $ancestor = $ancestor === '' ? $segments[$i] : $ancestor . '/' . $segments[$i];

                if (!isset($emittedDirs[$ancestor])) {
                    $emittedDirs[$ancestor] = true;

                    yield new DirectoryAttributes($ancestor);
                }
            }

            yield $this->fileAttributes($key, $file);
        }

        foreach (array_keys($this->directories) as $explicit) {
            if ($explicit === rtrim($prefix, '/') || ($prefix !== '' && !str_starts_with($explicit, $prefix))) {
                continue;
            }

            if (!isset($emittedDirs[$explicit])) {
                $emittedDirs[$explicit] = true;

                yield new DirectoryAttributes($explicit);
            }
        }
    }
}
