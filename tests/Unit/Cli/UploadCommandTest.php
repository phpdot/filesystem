<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Tests\Unit\Cli;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPdot\Filesystem\Adapter\InMemoryAdapter;
use PHPdot\Filesystem\Cli\UploadCommand;
use PHPdot\Filesystem\Filesystem;
use PHPdot\Filesystem\Write\WriteContents;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class UploadCommandTest extends TestCase
{
    public function testUploadsLocalFileToTheFilesystem(): void
    {
        $factory = new Psr17Factory();
        $filesystem = new Filesystem(new InMemoryAdapter($factory), new WriteContents($factory));

        $source = tempnam(sys_get_temp_dir(), 'phpdot-up-');
        if ($source === false) {
            throw new RuntimeException('Unable to create a temp source file.');
        }
        file_put_contents($source, 'cli upload body');

        try {
            $tester = new CommandTester(new UploadCommand($filesystem, $factory));
            $exit = $tester->execute(['source' => $source, 'destination' => 'dest/file.txt']);

            self::assertSame(Command::SUCCESS, $exit);
            self::assertSame('cli upload body', $filesystem->read('dest/file.txt'));
            self::assertStringContainsString('Uploaded', $tester->getDisplay());
        } finally {
            @unlink($source);
        }
    }

    public function testFailsWhenSourceIsMissing(): void
    {
        $factory = new Psr17Factory();
        $filesystem = new Filesystem(new InMemoryAdapter($factory), new WriteContents($factory));

        $tester = new CommandTester(new UploadCommand($filesystem, $factory));
        $exit = $tester->execute(['source' => '/no/such/file.txt', 'destination' => 'd.txt']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('not found', $tester->getDisplay());
    }
}
