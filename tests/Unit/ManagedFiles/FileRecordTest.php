<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Tests\Unit\ManagedFiles;

use DateTimeImmutable;
use DateTimeZone;
use PHPdot\Filesystem\ManagedFiles\FileRecord;
use PHPdot\Filesystem\Visibility;
use PHPUnit\Framework\TestCase;

final class FileRecordTest extends TestCase
{
    public function testWithContentPreservesDraftExpiry(): void
    {
        $expires = new DateTimeImmutable('+1 day', new DateTimeZone('UTC'));
        $draft = $this->record()->withDraft(true, $expires);

        $filled = $draft->withContent(999, 'image/png', 'abc');

        self::assertSame(999, $filled->size);
        self::assertSame('image/png', $filled->mimeType);
        self::assertTrue($filled->isDraft, 'withContent must not clear the draft flag.');
        self::assertSame($expires, $filled->expiresAt, 'withContent must not wipe the expiry.');
    }

    public function testWithDraftFalseClearsExpiry(): void
    {
        $published = $this->record()->withDraft(true, new DateTimeImmutable('+1 day'))->withDraft(false, null);

        self::assertFalse($published->isDraft);
        self::assertNull($published->expiresAt);
    }

    public function testQuarantinedRemembersOriginsAndRestoredReverses(): void
    {
        $original = $this->record(visibility: Visibility::Public);
        $deletedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $quarantined = $original->quarantined('.quarantine/zzz', $deletedAt);
        self::assertTrue($quarantined->isDeleted);
        self::assertSame('.quarantine/zzz', $quarantined->path);
        self::assertSame(Visibility::Private, $quarantined->visibility);
        self::assertSame(Visibility::Public, $quarantined->originalVisibility);
        self::assertSame($original->path, $quarantined->originalPath);
        self::assertSame($deletedAt, $quarantined->deletedAt);

        $restored = $quarantined->restored();
        self::assertFalse($restored->isDeleted);
        self::assertSame($original->path, $restored->path);
        self::assertSame(Visibility::Public, $restored->visibility);
        self::assertNull($restored->originalVisibility);
        self::assertNull($restored->originalPath);
        self::assertNull($restored->deletedAt);
    }

    public function testIsExpired(): void
    {
        $now = new DateTimeImmutable('2024-06-01T00:00:00Z');
        $record = $this->record()->withDraft(true, new DateTimeImmutable('2024-05-01T00:00:00Z'));

        self::assertTrue($record->isExpired($now));
        self::assertFalse($this->record()->isExpired($now), 'A record with no expiry is never expired.');
    }

    private function record(Visibility $visibility = Visibility::Private): FileRecord
    {
        return new FileRecord(
            id: 'id',
            path: 'a/b/c.txt',
            originalName: 'c.txt',
            size: 1,
            mimeType: 'text/plain',
            checksum: 'x',
            visibility: $visibility,
            createdAt: new DateTimeImmutable('2024-01-01T00:00:00Z'),
        );
    }
}
