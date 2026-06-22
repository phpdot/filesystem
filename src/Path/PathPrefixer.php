<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Path;

/**
 * Joins a normalized, root-relative path onto a backend root (a local
 * directory, or an S3 key prefix) and strips it back off again for listings.
 */
final class PathPrefixer
{
    private readonly string $prefix;

    public function __construct(string $prefix, private readonly string $separator = '/')
    {
        $trimmed = rtrim($prefix, '\\/');

        if ($trimmed !== '' || $prefix === $this->separator) {
            $trimmed .= $this->separator;
        }

        $this->prefix = $trimmed;
    }

    public function prefixPath(string $path): string
    {
        return $this->prefix . ltrim($path, '\\/');
    }

    public function stripPrefix(string $path): string
    {
        return substr($path, strlen($this->prefix));
    }

    public function stripDirectoryPrefix(string $path): string
    {
        return rtrim($this->stripPrefix($path), '\\/');
    }

    public function prefixDirectoryPath(string $path): string
    {
        $prefixed = $this->prefixPath(rtrim($path, '\\/'));

        if ($prefixed === '' || str_ends_with($prefixed, $this->separator)) {
            return $prefixed;
        }

        return $prefixed . $this->separator;
    }
}
