<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Command;

use Composer\Command\BaseCommand;
use Plan2net\Typo3UpdateCheck\ConsoleFormatter;
use Plan2net\Typo3UpdateCheck\Release\ApiFailureException;
use Plan2net\Typo3UpdateCheck\Release\ReleaseProvider;
use Plan2net\Typo3UpdateCheck\ReleaseProviderFactory;
use Plan2net\Typo3UpdateCheck\UpdateChecker;
use Plan2net\Typo3UpdateCheck\UpdateScope;
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

        $toNormalized = null;
        if ($to !== null) {
            $toNormalized = $versionParser->normalize($to);
            if ($toNormalized === null) {
                $output->writeln('<error>Invalid version format</error>');

                return self::FAILURE;
            }
        }

        $fromMajor = (int) explode('.', $fromNormalized)[0];
        $toMajor = $toNormalized !== null ? (int) explode('.', $toNormalized)[0] : $fromMajor;

        $provider = $this->createReleaseProvider();
        $updateChecker = new UpdateChecker($versionParser);

        try {
            $releases = $provider->getReleasesForMajorRange(min($fromMajor, $toMajor), max($fromMajor, $toMajor));
        } catch (ApiFailureException $exception) {
            $output->writeln(sprintf('<error>%s</error>', $exception->getMessage()));

            return self::FAILURE;
        }

        /** @var array<int, array<string, string>> $versionsByMajor */
        $versionsByMajor = [];
        $knownVersions = [];
        foreach ($releases as $release) {
            $normalized = $versionParser->normalize($release->version);
            if ($normalized !== null) {
                $major = (int) explode('.', $normalized)[0];
                $knownVersions[$normalized] = $release->version;
                $versionsByMajor[$major][$normalized] = $release->version;
            }
        }

        $latestInFromLine = $this->latestVersion($versionsByMajor[$fromMajor] ?? []);

        if (!isset($versionsByMajor[$fromMajor][$fromNormalized])) {
            $output->writeln(sprintf(
                '<error>%s is not a released TYPO3 version in the %d.x line (latest is %s).</error>',
                (string) $from,
                $fromMajor,
                $latestInFromLine,
            ));

            return self::FAILURE;
        }

        if ($toNormalized === null) {
            $to = $latestInFromLine;
            $toNormalized = $versionParser->normalize($to);
            if ($toNormalized === null) {
                $output->writeln('<error>Invalid version format</error>');

                return self::FAILURE;
            }
        }

        $toResolvedToLatest = $toDefaulted;

        if (!isset($versionsByMajor[$toMajor][$toNormalized])) {
            $latestInTargetLine = $this->latestVersion($versionsByMajor[$toMajor] ?? []);
            $latestInTargetLineNormalized = $versionParser->normalize($latestInTargetLine);

            $output->writeln(sprintf(
                '<error>%s is not a released TYPO3 version in the %d.x line (latest is %s).</error>',
                (string) $to,
                $toMajor,
                $latestInTargetLine,
            ));

            if (!$input->isInteractive()) {
                return self::FAILURE;
            }

            $helper = $this->getHelper('question');
            \assert($helper instanceof QuestionHelper);
            $useLatest = $helper->ask($input, $output, new ConfirmationQuestion(
                sprintf('Use the latest (%s) instead? [Y/n] ', $latestInTargetLine),
                true,
            ));
            if ($useLatest !== true || $latestInTargetLineNormalized === null) {
                return self::FAILURE;
            }

            $to = $latestInTargetLine;
            $toNormalized = $latestInTargetLineNormalized;
            $toResolvedToLatest = true;
        }

        if (version_compare($toNormalized, $fromNormalized, '<=')) {
            if (($autoDetect || $toResolvedToLatest) && $toMajor === $fromMajor) {
                $output->writeln(sprintf(
                    '<comment>Already on the latest release (%s) in the %d.x line.</comment>',
                    (string) $from,
                    $fromMajor,
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

        $scope = new UpdateScope($fromNormalized, $toNormalized);

        $versions = $updateChecker->filterVersionsBetween(array_keys($knownVersions), $fromNormalized, $toNormalized);

        $batch = $provider->getReleaseContents($versions, $fromNormalized);

        $formatter = new ConsoleFormatter();
        foreach ($formatter->formatMajorBumpHeader($scope) as $line) {
            $output->writeln($line);
        }
        $lines = $formatter->formatBatchReport($batch, $scope);
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
