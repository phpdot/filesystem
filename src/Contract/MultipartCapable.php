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
     *
     * @param array<int,string> $parts partNumber => ETag/marker
     * @param string $path
     * @param string $uploadId
     *
     * @return void
     */
    public function completeMultipart(string $path, string $uploadId, array $parts): void;

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
