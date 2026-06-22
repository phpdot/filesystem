<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Tests\Unit;

use Generator;
use PHPdot\Filesystem\Attributes\DirectoryAttributes;
use PHPdot\Filesystem\Attributes\FileAttributes;
use PHPdot\Filesystem\Contract\StorageAttributes;
use PHPdot\Filesystem\DirectoryListing;
use PHPUnit\Framework\TestCase;

final class DirectoryListingTest extends TestCase
{
    /**
     * @return list<StorageAttributes>
     */
    private function sample(): array
    {
        return [
            new FileAttributes('b.txt', 10, 'public', 100, 'text/plain'),
            new DirectoryAttributes('sub'),
            new FileAttributes('a.txt', 20, 'private', 200, 'text/plain'),
        ];
    }

    public function testToArrayPreservesEntries(): void
    {
        self::assertCount(3, (new DirectoryListing($this->sample()))->toArray());
    }

    public function testFilterIsSelective(): void
    {
        $files = (new DirectoryListing($this->sample()))
            ->filter(static fn(StorageAttributes $a): bool => $a->isFile())
            ->toArray();

        self::assertCount(2, $files);
        foreach ($files as $file) {
            self::assertTrue($file->isFile());
        }
    }

    public function testFilterWorksOverAGenerator(): void
    {
        $generator = (static function (): Generator {
            yield new FileAttributes('x');
            yield new DirectoryAttributes('d');
        })();

        $dirs = (new DirectoryListing($generator))
            ->filter(static fn(StorageAttributes $a): bool => $a->isDir())
            ->toArray();

        self::assertCount(1, $dirs);
        self::assertSame('d', $dirs[0]->path());
    }

    public function testMapTransforms(): void
    {
        $paths = iterator_to_array(
            (new DirectoryListing($this->sample()))->map(static fn(StorageAttributes $a): string => $a->path()),
            false,
        );

        self::assertSame(['b.txt', 'sub', 'a.txt'], $paths);
    }

    public function testSortByPath(): void
    {
        $paths = array_map(
            static fn(StorageAttributes $a): string => $a->path(),
            (new DirectoryListing($this->sample()))->sortByPath()->toArray(),
        );

        self::assertSame(['a.txt', 'b.txt', 'sub'], $paths);
    }

    public function testFileAttributesExposeMetadataAndJson(): void
    {
        $file = new FileAttributes('a.txt', 20, 'private', 200, 'text/plain', ['etag' => 'abc']);

        self::assertTrue($file->isFile());
        self::assertFalse($file->isDir());
        self::assertSame(20, $file->fileSize());
        self::assertSame('text/plain', $file->mimeType());
        self::assertSame(200, $file->lastModified());

        $json = $file->jsonSerialize();
        self::assertSame('file', $json['type']);
        self::assertSame('a.txt', $json['path']);
        self::assertSame(20, $json['file_size']);
        self::assertSame(['etag' => 'abc'], $json['extra_metadata']);
    }

    public function testDirectoryAttributesExposeFlagsAndJson(): void
    {
        $dir = new DirectoryAttributes('sub', 'public', 50);

        self::assertTrue($dir->isDir());
        self::assertFalse($dir->isFile());
        self::assertSame(50, $dir->lastModified());
        self::assertSame('dir', $dir->jsonSerialize()['type']);
    }
}
