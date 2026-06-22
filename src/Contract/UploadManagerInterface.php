<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Contract;

use DateTimeImmutable;
use PHPdot\Filesystem\Upload\ChunkResult;
use PHPdot\Filesystem\Upload\UploadSession;
use Psr\Http\Message\StreamInterface;

/**
 * The resumable-upload engine driven by both the CLI and the browser endpoint.
 *
 * One mechanism, adapter-specific finalize (S3 CompleteMultipartUpload, Local
 * atomic rename). Each chunk carries an explicit length, so a part request
 * always knows its Content-Length.
 */
interface UploadManagerInterface
{
    /**
     * @param array<string,mixed> $config
     */
    public function create(string $path, ?int $totalSize, array $config = []): UploadSession;

    public function writeChunk(string $sessionId, int $offset, StreamInterface $chunk, int $length): ChunkResult;

    public function complete(string $sessionId): void;

    public function abort(string $sessionId): void;

    public function status(string $sessionId): UploadSession;

    /**
     * Abort and delete every expired session. Returns how many were purged.
     */
    public function purgeExpired(DateTimeImmutable $now): int;
}
