<?php

declare(strict_types=1);

/**
 * Joins a normalized, root-relative path onto a backend root (a local
 * directory, or an S3 key prefix) and strips it back off again for listings.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Path;

final class PathPrefixer
{
    private readonly string $prefix;

    /**
     * __construct.
     *
     * @param string $prefix
     * @param string $separator
     */
    public function __construct(string $prefix, private readonly string $separator = '/')
    {
        $trimmed = rtrim($prefix, '\\/');

        if ($trimmed !== '' || $prefix === $this->separator) {
            $trimmed .= $this->separator;
        }

        $this->prefix = $trimmed;
    }

    /**
     * Prefix path.
     *
     * @param string $path
     *
     * @return string
     */
    public function prefixPath(string $path): string
    {
        return $this->prefix . ltrim($path, '\\/');
    }

    /**
     * Strip prefix.
     *
     * @param string $path
     *
     * @return string
     */
    public function stripPrefix(string $path): string
    {
        return substr($path, strlen($this->prefix));
    }

    /**
     * Strip directory prefix.
     *
     * @param string $path
     *
     * @return string
     */
    public function stripDirectoryPrefix(string $path): string
    {
        return rtrim($this->stripPrefix($path), '\\/');
    }

    /**
     * Prefix directory path.
     *
     * @param string $path
     *
     * @return string
     */
    public function prefixDirectoryPath(string $path): string
    {
        $prefixed = $this->prefixPath(rtrim($path, '\\/'));

        if ($prefixed === '' || str_ends_with($prefixed, $this->separator)) {
            return $prefixed;
        }

        return $prefixed . $this->separator;
    }
}
