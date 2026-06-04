<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Command;

use Composer\Command\BaseCommand;
use Plan2net\Typo3UpdateCheck\ConsoleFormatter;
use Plan2net\Typo3UpdateCheck\Release\ApiFailureException;
use Plan2net\Typo3UpdateCheck\Release\ReleaseProvider;
use Plan2net\Typo3UpdateCheck\ReleaseProviderFactory;
use Plan2net\Typo3UpdateCheck\UpdateChecker;
use Plan2net\Typo3UpdateCheck\VersionParser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'typo3:check-updates',
    description: 'Check TYPO3 core updates for breaking changes and security updates'
)]
class CheckUpdatesCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('from', InputArgument::REQUIRED, 'Current version (e.g., 12.4.1)')
            ->addArgument('to', InputArgument::REQUIRED, 'Target version (e.g., 12.4.10)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->writeHeader($output);
        $exitCode = $this->doExecute($input, $output);
        $this->writeFooter($output);

        return $exitCode;
    }

    private function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $from = $input->getArgument('from');
        $to = $input->getArgument('to');

        $versionParser = new VersionParser();
        $fromNormalized = $versionParser->normalize($from);
        $toNormalized = $versionParser->normalize($to);

        if ($fromNormalized === null || $toNormalized === null) {
            $output->writeln('<error>Invalid version format</error>');

            return 1;
        }

        if (version_compare($toNormalized, $fromNormalized, '<=')) {
            $output->writeln('<error>Target version must be greater than current version</error>');

            return 1;
        }

        $provider = $this->createReleaseProvider();
        $updateChecker = new UpdateChecker($versionParser);

        $majorVersion = (int) explode('.', $fromNormalized)[0];

        try {
            $releases = $provider->getReleasesForMajorVersion($majorVersion);
        } catch (ApiFailureException $exception) {
            $output->writeln(sprintf('<error>%s</error>', $exception->getMessage()));

            return 1;
        }

        $allVersions = array_filter(array_map(
            fn ($release) => $versionParser->normalize($release->version),
            $releases
        ));

        $versions = $updateChecker->filterVersionsBetween($allVersions, $fromNormalized, $toNormalized);

        if (empty($versions)) {
            $output->writeln('No versions found between ' . $fromNormalized . ' and ' . $toNormalized);

            return 0;
        }

        $batch = $provider->getReleaseContents($versions);

        foreach ((new ConsoleFormatter())->formatBatchReport($batch, $fromNormalized, $toNormalized) as $line) {
            $output->writeln($line);
        }

        if (!$batch->hasResults() && $batch->hasFailures()) {
            return 2;
        }

        return 0;
    }

    private function writeHeader(OutputInterface $output): void
    {
        $output->writeln('');
        $output->writeln('<bg=yellow;fg=black;options=bold> TYPO3 Update Check </>');
        $output->writeln('');
    }

    private function writeFooter(OutputInterface $output): void
    {
        $output->writeln('<fg=blue>────────────────────────────────────────────────────────────</>');
        $output->writeln('');
    }

    protected function createReleaseProvider(): ReleaseProvider
    {
        $cacheDir = $this->requireComposer()->getConfig()->get('cache-dir');

        return ReleaseProviderFactory::create(is_string($cacheDir) ? $cacheDir : null);
    }
}
