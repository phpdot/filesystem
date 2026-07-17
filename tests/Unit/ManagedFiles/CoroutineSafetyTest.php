<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Tests\Unit\ManagedFiles;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPdot\Filesystem\Adapter\InMemoryAdapter;
use PHPdot\Filesystem\Filesystem;
use PHPdot\Filesystem\FilesystemConfig;
use PHPdot\Filesystem\ManagedFiles\FileContext;
use PHPdot\Filesystem\ManagedFiles\Files;
use PHPdot\Filesystem\Path\PathGenerator;
use PHPdot\Filesystem\Write\WriteContents;
use PHPUnit\Framework\TestCase;

/**
 * Guards the class of bug behind the legacy coroutine-unsafe `setOriginalFilename`:
 * two concurrent stores through one shared facade must never cross filenames.
 * Skipped unless Swoole is present.
 */
final class CoroutineSafetyTest extends TestCase
{
    public function testConcurrentStoresNeverCrossFilenames(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('Swoole is not installed.');
        }

        $factory = new Psr17Factory();
        $config = new FilesystemConfig();
        $fs = new Filesystem(new InMemoryAdapter($factory), new WriteContents($factory), null, null, $config);
        $repo = new InMemoryFileRepository();
        $files = new Files($fs, $repo, new WriteContents($factory), $factory, new PathGenerator(), $config);

        /** @var array<string,string> $results */
        $results = [];

        \Swoole\Coroutine\run(function () use ($files, &$results): void {
            $group = new \Swoole\Coroutine\WaitGroup();

            for ($i = 0; $i < 25; ++$i) {
                $name = "file-{$i}.txt";
                $group->add();
                \Swoole\Coroutine::create(function () use ($files, $name, &$results, $group): void {
                    $record = $files->store("body-of-{$name}", new FileContext(originalName: $name));
                    $results[$name] = $record->originalName;
                    $group->done();
                });
            }

            $group->wait();
        });

        self::assertCount(25, $results);
        foreach ($results as $name => $stored) {
            self::assertSame($name, $stored, 'A concurrent store crossed filenames.');
        }
    }
}
