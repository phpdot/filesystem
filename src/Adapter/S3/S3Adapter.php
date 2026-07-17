<?php

declare(strict_types=1);

/**
 * The S3-compatible adapter.
 *
 * Directories are implicit (synthesized from key prefixes); object visibility
 * is bucket-policy controlled in v1 (ACLs are not managed, since modern AWS and
 * R2 reject them). Not auto-bound to the container — it needs credentials, so
 * the app binds it explicitly to swap out the default LocalAdapter.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Adapter\S3;

use DateTimeInterface;
use Generator;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use League\MimeTypeDetection\MimeTypeDetector;
use PHPdot\Filesystem\Attributes\DirectoryAttributes;
use PHPdot\Filesystem\Attributes\FileAttributes;
use PHPdot\Filesystem\Config;
use PHPdot\Filesystem\Contract\AdapterInterface;
use PHPdot\Filesystem\Contract\ChecksumProvider;
use PHPdot\Filesystem\Contract\MultipartCapable;
use PHPdot\Filesystem\Contract\PublicUrlGenerator;
use PHPdot\Filesystem\Contract\TemporaryUrlGenerator;
use PHPdot\Filesystem\Exception\S3RequestFailed;
use PHPdot\Filesystem\Exception\UnableToCheckExistence;
use PHPdot\Filesystem\Exception\UnableToCopyFile;
use PHPdot\Filesystem\Exception\UnableToDeleteFile;
use PHPdot\Filesystem\Exception\UnableToMoveFile;
use PHPdot\Filesystem\Exception\UnableToReadFile;
use PHPdot\Filesystem\Exception\UnableToRetrieveMetadata;
use PHPdot\Filesystem\Path\PathPrefixer;
use Psr\Http\Message\StreamInterface;

final class S3Adapter implements AdapterInterface, ChecksumProvider, MultipartCapable, PublicUrlGenerator, TemporaryUrlGenerator
{
    private const HASH_BUFFER = 1048576;

    private readonly PathPrefixer $prefixer;
    private readonly MimeTypeDetector $mimeDetector;

    /**
     * __construct.
     *
     * @param S3Client $client
     * @param S3Config $config
     * @param string $defaultVisibility
     */
    public function __construct(
        private readonly S3Client $client,
        private readonly S3Config $config,
        private readonly string $defaultVisibility = 'private',
    ) {
        $this->prefixer = new PathPrefixer($config->prefix);
        $this->mimeDetector = new FinfoMimeTypeDetector();
    }

    public function fileExists(string $path): bool
    {
        try {
            $this->client->headObject($this->prefixer->prefixPath($path));

            return true;
        } catch (S3RequestFailed $exception) {
            if ($exception->status() === 404) {
                return false;
            }

            throw UnableToCheckExistence::forLocation($path, $exception);
        }
    }

    public function directoryExists(string $path): bool
    {
        foreach ($this->client->listObjectsV2($this->directoryPrefix($path), true) as $ignored) {
            return true;
        }

        return false;
    }

    public function write(string $path, StreamInterface $contents, Config $config): void
    {
        $headers = [];
        $mimeType = $config->getNullableString(Config::MIME_TYPE) ?? $this->mimeDetector->detectMimeTypeFromPath($path);
        if ($mimeType !== null) {
            $headers['Content-Type'] = $mimeType;
        }

        $this->client->putObject($this->prefixer->prefixPath($path), $contents, $contents->getSize(), $headers);
    }

    public function read(string $path): string
    {
        try {
            return $this->client->getObject($this->prefixer->prefixPath($path))->getContents();
        } catch (S3RequestFailed $exception) {
            throw UnableToReadFile::fromLocation($path, $exception->getMessage(), $exception);
        }
    }

    public function readStream(string $path): StreamInterface
    {
        try {
            return $this->client->getObject($this->prefixer->prefixPath($path));
        } catch (S3RequestFailed $exception) {
            throw UnableToReadFile::fromLocation($path, $exception->getMessage(), $exception);
        }
    }

    public function delete(string $path): void
    {
        try {
            $this->client->deleteObject($this->prefixer->prefixPath($path));
        } catch (S3RequestFailed $exception) {
            throw UnableToDeleteFile::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    public function deleteDirectory(string $path): void
    {
        foreach ($this->client->listObjectsV2($this->directoryPrefix($path), true) as $entry) {
            if (!$entry['isPrefix']) {
                $this->client->deleteObject($entry['key']);
            }
        }
    }

    public function createDirectory(string $path, Config $config): void {}

    public function setVisibility(string $path, string $visibility): void {}

    public function visibility(string $path): FileAttributes
    {
        return new FileAttributes($path, visibility: $this->defaultVisibility);
    }

    public function mimeType(string $path): FileAttributes
    {
        $metadata = $this->head($path, 'mimeType');
        $mimeType = $metadata['mimeType'] ?? $this->mimeDetector->detectMimeTypeFromPath($path);

        if ($mimeType === null) {
            throw UnableToRetrieveMetadata::mimeType($path, 'Unable to determine the mime type.');
        }

        return new FileAttributes($path, mimeType: $mimeType);
    }

    public function lastModified(string $path): FileAttributes
    {
        return new FileAttributes($path, lastModified: $this->head($path, 'lastModified')['lastModified']);
    }

    public function fileSize(string $path): FileAttributes
    {
        return new FileAttributes($path, fileSize: $this->head($path, 'fileSize')['size']);
    }

    /**
     * @return Generator<int,DirectoryAttributes|FileAttributes>
     */
    public function listContents(string $path, bool $deep): iterable
    {
        foreach ($this->client->listObjectsV2($this->directoryPrefix($path), $deep) as $entry) {
            $relativePath = $this->prefixer->stripPrefix($entry['key']);

            if ($entry['isPrefix']) {
                yield new DirectoryAttributes(rtrim($relativePath, '/'));

                continue;
            }

            yield new FileAttributes($relativePath, $entry['size'], null, $entry['lastModified']);
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        try {
            $this->client->copyObject($this->prefixer->prefixPath($source), $this->prefixer->prefixPath($destination));
            $this->client->deleteObject($this->prefixer->prefixPath($source));
        } catch (S3RequestFailed $exception) {
            throw UnableToMoveFile::fromTo($source, $destination, $exception->getMessage(), $exception);
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        try {
            $this->client->copyObject($this->prefixer->prefixPath($source), $this->prefixer->prefixPath($destination));
        } catch (S3RequestFailed $exception) {
            throw UnableToCopyFile::fromTo($source, $destination, $exception->getMessage(), $exception);
        }
    }

    public function checksum(string $path, string $algo): string
    {
        try {
            $stream = $this->client->getObject($this->prefixer->prefixPath($path));
        } catch (S3RequestFailed $exception) {
            throw UnableToRetrieveMetadata::checksum($path, $exception->getMessage(), $exception);
        }

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

    public function publicUrl(string $path, Config $config): string
    {
        $key = $this->prefixer->prefixPath($path);

        if ($this->config->publicUrl !== null) {
            return rtrim($this->config->publicUrl, '/') . '/' . ltrim($key, '/');
        }

        return $this->client->objectUrl($key);
    }

    public function temporaryUrl(string $path, DateTimeInterface $expiresAt, Config $config): string
    {
        return $this->client->presign($this->prefixer->prefixPath($path), $expiresAt);
    }

    public function createMultipart(string $path, Config $config): string
    {
        $headers = [];
        $mimeType = $config->getNullableString(Config::MIME_TYPE) ?? $this->mimeDetector->detectMimeTypeFromPath($path);
        if ($mimeType !== null) {
            $headers['Content-Type'] = $mimeType;
        }

        return $this->client->createMultipartUpload($this->prefixer->prefixPath($path), $headers);
    }

    public function uploadPart(string $path, string $uploadId, int $partNumber, StreamInterface $chunk, int $length): string
    {
        return $this->client->uploadPart($this->prefixer->prefixPath($path), $uploadId, $partNumber, $chunk, $length);
    }

    public function completeMultipart(string $path, string $uploadId, array $parts): void
    {
        $this->client->completeMultipartUpload($this->prefixer->prefixPath($path), $uploadId, $parts);
    }

    public function abortMultipart(string $path, string $uploadId): void
    {
        $this->client->abortMultipartUpload($this->prefixer->prefixPath($path), $uploadId);
    }

    /**
     * Fetch an object's metadata via a HEAD request.
     *
     * @param 'fileSize'|'lastModified'|'mimeType' $type
     * @param string $path
     *
     * @return array{size: int, lastModified: ?int, mimeType: ?string, etag: string}
     */
    private function head(string $path, string $type): array
    {
        try {
            return $this->client->headObject($this->prefixer->prefixPath($path));
        } catch (S3RequestFailed $exception) {
            throw match ($type) {
                'fileSize' => UnableToRetrieveMetadata::fileSize($path, $exception->getMessage(), $exception),
                'lastModified' => UnableToRetrieveMetadata::lastModified($path, $exception->getMessage(), $exception),
                'mimeType' => UnableToRetrieveMetadata::mimeType($path, $exception->getMessage(), $exception),
            };
        }
    }

    /**
     * Directory prefix.
     *
     * @param string $path
     *
     * @return string
     */
    private function directoryPrefix(string $path): string
    {
        $prefix = $this->prefixer->prefixPath($path);

        return $prefix === '' ? '' : rtrim($prefix, '/') . '/';
    }
}
