<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Contract;

use PHPdot\Filesystem\Config;

/**
 * Capability: the adapter can produce a stable public URL for a path.
 */
interface PublicUrlGenerator
{
    public function publicUrl(string $path, Config $config): string;
}
