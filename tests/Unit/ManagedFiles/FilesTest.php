<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Tests\Unit\ManagedFiles;

use DateTimeImmutable;
use DateTimeZone;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPdot\Filesystem\Adapter\InMemoryAdapter;
use PHPdot\Filesystem\Exception\FileValidationFailed;
use PHPdot\Filesystem\Filesystem;
use PHPdot\Filesystem\FilesystemConfig;
use PHPdot\Filesystem\ManagedFiles\FileContext;
use PHPdot\Filesystem\ManagedFiles\Files;
use PHPdot\Filesystem\ManagedFiles\FilesFilter;
use PHPdot\Filesystem\Path\PathGenerator;
use PHPdot\Filesystem\Validation\ExtensionValidator;
use PHPdot\Filesystem\Validation\FileSizeValidator;
use PHPdot\Filesystem\Visibility;
use PHPdot\Filesystem\Write\WriteContents;
use PHPUnit\Framework\TestCase;

final class FilesTest extends TestCase
{
    public function testStoreValidatesGeneratesKeyWritesAndTracks(): void
    {
        [$files, $fs] = $this->files();

        $record = $files->store('hello world', new FileContext(
            originalName: 'greeting.txt',
            reference: 'user',
            referenceId: '42',
            tags: ['greeting'],
        ));

        self::assertMatchesRegularExpression('#^\d{4}/\d{2}/\d{2}/[0-9a-f-]{36}\.txt$#', $record->path);
        self::assertSame('greeting.txt', $record->originalName);
        self::assertSame(11, $record->size);
        self::assertSame(hash('sha256', 'hello world'), $record->checksum);
        self::assertSame('user', $record->reference);
        self::assertTrue($fs->fileExists($record->path));
        self::assertSame('hello world', $fs->read($record->path));
    }

    public function testStoreThrowsWhenValidationFails(): void
    {
        [$files] = $this->files();

        $this->expectException(FileValidationFailed::class);

        $files->store('too big', new FileContext(
            originalName: 'x.bin',
            validators: [new FileSizeValidator(maxBytes: 1), new ExtensionValidator(['txt'])],
        ));
    }

    public function testDeleteQuarantinesBytesAndInvalidatesOldPath(): void
    {
        [$files, $fs] = $this->files();
        $record = $files->store('secret', new FileContext(originalName: 'secret.txt', visibility: Visibility::Public));
        $originalPath = $record->path;

        $deleted = $files->delete($record->id);

        self::assertTrue($deleted->isDeleted);
        self::assertFalse($fs->fileExists($originalPath), 'The original (possibly public) key must no longer resolve.');
        self::assertTrue($fs->fileExists($deleted->path));
        self::assertStringStartsWith('.quarantine/', $deleted->path);
        self::assertSame(Visibility::Private, $fs->visibility($deleted->path));
        self::assertSame(Visibility::Public, $deleted->originalVisibility);
    }

    public function testRestoreReversesQuarantine(): void
    {
        [$files, $fs] = $this->files();
        $record = $files->store('data', new FileContext(originalName: 'doc.txt', visibility: Visibility::Public));
        $originalPath = $record->path;

        $files->delete($record->id);
        $restored = $files->restore($record->id);

        self::assertFalse($restored->isDeleted);
        self::assertSame($originalPath, $restored->path);
        self::assertSame(Visibility::Public, $restored->visibility);
        self::assertTrue($fs->fileExists($originalPath));
        self::assertSame('data', $fs->read($originalPath));
    }

    public function testStoreDraftAndPublish(): void
    {
        [$files] = $this->files();

        $draft = $files->storeDraft('draft', new FileContext(originalName: 'd.txt'));
        self::assertTrue($draft->isDraft);
        self::assertNotNull($draft->expiresAt);

        $published = $files->publish($draft->id);
        self::assertFalse($published->isDraft);
        self::assertNull($published->expiresAt);
    }

    public function testPurgeRemovesExpiredDraftsAndOldSoftDeletes(): void
    {
        // Zero TTLs make everything immediately purgeable at a future "now".
        [$files, $fs, $repo] = $this->files(new FilesystemConfig(draftTtl: 0, softDeleteRetention: 0));

        $draft = $files->storeDraft('d', new FileContext(originalName: 'd.txt'));
        $stored = $files->store('s', new FileContext(originalName: 's.txt'));
        $deleted = $files->delete($stored->id);

        $purged = $files->purge(new DateTimeImmutable('now', new DateTimeZone('UTC'))->modify('+10 seconds'));

        self::assertSame(2, $purged);
        self::assertNull($repo->find($draft->id));
        self::assertNull($repo->find($stored->id));
        self::assertFalse($fs->fileExists($draft->path));
        self::assertFalse($fs->fileExists($deleted->path));
    }

    public function testSearchFiltersByReference(): void
    {
        [$files, , $repo] = $this->files();

        $files->store('a', new FileContext(originalName: 'a.txt', reference: 'post', referenceId: '1'));
        $files->store('b', new FileContext(originalName: 'b.txt', reference: 'post', referenceId: '2'));
        $files->store('c', new FileContext(originalName: 'c.txt', reference: 'user', referenceId: '1'));

        $result = $repo->search(new FilesFilter(reference: 'post'));

        self::assertSame(2, $result['total']);
    }

    /**
     * @return array{Files, Filesystem, InMemoryFileRepository}
     */
    private function files(?FilesystemConfig $config = null): array
    {
        $config ??= new FilesystemConfig();
        $factory = new Psr17Factory();
        $fs = new Filesystem(new InMemoryAdapter($factory), new WriteContents($factory), null, null, $config);
        $repo = new InMemoryFileRepository();
        $files = new Files($fs, $repo, new WriteContents($factory), $factory, new PathGenerator(), $config);

        return [$files, $fs, $repo];
    }
}
