<?php

declare(strict_types=1);

/**
 * Sweeps managed files: hard-deletes expired drafts and soft-deleted records
 * that have outlived their retention. Parallels {@see PurgeSessionsCommand}.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Cli;

use DateTimeImmutable;
use DateTimeZone;
use PHPdot\Console\Command;
use PHPdot\Filesystem\ManagedFiles\Files;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'filesystem:purge-files',
    description: 'Hard-delete expired draft files and soft-deleted files past their retention.',
)]
final class PurgeFilesCommand extends Command
{
    /**
     * __construct.
     *
     * @param Files $files
     */
    public function __construct(private readonly Files $files)
    {
        parent::__construct();
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
        $purged = $this->files->purge(new DateTimeImmutable('now', new DateTimeZone('UTC')));

        $this->success($output, sprintf('Purged %d managed file(s).', $purged));

        return self::SUCCESS;
    }
}
