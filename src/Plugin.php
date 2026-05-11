<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PrePoolCreateEvent;
use Plan2net\Typo3UpdateCheck\Command\CommandProvider;
use Plan2net\Typo3UpdateCheck\Release\ApiFailure;
use Plan2net\Typo3UpdateCheck\Release\ApiFailureCategory;
use Plan2net\Typo3UpdateCheck\Release\ApiFailureException;
use Plan2net\Typo3UpdateCheck\Release\FailureMessageFormatter;
use Plan2net\Typo3UpdateCheck\Release\ReleaseProvider;

final class Plugin implements PluginInterface, EventSubscriberInterface, Capable
{
    private Composer $composer;
    private IOInterface $io;
    private VersionParser $versionParser;
    private UpdateChecker $updateChecker;
    private ?ReleaseProvider $releaseProvider = null;
    private ConsoleFormatter $consoleFormatter;
    private FailureMessageFormatter $failureFormatter;
    private bool $hasChecked = false;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->versionParser = new VersionParser();
        $this->updateChecker = new UpdateChecker($this->versionParser);
        $this->consoleFormatter = new ConsoleFormatter();
        $this->failureFormatter = new FailureMessageFormatter();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PluginEvents::PRE_POOL_CREATE => ['checkForBreakingChanges', 1000],
        ];
    }

    public function getCapabilities(): array
    {
        return [
            CommandProviderCapability::class => CommandProvider::class,
        ];
    }

    /** @internal Test seam — production code obtains the provider via the factory. */
    public function setReleaseProvider(ReleaseProvider $provider): void
    {
        $this->releaseProvider = $provider;
    }

    /**
     * @throws \Exception
     */
    public function checkForBreakingChanges(PrePoolCreateEvent $event): void
    {
        if ($this->hasChecked) {
            return;
        }

        $updateInfo = $this->detectUpdate($event);
        if (!$updateInfo) {
            return;
        }

        [$currentVersion, $targetVersion] = $updateInfo;

        $this->announceUpdate($currentVersion, $targetVersion);

        $versions = $this->fetchVersionList($currentVersion, $targetVersion);
        if (!$versions) {
            return;
        }

        $hasImportantChanges = $this->processVersions($versions, $currentVersion, $targetVersion);
        $this->handleUserConfirmation($hasImportantChanges);

        $this->hasChecked = true;
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    /**
     * @return array{string, string}|null
     */
    private function detectUpdate(PrePoolCreateEvent $event): ?array
    {
        $localRepository = $this->composer->getRepositoryManager()->getLocalRepository();
        $currentPackage = $localRepository->findPackage('typo3/cms-core', '*');

        if (!$currentPackage) {
            return null;
        }

        $currentVersion = $this->versionParser->normalize($currentPackage->getPrettyVersion());
        if (!$currentVersion) {
            return null;
        }

        $targetVersion = $this->findTargetVersion($event->getPackages());
        if (!$targetVersion || version_compare($targetVersion, $currentVersion, '<=')) {
            return null;
        }

        return [$currentVersion, $targetVersion];
    }

    /**
     * @param PackageInterface[] $packages
     */
    private function findTargetVersion(array $packages): ?string
    {
        return $this->updateChecker->findTargetVersion($packages, '0.0.0');
    }

    private function announceUpdate(string $currentVersion, string $targetVersion): void
    {
        $this->io->write(sprintf(
            '<info>TYPO3 core will be updated from %s to %s</info>',
            $currentVersion,
            $targetVersion
        ));
        $this->io->write('Fetching version information...');
    }

    /**
     * @return string[]|null
     *
     * @throws \Exception
     */
    private function fetchVersionList(string $currentVersion, string $targetVersion): ?array
    {
        try {
            $versions = $this->getVersionsBetween($currentVersion, $targetVersion);
        } catch (ApiFailureException $exception) {
            $this->io->write(sprintf(
                '<error>%s — proceeding with update without breaking-change preview.</error>',
                $this->humanizeFailure($exception->failure),
            ));

            return null;
        }

        if (empty($versions)) {
            $this->io->write('No intermediate versions found.');

            return null;
        }

        return $versions;
    }

    /**
     * @param string[] $versions
     */
    private function processVersions(array $versions, string $currentVersion, string $targetVersion): bool
    {
        $batch = $this->getReleaseProvider()->getReleaseContents($versions);

        if (!$batch->hasResults() && !$batch->hasFailures()) {
            $this->io->write('<error>Failed to fetch release information.</error>');
            $this->io->write('<comment>The TYPO3 API might be temporarily unavailable. Proceeding with update.</comment>');

            return false;
        }

        $hasImportantChanges = false;
        foreach ($batch->results as $content) {
            if ($content->getBreakingChanges() || $content->getSecurityUpdates()) {
                $hasImportantChanges = true;
                $this->io->write($this->consoleFormatter->format($content));
            }
        }

        if ($batch->hasFailures()) {
            foreach ($batch->failures as $version => $failure) {
                $this->io->write(sprintf(
                    '<comment>%s</comment>',
                    $this->failureFormatter->describe($version, $failure),
                ));
            }
            $this->io->write(sprintf(
                '<comment>Retry later with: composer typo3:check-updates %s %s</comment>',
                $currentVersion,
                $targetVersion,
            ));

            if (!$batch->hasResults()) {
                $dominant = $batch->dominantFailureCategory();
                $this->io->write(sprintf(
                    '<comment>Proceeding with update (dominant failure: %s).</comment>',
                    $dominant?->value ?? 'unknown',
                ));

                return false;
            }
        }

        if (!$hasImportantChanges && !$batch->hasFailures()) {
            $this->io->write('✓ No breaking changes or security updates found.');
        }

        return $hasImportantChanges;
    }

    private function handleUserConfirmation(bool $hasImportantChanges): void
    {
        if (!$this->io->isInteractive()) {
            return;
        }

        if (!$hasImportantChanges) {
            return;
        }

        $question = '⚠️ Breaking changes or security updates were found. Do you want to continue with the update? [y/N] ';

        if (!$this->io->askConfirmation($question, false)) {
            $this->io->write('<info>Update cancelled by user.</info>');
            exit(0);
        }
    }

    /**
     * @return string[]
     *
     * @throws \Exception
     */
    private function getVersionsBetween(string $fromVersion, string $toVersion): array
    {
        $majorVersion = (int) explode('.', $fromVersion)[0];
        $releaseProvider = $this->getReleaseProvider();
        $releases = $releaseProvider->getReleasesForMajorVersion($majorVersion);

        $allVersions = [];
        foreach ($releases as $release) {
            $normalized = $this->versionParser->normalize($release->version);
            if ($normalized) {
                $allVersions[] = $normalized;
            }
        }

        return $this->updateChecker->filterVersionsBetween($allVersions, $fromVersion, $toVersion);
    }

    private function humanizeFailure(ApiFailure $failure): string
    {
        return match ($failure->category) {
            ApiFailureCategory::ConnectionError => 'Could not reach the TYPO3 API (network error or timeout)',
            ApiFailureCategory::ServerError => sprintf(
                'TYPO3 API returned %d after retries',
                $failure->statusCode ?? 0,
            ),
            ApiFailureCategory::NotFound => 'TYPO3 API has no information for this version',
            ApiFailureCategory::MalformedResponse => 'TYPO3 API returned an unexpected response',
            ApiFailureCategory::Unknown => sprintf('Unexpected API failure: %s', $failure->detail),
        };
    }

    private function getReleaseProvider(): ReleaseProvider
    {
        if ($this->releaseProvider === null) {
            $cacheDir = $this->composer->getConfig()->get('cache-dir');
            $this->releaseProvider = ReleaseProviderFactory::create(is_string($cacheDir) ? $cacheDir : null);
        }

        return $this->releaseProvider;
    }
}
