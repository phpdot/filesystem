<?php

declare(strict_types=1);

/**
 * Capability: the adapter can produce a time-limited (presigned) URL.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Contract;

use DateTimeInterface;
use PHPdot\Filesystem\Config;

interface TemporaryUrlGenerator
{
    /**
     * Temporary url.
     *
     * @param string $path
     * @param DateTimeInterface $expiresAt
     * @param Config $config
     *
     * @return string
     */
    public function temporaryUrl(string $path, DateTimeInterface $expiresAt, Config $config): string;
}
