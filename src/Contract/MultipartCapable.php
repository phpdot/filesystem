<?php

declare(strict_types=1);

/**
 * Capability: the adapter supports resumable, multi-part uploads.
 *
 * Every part body is sized and its length passed explicitly, so the transport
 * always knows the Content-Length — an unknown-length stream never reaches a
 * part request.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Contract;

use PHPdot\Filesystem\Config;
use Psr\Http\Message\StreamInterface;

interface MultipartCapable
{
    /**
     * Begin a multipart upload; returns an opaque upload id / handle
     * (an S3 UploadId, or an opaque token for Local).
     *
     * @param string $path
     * @param Config $config
     *
     * @return string
     */
    public function createMultipart(string $path, Config $config): string;

    /**
     * Upload one sized part; returns the identity to retain
     * (an S3 ETag, or an offset marker for Local).
     *
     * @param string $uploadId
     * @param int $partNumber
     * @param StreamInterface $chunk
     * @param int $length
     * @param string $path
     *
     * @return string
     */
    public function uploadPart(string $path, string $uploadId, int $partNumber, StreamInterface $chunk, int $length): string;

    /**
     * Finalize the upload from the retained part identities (ascending order).
     * Parts carrying a checksum are handed to the storage so it can store an
     * object-level checksum; ETag-only parts complete as before.
     *
     * @param array<int,string|array{etag: string, checksumSha256?: null|string, checksumCrc64?: null|string}> $parts partNumber => ETag/marker, or the part with its checksum
     * @param string $path
     * @param string $uploadId
     *
     * @return void
     */
    public function completeMultipart(string $path, string $uploadId, array $parts): void;

    /**
     * The parts the storage itself holds for this upload — the server-side
     * truth a completion should be built from, so what a client claims it
     * uploaded is never trusted, merely checked against this.
     *
     * @param string $path
     * @param string $uploadId
     *
     * @return array<int, array{etag: string, size: int, checksumSha256: null|string, checksumCrc64: null|string}> partNumber => etag/size/checksums
     */
    public function listParts(string $path, string $uploadId): array;

    /**
     * Abort multipart.
     *
     * @param string $path
     * @param string $uploadId
     *
     * @return void
     */
    public function abortMultipart(string $path, string $uploadId): void;
}
