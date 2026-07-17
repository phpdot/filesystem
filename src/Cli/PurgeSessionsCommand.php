<?php

declare(strict_types=1);

/**
 * Sweeps expired resumable upload sessions: aborts their backend multipart
 * uploads and deletes the session records.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Filesystem\Cli;

use DateTimeImmutable;
use DateTimeZone;
use PHPdot\Console\Command;
use PHPdot\Filesystem\Contract\UploadManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'filesystem:purge-sessions',
    description: 'Delete expired resumable upload sessions and abort their multipart uploads.',
)]
final class PurgeSessionsCommand extends Command
{
    /**
     * __construct.
     *
     * @param UploadManagerInterface $uploads
     */
    public function __construct(private readonly UploadManagerInterface $uploads)
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
        $purged = $this->uploads->purgeExpired(new DateTimeImmutable('now', new DateTimeZone('UTC')));

        $this->success($output, sprintf('Purged %d expired upload session(s).', $purged));

        return self::SUCCESS;
    }
}
