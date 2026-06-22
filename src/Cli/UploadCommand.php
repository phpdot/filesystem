<?php

declare(strict_types=1);
namespace PHPdot\Filesystem\Cli;

use PHPdot\Console\Command;
use PHPdot\Filesystem\Config;
use PHPdot\Filesystem\Contract\FilesystemInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Streams a local file to the configured filesystem, rendering a terminal
 * progress bar fed by the same {@see Config::PROGRESS} callback the library
 * exposes to every consumer. A thin transport over the core operator.
 */
#[AsCommand(
    name: 'filesystem:upload',
    description: 'Upload a local file to the configured filesystem, with a progress bar.',
)]
final class UploadCommand extends Command
{
    public function __construct(
        private readonly FilesystemInterface $filesystem,
        private readonly StreamFactoryInterface $streams,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('source', InputArgument::REQUIRED, 'Path to the local source file')
            ->addArgument('destination', InputArgument::REQUIRED, 'Destination path within the filesystem');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sourceArgument = $input->getArgument('source');
        $destinationArgument = $input->getArgument('destination');
        $source = is_string($sourceArgument) ? $sourceArgument : '';
        $destination = is_string($destinationArgument) ? $destinationArgument : '';

        if (!is_file($source)) {
            $this->error($output, "Source file not found: {$source}");

            return self::FAILURE;
        }

        $size = filesize($source);
        $progress = new ProgressBar($output, $size === false ? 0 : $size);
        $progress->start();

        $this->filesystem->write($destination, $this->streams->createStreamFromFile($source, 'rb'), [
            Config::PROGRESS => static function (int $soFar, ?int $total) use ($progress): void {
                if ($total !== null) {
                    $progress->setMaxSteps($total);
                }
                $progress->setProgress($soFar);
            },
        ]);

        $progress->finish();
        $output->writeln('');
        $this->success($output, "Uploaded {$source} → {$destination}");

        return self::SUCCESS;
    }
}
