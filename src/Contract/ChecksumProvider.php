<?php

declare(strict_types=1);

/**
 * Capability: the adapter can compute a content checksum cheaply (e.g. from a
 * stored ETag). Absent it, the operator streams the file and hashes it itself.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Contract;

interface ChecksumProvider
{
    /**
     * Checksum.
     *
     * @param string $path
     * @param string $algo
     *
     * @return string
     */
    public function checksum(string $path, string $algo): string;

    /**
     * The fingerprint the storage itself holds for the object, prefixed with
     * its algorithm ("sha256:<hex>", "crc64nvme:<base64>"), read from metadata
     * alone — never by reading the bytes. Null when nothing is stored; an
     * adapter whose storage keeps no fingerprint answers null always.
     *
     * @param string $path
     *
     * @return null|string
     */
    public function storedChecksum(string $path): null|string;
}
