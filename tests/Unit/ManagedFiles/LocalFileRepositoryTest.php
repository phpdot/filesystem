<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Tests\Unit\ManagedFiles;

use DateTimeImmutable;
use DateTimeZone;
use PHPdot\Filesystem\FilesystemConfig;
use PHPdot\Filesystem\ManagedFiles\FileRecord;
use PHPdot\Filesystem\ManagedFiles\FilesFilter;
use PHPdot\Filesystem\ManagedFiles\LocalFileRepository;
use PHPdot\Filesystem\Visibility;
use PHPUnit\Framework\TestCase;

final class LocalFileRepositoryTest extends TestCase
{
    private string $dir;
    private LocalFileRepository $repo;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/phpdot-fs-files-' . bin2hex(random_bytes(6));
        $this->repo = new LocalFileRepository(new FilesystemConfig(fileRecordsDirectory: $this->dir));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function testSaveFindRoundTripPreservesEveryField(): void
    {
        $record = $this->record('abc', tags: ['a', 'b']);
        $this->repo->save($record);

        $found = $this->repo->find('abc');

        self::assertNotNull($found);
        self::assertSame('abc', $found->id);
        self::assertSame('2024/01/01/abc.txt', $found->path);
        self::assertSame('orig.txt', $found->originalName);
        self::assertSame(123, $found->size);
        self::assertSame('text/plain', $found->mimeType);
        self::assertSame('deadbeef', $found->checksum);
        self::assertSame(Visibility::Public, $found->visibility);
        self::assertSame(['a', 'b'], $found->tags);
        self::assertSame('post', $found->reference);
        self::assertSame('7', $found->referenceId);
    }

    public function testFindByPath(): void
    {
        $this->repo->save($this->record('x'));

        self::assertNotNull($this->repo->findByPath('2024/01/01/x.txt'));
        self::assertNull($this->repo->findByPath('nope'));
    }

    public function testSearchFiltersPagesAndCounts(): void
    {
        foreach (['a', 'b', 'c'] as $i => $id) {
            $this->repo->save($this->record($id, reference: $i === 2 ? 'other' : 'post'));
        }

        $result = $this->repo->search(new FilesFilter(reference: 'post'), limit: 1, offset: 0);

        self::assertSame(2, $result['total']);
        self::assertCount(1, $result['records']);
    }

    public function testSoftDeleteFlagsRecordAndHardDeleteRemovesIt(): void
    {
        $this->repo->save($this->record('gone'));

        $this->repo->softDelete('gone');
        $afterSoft = $this->repo->find('gone');
        self::assertNotNull($afterSoft);
        self::assertTrue($afterSoft->isDeleted);
        self::assertNotNull($afterSoft->deletedAt);

        $this->repo->hardDelete('gone');
        self::assertNull($this->repo->find('gone'));
    }

    public function testQuarantineFieldsSurviveRoundTrip(): void
    {
        $record = $this->record('q', visibility: Visibility::Public);
        $quarantined = $record->quarantined('.quarantine/abc123', new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $this->repo->save($quarantined);

        $found = $this->repo->find('q');

        self::assertNotNull($found);
        self::assertSame('.quarantine/abc123', $found->path);
        self::assertSame(Visibility::Private, $found->visibility);
        self::assertSame(Visibility::Public, $found->originalVisibility);
        self::assertSame('2024/01/01/q.txt', $found->originalPath);
        self::assertTrue($found->isDeleted);
    }

    /**
     * @param list<string> $tags
     */
    private function record(string $id, ?string $reference = 'post', array $tags = [], Visibility $visibility = Visibility::Public): FileRecord
    {
        return new FileRecord(
            id: $id,
            path: '2024/01/01/' . $id . '.txt',
            originalName: 'orig.txt',
            size: 123,
            mimeType: 'text/plain',
            checksum: 'deadbeef',
            visibility: $visibility,
            createdAt: new DateTimeImmutable('2024-01-01T00:00:00Z'),
            reference: $reference,
            referenceId: '7',
            tags: $tags,
        );
    }
}
