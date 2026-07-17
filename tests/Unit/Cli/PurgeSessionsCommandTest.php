<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Tests\Unit\Cli;

use DateTimeImmutable;
use DateTimeZone;
use FilesystemIterator;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPdot\Filesystem\Adapter\LocalAdapter;
use PHPdot\Filesystem\Cli\PurgeSessionsCommand;
use PHPdot\Filesystem\FilesystemConfig;
use PHPdot\Filesystem\Upload\Store\LocalSessionStore;
use PHPdot\Filesystem\Upload\UploadManager;
use PHPdot\Filesystem\Upload\UploadSession;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class PurgeSessionsCommandTest extends TestCase
{
    private string $root;
    private string $sessionDir;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/phpdot-purge-root-' . bin2hex(random_bytes(6));
        $this->sessionDir = sys_get_temp_dir() . '/phpdot-purge-sess-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->root);
        $this->deleteTree($this->sessionDir);
    }

    public function testPurgesOnlyExpiredSessions(): void
    {
        $adapter = new LocalAdapter(new FilesystemConfig(root: $this->root), new Psr17Factory());
        $store = new LocalSessionStore(new FilesystemConfig(sessionDirectory: $this->sessionDir));
        $manager = new UploadManager($adapter, $store);

        $store->put($this->session('expired', new DateTimeImmutable('-1 hour', new DateTimeZone('UTC'))));
        $store->put($this->session('fresh', new DateTimeImmutable('+1 hour', new DateTimeZone('UTC'))));

        $tester = new CommandTester(new PurgeSessionsCommand($manager));
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Purged 1', $tester->getDisplay());
        self::assertNull($store->find('expired'));
        self::assertNotNull($store->find('fresh'));
    }

    private function session(string $id, DateTimeImmutable $expiresAt): UploadSession
    {
        return new UploadSession($id, $id . '.bin', 'UP-' . $id, 100, 0, [], 1024, $expiresAt);
    }

    private function deleteTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if (!$item instanceof SplFileInfo) {
                continue;
            }

            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }
}
