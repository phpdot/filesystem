<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Contract;

use DateTimeInterface;
use PHPdot\Filesystem\Config;

/**
 * Capability: the adapter can produce a time-limited (presigned) URL.
 */
interface TemporaryUrlGenerator
{
    public function temporaryUrl(string $path, DateTimeInterface $expiresAt, Config $config): string;
}
