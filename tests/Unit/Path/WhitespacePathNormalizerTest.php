<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Tests\Unit\Path;

use PHPdot\Filesystem\Exception\CorruptedPathDetected;
use PHPdot\Filesystem\Exception\PathTraversalDetected;
use PHPdot\Filesystem\Path\WhitespacePathNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WhitespacePathNormalizerTest extends TestCase
{
    private WhitespacePathNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new WhitespacePathNormalizer();
    }

    #[DataProvider('traversalProvider')]
    public function testRejectsTraversalThatEscapesRoot(string $path): void
    {
        $this->expectException(PathTraversalDetected::class);

        $this->normalizer->normalizePath($path);
    }

    /**
     * @return iterable<string,array{string}>
     */
    public static function traversalProvider(): iterable
    {
        yield 'bare dotdot' => ['..'];
        yield 'leading dotdot' => ['../foo'];
        yield 'escape after descent' => ['foo/../../bar'];
        yield 'nested escape' => ['a/b/../../../c'];
        yield 'backslash traversal' => ['..\\..\\etc'];
    }

    public function testRejectsControlCharacters(): void
    {
        $this->expectException(CorruptedPathDetected::class);

        $this->normalizer->normalizePath("foo/\0/bar");
    }

    #[DataProvider('normalizationProvider')]
    public function testNormalizes(string $input, string $expected): void
    {
        self::assertSame($expected, $this->normalizer->normalizePath($input));
    }

    /**
     * @return iterable<string,array{string,string}>
     */
    public static function normalizationProvider(): iterable
    {
        yield 'current dir segments' => ['foo/./bar', 'foo/bar'];
        yield 'double slashes' => ['foo//bar', 'foo/bar'];
        yield 'trailing dotdot within root' => ['foo/bar/..', 'foo'];
        yield 'leading and trailing slash' => ['/foo/bar/', 'foo/bar'];
        yield 'backslashes' => ['foo\\bar\\baz', 'foo/bar/baz'];
        yield 'internal descent stays in root' => ['foo/../bar', 'bar'];
        yield 'empty stays root' => ['', ''];
        yield 'dot stays root' => ['.', ''];
    }

    public function testAllowsRelativeTraversalWhenConfigured(): void
    {
        $normalizer = new WhitespacePathNormalizer(allowRelativeTraversal: true);

        self::assertSame('../foo', $normalizer->normalizePath('../foo'));
        self::assertSame('../../foo', $normalizer->normalizePath('../../foo'));
    }
}
