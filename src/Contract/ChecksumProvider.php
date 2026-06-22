<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Contract;

/**
 * Capability: the adapter can compute a content checksum cheaply (e.g. from a
 * stored ETag). Absent it, the operator streams the file and hashes it itself.
 */
interface ChecksumProvider
{
    public function checksum(string $path, string $algo): string;
}
