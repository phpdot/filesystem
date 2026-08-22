<?php

declare(strict_types=1);

namespace PHPdot\Filesystem\Tests\Unit\Cli;

use PHPdot\Filesystem\Cli\WatchChangesCommand;
use PHPdot\Filesystem\Realtime\Binlog\ColumnType;
use PHPdot\Filesystem\Realtime\Binlog\EventType;
use PHPdot\Filesystem\Realtime\BinlogClient;
use PHPdot\Filesystem\Realtime\BinlogConfig;
use PHPdot\Filesystem\Realtime\Checkpoint\InMemoryCheckpointStore;
use PHPdot\Filesystem\Tests\Unit\Realtime\BinlogEventBuilder as Build;
use PHPdot\Filesystem\Tests\Unit\Realtime\ScriptedConnectionFactory;
use PHPdot\Filesystem\Tests\Unit\Realtime\ScriptedPacketStream;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class WatchChangesCommandTest extends TestCase
{
    private const TABLE_ID = 7;

    /**
     * A scripted server that serves one insert into `shop.orders`.
     */
    private static function client(): BinlogClient
    {
        $stream = (new ScriptedPacketStream())
            ->pushResultSet('ON')    // log_bin
            ->pushResultSet('ROW')   // binlog_format
            ->pushOk()               // SET @master_binlog_checksum
            ->pushResultSet('NONE')  // binlog_checksum
            ->pushOk()               // SET @master_heartbeat_period
            ->pushOk()               // COM_REGISTER_SLAVE
            ->pushEvent(Build::formatDescription())
            ->pushEvent(Build::rotate('binlog.000004'))
            ->pushEvent(Build::tableMap(
                self::TABLE_ID,
                'shop',
                'orders',
                [ColumnType::LONG],
                [0],
                [false],
                ['id'],
                [0],
            ))
            ->pushEvent(Build::rows(EventType::WRITE_ROWS_V2, self::TABLE_ID, 1, [true], [
                Build::rowImage([false], pack('V', 99)),
            ], 900));

        return new BinlogClient(
            new BinlogConfig(checkpointIntervalSeconds: 0),
            new InMemoryCheckpointStore(),
            new ScriptedConnectionFactory($stream),
        );
    }

    public function testPrintsASummaryLinePerChangeAndStopsAtTheLimit(): void
    {
        $tester = new CommandTester(new WatchChangesCommand(self::client()));
        $exit = $tester->execute(['--limit' => '1']);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('insert', $tester->getDisplay());
        self::assertStringContainsString('shop.orders', $tester->getDisplay());
        self::assertStringContainsString('id=99', $tester->getDisplay());
        self::assertStringContainsString('binlog.000004:900', $tester->getDisplay());
    }

    public function testJsonModeEmitsOneObjectPerChange(): void
    {
        $tester = new CommandTester(new WatchChangesCommand(self::client()));
        $tester->execute(['--limit' => '1', '--json' => true]);

        self::assertStringContainsString('"kind":"insert"', $tester->getDisplay());
        self::assertStringContainsString('"table":"orders"', $tester->getDisplay());
    }

    public function testSubscribesToTerminationSignalsForACleanShutdown(): void
    {
        $signals = (new WatchChangesCommand(self::client()))->getSubscribedSignals();

        if (!defined('SIGINT')) {
            self::assertSame([], $signals);

            return;
        }

        self::assertContains(SIGINT, $signals);
    }
}
