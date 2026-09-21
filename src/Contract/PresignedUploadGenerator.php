<?php

declare(strict_types=1);

/**
 * Capability: the adapter can grant direct uploads through presigned URLs.
 *
 * The grant authorizes one PUT of one key until the expiry. Pinning a content
 * type makes it part of the signature — the client must send it verbatim —
 * which keeps the stored object's type a server decision rather than a client
 * one. Leaving it null signs host only, and the client may store any type:
 * only do that when nothing from the bucket is ever served to a browser.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Contract;

use DateTimeInterface;
use PHPdot\Filesystem\Config;
use PHPdot\Filesystem\Upload\PresignedUpload;

interface PresignedUploadGenerator
{
    /**
     * Grant a direct PUT for one object.
     *
     * @param string $path The object path the grant authorizes
     * @param DateTimeInterface $expiresAt The instant the grant dies
     * @param null|string $contentType Pinned into the signature when given — the client must send it verbatim
     * @param null|string $sha256Base64 The digest S3 verifies against the bytes and stores (base64); when null the object carries no stored checksum
     * @param null|int $size Pinned into the signature when given — the bucket refuses a body of any other length
     * @param Config $config
     *
     * @return PresignedUpload
     */
    public function presignedUpload(string $path, DateTimeInterface $expiresAt, null|string $contentType, null|string $sha256Base64, null|int $size, Config $config): PresignedUpload;

    /**
     * Grant a direct PUT for one part of a multipart upload — the resumable
     * form. Each part is its own grant (partNumber and uploadId signed into
     * the canonical query); the client uploads parts directly and holds no
     * ETags, because completion is built from the storage's own part list.
     *
     * @param string $path The object path the grant authorizes
     * @param string $uploadId The multipart upload the part belongs to
     * @param int $partNumber 1-based part number, ascending
     * @param DateTimeInterface $expiresAt The instant the grant dies
     * @param null|string $contentType Pinned into the signature when given
     * @param null|string $checksumBase64 Signed under the algorithm's header — SHA256 everywhere, CRC64NVME where the storage refuses SHA-256 parts (R2)
     * @param string $checksumAlgorithm SHA256 (default) or CRC64NVME
     * @param null|int $size Pinned into the signature when given — the bucket refuses a body of any other length
     * @param Config $config
     *
     * @return PresignedUpload
     */
    public function presignedPartUpload(string $path, string $uploadId, int $partNumber, DateTimeInterface $expiresAt, null|string $contentType, null|string $checksumBase64, string $checksumAlgorithm, null|int $size, Config $config): PresignedUpload;
}
