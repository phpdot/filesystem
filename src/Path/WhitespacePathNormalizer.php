<?php

declare(strict_types=1);

/**
 * Hardened path normalizer.
 *
 * This is the security boundary for every path that reaches an adapter: it
 * collapses "." / redundant separators, resolves ".." segments, and — unless
 * relative traversal is explicitly allowed — refuses any ".." that would climb
 * above the root. Control characters (e.g. embedded null bytes) are rejected
 * outright.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Path;

use PHPdot\Container\Attribute\Binds;
use PHPdot\Container\Attribute\Singleton;
use PHPdot\Filesystem\Contract\PathNormalizer;
use PHPdot\Filesystem\Exception\CorruptedPathDetected;
use PHPdot\Filesystem\Exception\PathTraversalDetected;

#[Singleton]
#[Binds(PathNormalizer::class)]
final class WhitespacePathNormalizer implements PathNormalizer
{
    /**
     * __construct.
     *
     * @param bool $allowRelativeTraversal
     */
    public function __construct(private readonly bool $allowRelativeTraversal = false) {}

    public function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $this->rejectControlCharacters($path);

        return $this->normalizeRelativePath($path);
    }

    /**
     * Reject control characters.
     *
     * @param string $path
     *
     * @return void
     */
    private function rejectControlCharacters(string $path): void
    {
        if (preg_match('#\p{C}+#u', $path) === 1) {
            throw CorruptedPathDetected::forPath($path);
        }
    }

    /**
     * Normalize relative path.
     *
     * @param string $path
     *
     * @return string
     */
    private function normalizeRelativePath(string $path): string
    {
        /**
         * @var list<string> $parts
         */
        $parts = [];

        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part !== '..') {
                $parts[] = $part;

                continue;
            }

            $last = $parts === [] ? null : $parts[\count($parts) - 1];

            if ($last !== null && $last !== '..') {
                array_pop($parts);

                continue;
            }

            if (!$this->allowRelativeTraversal) {
                throw PathTraversalDetected::forPath($path);
            }

            $parts[] = '..';
        }

        return implode('/', $parts);
    }
}
