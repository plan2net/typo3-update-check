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
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

#[AsCommand(
    name: 'typo3:check-updates',
    description: 'Check TYPO3 core updates for breaking changes and security updates'
)]
class CheckUpdatesCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('from', InputArgument::OPTIONAL, 'Current version (e.g., 12.4.1); defaults to the installed version')
            ->addArgument('to', InputArgument::OPTIONAL, 'Target version (e.g., 12.4.45); defaults to the latest release');
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
        $fromDetected = $from === null;
        $toDefaulted = $to === null;
        $autoDetect = $fromDetected || $toDefaulted;

        if ($autoDetect && !$input->isInteractive()) {
            $output->writeln('<error>Provide a current and target version, e.g. composer typo3:check-updates 12.4.3 12.4.45</error>');

            return self::FAILURE;
        }

        $versionParser = new VersionParser();

        if ($from === null) {
            $from = $this->installedCoreVersion();
            if ($from === null) {
                $output->writeln('<error>Could not detect an installed typo3/cms-core. Pass the versions explicitly.</error>');

                return self::FAILURE;
            }
        }

        $fromNormalized = $versionParser->normalize($from);
        if ($fromNormalized === null) {
            $output->writeln('<error>Invalid version format</error>');

            return self::FAILURE;
        }

        $provider = $this->createReleaseProvider();
        $updateChecker = new UpdateChecker($versionParser);

        $majorVersion = (int) explode('.', $fromNormalized)[0];

        try {
            $releases = $provider->getReleasesForMajorVersion($majorVersion);
        } catch (ApiFailureException $exception) {
            $output->writeln(sprintf('<error>%s</error>', $exception->getMessage()));

            return self::FAILURE;
        }

        $knownVersions = [];
        foreach ($releases as $release) {
            $normalized = $versionParser->normalize($release->version);
            if ($normalized !== null) {
                $knownVersions[$normalized] = $release->version;
            }
        }

        $latest = $this->latestVersion($knownVersions);
        $latestNormalized = $versionParser->normalize($latest);

        if (!isset($knownVersions[$fromNormalized])) {
            $output->writeln(sprintf(
                '<error>%s is not a released TYPO3 version in the %d.x line (latest is %s).</error>',
                (string) $from,
                $majorVersion,
                $latest,
            ));

            return self::FAILURE;
        }

        if ($to === null) {
            $to = $latest;
        }

        $toNormalized = $versionParser->normalize($to);
        if ($toNormalized === null) {
            $output->writeln('<error>Invalid version format</error>');

            return self::FAILURE;
        }

        $toResolvedToLatest = $toDefaulted;

        if (!isset($knownVersions[$toNormalized])) {
            $output->writeln(sprintf(
                '<error>%s is not a released TYPO3 version in the %d.x line (latest is %s).</error>',
                (string) $to,
                $majorVersion,
                $latest,
            ));

            if (!$input->isInteractive()) {
                return self::FAILURE;
            }

            $helper = $this->getHelper('question');
            \assert($helper instanceof QuestionHelper);
            $useLatest = $helper->ask($input, $output, new ConfirmationQuestion(
                sprintf('Use the latest (%s) instead? [Y/n] ', $latest),
                true,
            ));
            if ($useLatest !== true || $latestNormalized === null) {
                return self::FAILURE;
            }

            $to = $latest;
            $toNormalized = $latestNormalized;
            $toResolvedToLatest = true;
        }

        if (version_compare($toNormalized, $fromNormalized, '<=')) {
            if ($autoDetect || $toResolvedToLatest) {
                $output->writeln(sprintf(
                    '<comment>Already on the latest release (%s) in the %d.x line.</comment>',
                    (string) $from,
                    $majorVersion,
                ));

                return self::SUCCESS;
            }

            $output->writeln('<error>Target version must be greater than current version</error>');

            return self::FAILURE;
        }

        if ($autoDetect) {
            $current = (string) $from . ($fromDetected ? ' (installed)' : '');
            $target = (string) $to . ($toDefaulted ? ' (latest)' : '');

            $helper = $this->getHelper('question');
            \assert($helper instanceof QuestionHelper);
            $proceed = $helper->ask($input, $output, new ConfirmationQuestion(
                sprintf('Check %s → %s? [Y/n] ', $current, $target),
                true,
            ));
            if ($proceed !== true) {
                return self::SUCCESS;
            }
        }

        $versions = $updateChecker->filterVersionsBetween(array_keys($knownVersions), $fromNormalized, $toNormalized);

        $batch = $provider->getReleaseContents($versions);

        $formatter = new ConsoleFormatter();
        $lines = $formatter->formatBatchReport($batch, $fromNormalized, $toNormalized);
        $lines = array_merge($lines, $formatter->formatSecurityGap(
            $toNormalized,
            $updateChecker->securityReleasesAbove($releases, $toNormalized),
        ));

        foreach ($lines as $line) {
            $output->writeln($line);
        }

        if (!$batch->hasResults() && $batch->hasFailures()) {
            return self::INVALID;
        }

        return self::SUCCESS;
    }

    /**
     * @param array<string, string> $knownVersions normalized => pretty
     */
    private function latestVersion(array $knownVersions): string
    {
        if ($knownVersions === []) {
            return 'unknown';
        }

        $normalized = array_keys($knownVersions);
        usort($normalized, static fn (string $a, string $b): int => version_compare($b, $a));

        return $knownVersions[$normalized[0]];
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

    protected function installedCoreVersion(): ?string
    {
        $package = $this->requireComposer()
            ->getRepositoryManager()
            ->getLocalRepository()
            ->findPackage('typo3/cms-core', '*');

        return $package?->getPrettyVersion();
    }

    protected function createReleaseProvider(): ReleaseProvider
    {
        $composer = $this->requireComposer();
        $cacheDir = $composer->getConfig()->get('cache-dir');

        return ReleaseProviderFactory::create(
            $composer->getLoop()->getHttpDownloader(),
            is_string($cacheDir) ? $cacheDir : null,
        );
    }
}
