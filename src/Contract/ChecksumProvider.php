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
}
