<?php

declare(strict_types=1);

namespace PHPdot\Filesystem\Tests\Unit\Realtime\Checkpoint;

use PHPdot\Filesystem\Exception\UnableToPersistCheckpoint;
use PHPdot\Filesystem\Realtime\BinlogConfig;
use PHPdot\Filesystem\Realtime\Change\Checkpoint;
use PHPdot\Filesystem\Realtime\Checkpoint\InMemoryCheckpointStore;
use PHPdot\Filesystem\Realtime\Checkpoint\LocalCheckpointStore;
use PHPUnit\Framework\TestCase;

final class LocalCheckpointStoreTest extends TestCase
{
    private string $dir;

    private string $file;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/phpdot-fs-checkpoint-' . bin2hex(random_bytes(6));
        $this->file = $this->dir . '/checkpoint.json';
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->dir);
    }

    private function store(): LocalCheckpointStore
    {
        return new LocalCheckpointStore(new BinlogConfig(checkpointFile: $this->file));
    }

    public function testAnUnwrittenStoreLoadsAnEmptyCheckpoint(): void
    {
        $checkpoint = $this->store()->load();

        self::assertFalse($checkpoint->hasFile());
        self::assertFalse($checkpoint->hasGtidSet());
        // Position 4 is the first byte after a binlog file's magic number.
        self::assertSame(4, $checkpoint->position);
    }

    public function testRoundTripsEveryField(): void
    {
        $store = $this->store();
        $store->save(new Checkpoint('binlog.000004', 1985, 'uuid:1-5'));

        $loaded = $store->load();

        self::assertSame('binlog.000004', $loaded->file);
        self::assertSame(1985, $loaded->position);
        self::assertSame('uuid:1-5', $loaded->gtidSet);
    }

    public function testCreatesItsDirectory(): void
    {
        $this->store()->save(new Checkpoint('binlog.000001', 4));

        self::assertFileExists($this->file);
    }

    public function testSavingLeavesNoTemporaryFilesBehind(): void
    {
        $store = $this->store();
        $store->save(new Checkpoint('binlog.000001', 4));
        $store->save(new Checkpoint('binlog.000002', 8));

        // An atomic write stages through a temporary file; none may survive.
        self::assertSame([], glob($this->dir . '/*.tmp'));
        self::assertSame('binlog.000002', $store->load()->file);
    }

    public function testACorruptedCheckpointFailsLoudlyRatherThanResumingFromNowhere(): void
    {
        $store = $this->store();
        $store->save(new Checkpoint('binlog.000001', 4));
        file_put_contents($this->file, '{not json');

        $this->expectException(UnableToPersistCheckpoint::class);

        $store->load();
    }

    public function testAnEmptyFileIsTreatedAsNoCheckpoint(): void
    {
        $store = $this->store();
        $store->save(new Checkpoint('binlog.000001', 4));
        file_put_contents($this->file, '');

        self::assertFalse($store->load()->hasFile());
    }

    public function testInMemoryStoreRoundTrips(): void
    {
        $store = new InMemoryCheckpointStore();

        self::assertFalse($store->load()->hasFile());

        $store->save(new Checkpoint('binlog.000009', 12));

        self::assertSame('binlog.000009', $store->load()->file);
    }

    public function testCheckpointDescribesItself(): void
    {
        self::assertSame('start of stream', (new Checkpoint())->describe());
        self::assertSame('binlog.000004:1985', (new Checkpoint('binlog.000004', 1985))->describe());
        self::assertSame(
            'binlog.000004:1985 (gtid uuid:1-5)',
            (new Checkpoint('binlog.000004', 1985, 'uuid:1-5'))->describe(),
        );
    }

    private function deleteTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . '/' . $entry;
            is_dir($path) ? $this->deleteTree($path) : @unlink($path);
        }

        @rmdir($directory);
    }
}
