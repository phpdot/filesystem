<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Cli;

use DateTimeImmutable;
use DateTimeZone;
use PHPdot\Console\Command;
use PHPdot\Filesystem\ManagedFiles\Files;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Sweeps managed files: hard-deletes expired drafts and soft-deleted records
 * that have outlived their retention. Parallels {@see PurgeSessionsCommand}.
 */
#[AsCommand(
    name: 'filesystem:purge-files',
    description: 'Hard-delete expired draft files and soft-deleted files past their retention.',
)]
final class PurgeFilesCommand extends Command
{
    public function __construct(private readonly Files $files)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $purged = $this->files->purge(new DateTimeImmutable('now', new DateTimeZone('UTC')));

        $this->success($output, sprintf('Purged %d managed file(s).', $purged));

        return self::SUCCESS;
    }
}
