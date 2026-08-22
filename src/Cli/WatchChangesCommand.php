<?php

declare(strict_types=1);

/**
 * Streams MySQL row changes to the terminal, as they commit.
 *
 * A thin loop over {@see BinlogClient}: useful for confirming replication
 * credentials and server settings before wiring the stream into an application,
 * and for tailing a table during development.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Cli;

use PHPdot\Console\Command;
use PHPdot\Filesystem\Realtime\BinlogClient;
use PHPdot\Filesystem\Realtime\Change\ChangeEvent;
use PHPdot\Filesystem\Realtime\Change\ChangeKind;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'realtime:watch',
    description: 'Stream row changes from the MySQL binary log as they commit.',
)]
final class WatchChangesCommand extends Command implements SignalableCommandInterface
{
    /**
     * __construct.
     *
     * @param BinlogClient $client
     */
    public function __construct(private readonly BinlogClient $client)
    {
        parent::__construct();
    }

    /**
     * Configure.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many changes; 0 streams forever.', '0')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit one JSON object per change instead of a summary line.');
    }

    /**
     * Execute.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = max(0, (int) $input->getOption('limit'));
        $asJson = $input->getOption('json') === true;
        $seen = 0;

        foreach ($this->client->changes() as $change) {
            $output->writeln($asJson ? $this->encode($change) : $this->summarise($change));

            if (++$seen === $limit) {
                $this->client->stop();
            }
        }

        $this->success($output, sprintf(
            'Streamed %d change(s); stopped at %s.',
            $seen,
            $this->client->checkpoint()->describe(),
        ));

        return self::SUCCESS;
    }

    /**
     * Ask the stream to wind down on Ctrl-C rather than dying mid-transaction,
     * so the checkpoint is flushed before the process exits.
     *
     * @return list<int>
     */
    public function getSubscribedSignals(): array
    {
        return array_values(array_filter(
            [defined('SIGINT') ? SIGINT : null, defined('SIGTERM') ? SIGTERM : null],
            static fn(null|int $signal): bool => $signal !== null,
        ));
    }

    /**
     * Handle signal.
     *
     * @param int $signal
     * @param int|false $previousExitCode
     *
     * @return int|false
     */
    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->client->stop();

        return false;
    }

    /**
     * Encode.
     *
     * @param ChangeEvent $change
     *
     * @return string
     */
    private function encode(ChangeEvent $change): string
    {
        return json_encode($change->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Summarise.
     *
     * @param ChangeEvent $change
     *
     * @return string
     */
    private function summarise(ChangeEvent $change): string
    {
        $key = $change->primaryKey === []
            ? ''
            : ' [' . implode(', ', array_map(
                static fn(string $column, mixed $value): string => $column . '=' . (is_scalar($value) ? (string) $value : '?'),
                array_keys($change->primaryKey),
                $change->primaryKey,
            )) . ']';

        $columns = $change->kind === ChangeKind::UPDATE
            ? ' changed: ' . implode(', ', $change->changedColumns())
            : '';

        return sprintf('%-6s %s%s%s', $change->kind->value, $change->qualifiedName(), $key, $columns);
    }
}
