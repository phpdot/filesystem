<?php

declare(strict_types=1);

/**
 * The resumable-upload engine driven by both the CLI and the browser endpoint.
 *
 * One mechanism, adapter-specific finalize (S3 CompleteMultipartUpload, Local
 * atomic rename). Each chunk carries an explicit length, so a part request
 * always knows its Content-Length.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Contract;

use DateTimeImmutable;
use PHPdot\Filesystem\Upload\ChunkResult;
use PHPdot\Filesystem\Upload\UploadSession;
use Psr\Http\Message\StreamInterface;

interface UploadManagerInterface
{
    /**
     * Begin a new resumable upload session.
     *
     * @param array<string,mixed> $config
     * @param string $path
     * @param ?int $totalSize
     *
     * @return UploadSession
     */
    public function create(string $path, ?int $totalSize, array $config = []): UploadSession;

    /**
     * Write chunk.
     *
     * @param string $sessionId
     * @param int $offset
     * @param StreamInterface $chunk
     * @param int $length
     *
     * @return ChunkResult
     */
    public function writeChunk(string $sessionId, int $offset, StreamInterface $chunk, int $length): ChunkResult;

    /**
     * Complete.
     *
     * @param string $sessionId
     *
     * @return void
     */
    public function complete(string $sessionId): void;

    /**
     * Abort.
     *
     * @param string $sessionId
     *
     * @return void
     */
    public function abort(string $sessionId): void;

    /**
     * Status.
     *
     * @param string $sessionId
     *
     * @return UploadSession
     */
    public function status(string $sessionId): UploadSession;

    /**
     * Abort and delete every expired session. Returns how many were purged.
     *
     * @param DateTimeImmutable $now
     *
     * @return int
     */
    public function purgeExpired(DateTimeImmutable $now): int;
}
