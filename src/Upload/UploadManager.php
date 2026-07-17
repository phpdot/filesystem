<?php

declare(strict_types=1);

/**
 * The shared resumable-upload engine. Drives the adapter's multipart capability
 * and persists per-session state through the {@see SessionStoreInterface}.
 *
 * Chunks are sequential (tus PATCH semantics): each must continue from the
 * session's current offset, and becomes the next multipart part.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Upload;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PHPdot\Container\Attribute\Binds;
use PHPdot\Container\Attribute\Singleton;
use PHPdot\Filesystem\Config;
use PHPdot\Filesystem\Contract\AdapterInterface;
use PHPdot\Filesystem\Contract\MultipartCapable;
use PHPdot\Filesystem\Contract\SessionStoreInterface;
use PHPdot\Filesystem\Contract\UploadManagerInterface;
use PHPdot\Filesystem\Exception\MultipartUploadFailed;
use PHPdot\Filesystem\Exception\UploadOffsetMismatch;
use PHPdot\Filesystem\Exception\UploadSessionExpired;
use PHPdot\Filesystem\Exception\UploadSessionNotFound;
use PHPdot\Filesystem\FilesystemConfig;
use Psr\Http\Message\StreamInterface;
use Throwable;

#[Singleton]
#[Binds(UploadManagerInterface::class)]
final class UploadManager implements UploadManagerInterface
{
    /**
     * __construct.
     *
     * @param AdapterInterface $adapter
     * @param SessionStoreInterface $store
     * @param FilesystemConfig $config
     */
    public function __construct(
        private readonly AdapterInterface $adapter,
        private readonly SessionStoreInterface $store,
        private readonly FilesystemConfig $config = new FilesystemConfig(),
    ) {}

    public function create(string $path, ?int $totalSize, array $config = []): UploadSession
    {
        $options = new Config($config);
        $uploadId = $this->multipart()->createMultipart($path, $options);

        $session = new UploadSession(
            id: bin2hex(random_bytes(16)),
            path: $path,
            uploadId: $uploadId,
            totalSize: $totalSize,
            bytesReceived: 0,
            parts: [],
            chunkSize: $options->getInt(Config::CHUNK_SIZE, $this->config->chunkSize),
            expiresAt: $this->now()->add(new DateInterval('PT' . $this->config->sessionTtl . 'S')),
        );

        $this->store->put($session);

        return $session;
    }

    public function writeChunk(string $sessionId, int $offset, StreamInterface $chunk, int $length): ChunkResult
    {
        $session = $this->requireSession($sessionId);

        if ($offset !== $session->bytesReceived) {
            throw UploadOffsetMismatch::expected($session->bytesReceived, $offset);
        }

        $partNumber = count($session->parts) + 1;
        $identity = $this->multipart()->uploadPart($session->path, $session->uploadId, $partNumber, $chunk, $length);

        $session = $session
            ->withPart($partNumber, $identity)
            ->withBytesReceived($session->bytesReceived + $length);

        $this->store->put($session);

        return new ChunkResult($session->bytesReceived, $session->isComplete());
    }

    public function complete(string $sessionId): void
    {
        $session = $this->requireSession($sessionId);

        if ($session->parts === []) {
            throw MultipartUploadFailed::withReason('Cannot complete an upload with no parts.');
        }

        $this->multipart()->completeMultipart($session->path, $session->uploadId, $session->parts);
        $this->store->delete($sessionId);
    }

    public function abort(string $sessionId): void
    {
        $session = $this->requireSession($sessionId);

        $this->multipart()->abortMultipart($session->path, $session->uploadId);
        $this->store->delete($sessionId);
    }

    public function status(string $sessionId): UploadSession
    {
        return $this->requireSession($sessionId);
    }

    public function purgeExpired(DateTimeImmutable $now): int
    {
        $purged = 0;

        foreach ($this->store->expired($now) as $session) {
            try {
                $this->multipart()->abortMultipart($session->path, $session->uploadId);
            } catch (Throwable) {
            }

            $this->store->delete($session->id);
            ++$purged;
        }

        return $purged;
    }

    /**
     * Require session.
     *
     * @param string $sessionId
     *
     * @return UploadSession
     */
    private function requireSession(string $sessionId): UploadSession
    {
        $session = $this->store->find($sessionId);
        if ($session === null) {
            throw UploadSessionNotFound::withId($sessionId);
        }

        if ($session->isExpired($this->now())) {
            throw UploadSessionExpired::withId($sessionId);
        }

        return $session;
    }

    /**
     * Multipart.
     *
     * @return MultipartCapable
     */
    private function multipart(): MultipartCapable
    {
        if (!$this->adapter instanceof MultipartCapable) {
            throw MultipartUploadFailed::withReason('The configured adapter does not support multipart uploads.');
        }

        return $this->adapter;
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
}
