<?php

declare(strict_types=1);

/**
 * The one concrete operator the developer uses.
 *
 * Normalizes paths, collapses the write-input union to a stream, wires progress
 * and PSR-14 events, and unwraps adapter {@see Attributes\FileAttributes} to
 * scalars. Optional capabilities (checksum, URLs) are probed with `instanceof`
 * and gracefully degraded.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem;

use Closure;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use PHPdot\Container\Attribute\Binds;
use PHPdot\Container\Attribute\Singleton;
use PHPdot\Filesystem\Contract\AdapterInterface;
use PHPdot\Filesystem\Contract\ChecksumProvider;
use PHPdot\Filesystem\Contract\FilesystemInterface;
use PHPdot\Filesystem\Contract\PathNormalizer;
use PHPdot\Filesystem\Contract\PublicUrlGenerator;
use PHPdot\Filesystem\Contract\TemporaryUrlGenerator;
use PHPdot\Filesystem\Event\UploadCompleted;
use PHPdot\Filesystem\Event\UploadFailed;
use PHPdot\Filesystem\Event\UploadProgressed;
use PHPdot\Filesystem\Exception\UnableToGeneratePublicUrl;
use PHPdot\Filesystem\Exception\UnableToGenerateTemporaryUrl;
use PHPdot\Filesystem\Exception\UnableToRetrieveMetadata;
use PHPdot\Filesystem\Path\WhitespacePathNormalizer;
use PHPdot\Filesystem\Stream\ProgressStream;
use PHPdot\Filesystem\Write\WriteContents;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;

#[Singleton]
#[Binds(FilesystemInterface::class)]
final class Filesystem implements FilesystemInterface
{
    private const HASH_BUFFER = 1048576;

    private readonly PathNormalizer $normalizer;

    /**
     * __construct.
     *
     * @param AdapterInterface $adapter
     * @param WriteContents $writeContents
     * @param ?PathNormalizer $normalizer
     * @param ?EventDispatcherInterface $events
     * @param FilesystemConfig $config
     */
    public function __construct(
        private readonly AdapterInterface $adapter,
        private readonly WriteContents $writeContents,
        ?PathNormalizer $normalizer = null,
        private readonly ?EventDispatcherInterface $events = null,
        private readonly FilesystemConfig $config = new FilesystemConfig(),
    ) {
        $this->normalizer = $normalizer ?? new WhitespacePathNormalizer();
    }

    public function fileExists(string $path): bool
    {
        return $this->adapter->fileExists($this->normalizer->normalizePath($path));
    }

    public function directoryExists(string $path): bool
    {
        return $this->adapter->directoryExists($this->normalizer->normalizePath($path));
    }

    public function has(string $path): bool
    {
        $normalized = $this->normalizer->normalizePath($path);

        return $this->adapter->fileExists($normalized) || $this->adapter->directoryExists($normalized);
    }

    public function read(string $path): string
    {
        return $this->adapter->read($this->normalizer->normalizePath($path));
    }

    public function readStream(string $path): StreamInterface
    {
        return $this->adapter->readStream($this->normalizer->normalizePath($path));
    }

    public function listContents(string $path, bool $deep = false): DirectoryListing
    {
        return new DirectoryListing($this->adapter->listContents($this->normalizer->normalizePath($path), $deep));
    }

    public function fileSize(string $path): int
    {
        $normalized = $this->normalizer->normalizePath($path);
        $size = $this->adapter->fileSize($normalized)->fileSize();

        if ($size === null) {
            throw UnableToRetrieveMetadata::fileSize($path, 'The adapter returned no size.');
        }

        return $size;
    }

    public function lastModified(string $path): int
    {
        $normalized = $this->normalizer->normalizePath($path);
        $lastModified = $this->adapter->lastModified($normalized)->lastModified();

        if ($lastModified === null) {
            throw UnableToRetrieveMetadata::lastModified($path, 'The adapter returned no timestamp.');
        }

        return $lastModified;
    }

    public function mimeType(string $path): string
    {
        $normalized = $this->normalizer->normalizePath($path);
        $mimeType = $this->adapter->mimeType($normalized)->mimeType();

        if ($mimeType === null) {
            throw UnableToRetrieveMetadata::mimeType($path, 'The adapter returned no mime type.');
        }

        return $mimeType;
    }

    public function checksum(string $path, string $algo = 'sha256'): string
    {
        $normalized = $this->normalizer->normalizePath($path);

        if ($this->adapter instanceof ChecksumProvider) {
            return $this->adapter->checksum($normalized, $algo);
        }

        $stream = $this->adapter->readStream($normalized);
        $context = hash_init($algo);
        while (!$stream->eof()) {
            $chunk = $stream->read(self::HASH_BUFFER);
            if ($chunk === '') {
                break;
            }
            hash_update($context, $chunk);
        }

        return hash_final($context);
    }

    public function visibility(string $path): Visibility
    {
        $normalized = $this->normalizer->normalizePath($path);
        $visibility = $this->adapter->visibility($normalized)->visibility();

        if ($visibility === null) {
            throw UnableToRetrieveMetadata::visibility($path, 'The adapter returned no visibility.');
        }

        return Visibility::parse($visibility);
    }

    public function write(string $path, string|StreamInterface|UploadedFileInterface $contents, array $config = []): void
    {
        $normalized = $this->normalizer->normalizePath($path);
        $options = new Config($config);

        $stream = $this->writeContents->normalize($contents);
        $total = $stream->getSize();
        $stream = $this->wrapWithProgress($stream, $normalized, $options, $total);

        try {
            $this->adapter->write($normalized, $stream, $options);
        } catch (Throwable $throwable) {
            $this->events?->dispatch(new UploadFailed($normalized, $throwable));

            throw $throwable;
        }

        $bytesWritten = $stream instanceof ProgressStream ? $stream->bytesSeen() : ($total ?? 0);
        $this->events?->dispatch(new UploadCompleted($normalized, $bytesWritten));
    }

    public function setVisibility(string $path, Visibility $visibility): void
    {
        $this->adapter->setVisibility($this->normalizer->normalizePath($path), $visibility->value);
    }

    public function delete(string $path): void
    {
        $this->adapter->delete($this->normalizer->normalizePath($path));
    }

    public function deleteDirectory(string $path): void
    {
        $this->adapter->deleteDirectory($this->normalizer->normalizePath($path));
    }

    public function createDirectory(string $path, array $config = []): void
    {
        $this->adapter->createDirectory($this->normalizer->normalizePath($path), new Config($config));
    }

    public function move(string $source, string $destination, array $config = []): void
    {
        $this->adapter->move(
            $this->normalizer->normalizePath($source),
            $this->normalizer->normalizePath($destination),
            new Config($config),
        );
    }

    public function copy(string $source, string $destination, array $config = []): void
    {
        $this->adapter->copy(
            $this->normalizer->normalizePath($source),
            $this->normalizer->normalizePath($destination),
            new Config($config),
        );
    }

    public function publicUrl(string $path, array $config = []): string
    {
        $normalized = $this->normalizer->normalizePath($path);

        if (!$this->adapter instanceof PublicUrlGenerator) {
            throw UnableToGeneratePublicUrl::noGeneratorConfigured($normalized);
        }

        return $this->adapter->publicUrl($normalized, new Config($config));
    }

    public function temporaryUrl(string $path, DateTimeInterface $expiresAt, array $config = []): string
    {
        $normalized = $this->normalizer->normalizePath($path);

        if (!$this->adapter instanceof TemporaryUrlGenerator) {
            throw UnableToGenerateTemporaryUrl::notSupported($normalized);
        }

        return $this->adapter->temporaryUrl($normalized, $expiresAt, new Config($config));
    }

    public function url(string $path, array $config = []): string
    {
        if ($this->supportsPublicUrls() && $this->visibility($path)->isPublic()) {
            return $this->publicUrl($path, $config);
        }

        if ($this->supportsTemporaryUrls()) {
            return $this->temporaryUrl($path, $this->urlExpiry($config), $config);
        }

        return $this->publicUrl($path, $config);
    }

    public function supportsPublicUrls(): bool
    {
        return $this->adapter instanceof PublicUrlGenerator;
    }

    public function supportsTemporaryUrls(): bool
    {
        return $this->adapter instanceof TemporaryUrlGenerator;
    }

    /**
     * Resolve the effective expiry timestamp for a temporary URL.
     *
     * @param array<string,mixed> $config
     *
     * @return DateTimeInterface
     */
    private function urlExpiry(array $config): DateTimeInterface
    {
        $expires = $config[Config::EXPIRES_AT] ?? null;
        if ($expires instanceof DateTimeInterface) {
            return $expires;
        }

        return $this->now()->add(new DateInterval('PT' . $this->config->temporaryUrlTtl . 'S'));
    }

    /**
     * Now.
     *
     * @return DateTimeImmutable
     */
    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /**
     * Wrap with progress.
     *
     * @param StreamInterface $stream
     * @param string $path
     * @param Config $options
     * @param ?int $total
     *
     * @return StreamInterface
     */
    private function wrapWithProgress(StreamInterface $stream, string $path, Config $options, ?int $total): StreamInterface
    {
        $onProgress = $options->getCallable(Config::PROGRESS);
        $events = $this->events;

        if ($onProgress === null && $events === null) {
            return $stream;
        }

        $callback = static function (int $soFar, ?int $totalBytes) use ($onProgress, $events, $path): void {
            if ($onProgress !== null) {
                $onProgress($soFar, $totalBytes);
            }

            $events?->dispatch(new UploadProgressed($path, $soFar, $totalBytes));
        };

        return new ProgressStream($stream, Closure::fromCallable($callback), $total);
    }
}
