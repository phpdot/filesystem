<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Tests\Unit\Cli;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPdot\Filesystem\Adapter\InMemoryAdapter;
use PHPdot\Filesystem\Cli\PurgeFilesCommand;
use PHPdot\Filesystem\Filesystem;
use PHPdot\Filesystem\FilesystemConfig;
use PHPdot\Filesystem\ManagedFiles\FileContext;
use PHPdot\Filesystem\ManagedFiles\Files;
use PHPdot\Filesystem\Path\PathGenerator;
use PHPdot\Filesystem\Tests\Unit\ManagedFiles\InMemoryFileRepository;
use PHPdot\Filesystem\Write\WriteContents;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class PurgeFilesCommandTest extends TestCase
{
    public function testPurgesExpiredDrafts(): void
    {
        $factory = new Psr17Factory();
        $config = new FilesystemConfig(draftTtl: 0, softDeleteRetention: 0);
        $fs = new Filesystem(new InMemoryAdapter($factory), new WriteContents($factory), null, null, $config);
        $repo = new InMemoryFileRepository();
        $files = new Files($fs, $repo, new WriteContents($factory), $factory, new PathGenerator(), $config);

        $files->storeDraft('d', new FileContext(originalName: 'd.txt'));

        $tester = new CommandTester(new PurgeFilesCommand($files));
        $exit = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('Purged 1', $tester->getDisplay());
        self::assertSame([], $repo->records);
    }
}
