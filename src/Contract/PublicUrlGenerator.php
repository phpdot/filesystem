<?php

declare(strict_types=1);

/**
 * Capability: the adapter can produce a stable public URL for a path.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Contract;

use PHPdot\Filesystem\Config;

interface PublicUrlGenerator
{
    /**
     * Public url.
     *
     * @param string $path
     * @param Config $config
     *
     * @return string
     */
    public function publicUrl(string $path, Config $config): string;
}
