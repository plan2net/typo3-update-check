<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Command;

use Composer\Command\BaseCommand;
use Plan2net\Typo3UpdateCheck\ConsoleFormatter;
use Plan2net\Typo3UpdateCheck\ReleaseProviderFactory;
use Plan2net\Typo3UpdateCheck\UpdateChecker;
use Plan2net\Typo3UpdateCheck\VersionParser;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class CheckUpdatesCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->setName('typo3:check-updates')
            ->setDescription('Check TYPO3 core updates for breaking changes and security updates')
            ->addArgument('from', InputArgument::REQUIRED, 'Current version (e.g., 12.4.1)')
            ->addArgument('to', InputArgument::REQUIRED, 'Target version (e.g., 12.4.10)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
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

        $cacheDir = $this->requireComposer()->getConfig()->get('cache-dir');
        $releaseProvider = ReleaseProviderFactory::create(is_string($cacheDir) ? $cacheDir : null);
        $updateChecker = new UpdateChecker($versionParser);

        $majorVersion = (int) explode('.', $fromNormalized)[0];
        $releases = $releaseProvider->getReleasesForMajorVersion($majorVersion);

        $allVersions = array_filter(array_map(
            fn ($release) => $versionParser->normalize($release->version),
            $releases
        ));

        $versions = $updateChecker->filterVersionsBetween($allVersions, $fromNormalized, $toNormalized);

        if (empty($versions)) {
            $output->writeln('No versions found between ' . $fromNormalized . ' and ' . $toNormalized);

            return 0;
        }

        $releaseContents = $releaseProvider->getReleaseContents($versions);

        if (empty($releaseContents)) {
            $output->writeln('<error>Failed to fetch release information</error>');

            return 1;
        }

        $hasImportantChanges = false;
        $formatter = new ConsoleFormatter();

        foreach ($releaseContents as $content) {
            if ($content->getBreakingChanges() || $content->getSecurityUpdates()) {
                $hasImportantChanges = true;
                $output->writeln($formatter->format($content));
            }
        }

        if (!$hasImportantChanges) {
            $output->writeln('✓ No breaking changes or security updates found.');
        }

        return 0;
    }
}
