<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Tests\Unit\Path;

use PHPdot\Filesystem\Path\PathPrefixer;
use PHPUnit\Framework\TestCase;

final class PathPrefixerTest extends TestCase
{
    public function testPrefixAndStripRoundTrip(): void
    {
        $prefixer = new PathPrefixer('/var/storage');

        self::assertSame('/var/storage/a/b.txt', $prefixer->prefixPath('a/b.txt'));
        self::assertSame('a/b.txt', $prefixer->stripPrefix('/var/storage/a/b.txt'));
    }

    public function testEmptyPrefixIsTransparent(): void
    {
        $prefixer = new PathPrefixer('');

        self::assertSame('a/b.txt', $prefixer->prefixPath('a/b.txt'));
        self::assertSame('a/b.txt', $prefixer->stripPrefix('a/b.txt'));
    }

    public function testTrimsRedundantSlashes(): void
    {
        $prefixer = new PathPrefixer('root/');

        self::assertSame('root/a', $prefixer->prefixPath('/a'));
    }

    public function testPrefixDirectoryPathEnsuresTrailingSeparator(): void
    {
        $prefixer = new PathPrefixer('/var/storage');

        self::assertSame('/var/storage/sub/', $prefixer->prefixDirectoryPath('sub'));
    }

    public function testStripDirectoryPrefixRemovesTrailingSlash(): void
    {
        $prefixer = new PathPrefixer('/var/storage');

        self::assertSame('sub', $prefixer->stripDirectoryPrefix('/var/storage/sub/'));
    }

    public function testCustomSeparator(): void
    {
        $prefixer = new PathPrefixer('bucket', '/');

        self::assertSame('bucket/key', $prefixer->prefixPath('key'));
    }
}
