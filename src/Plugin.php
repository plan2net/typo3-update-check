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
use Plan2net\Typo3UpdateCheck\Release\Release;
use Plan2net\Typo3UpdateCheck\Release\ReleaseProvider;

final class Plugin implements PluginInterface, EventSubscriberInterface, Capable
{
    private Composer $composer;
    private IOInterface $io;
    private VersionParser $versionParser;
    private UpdateChecker $updateChecker;
    private ?ReleaseProvider $releaseProvider = null;
    private ConsoleFormatter $consoleFormatter;
    private bool $hasChecked = false;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->versionParser = new VersionParser();
        $this->updateChecker = new UpdateChecker($this->versionParser);
        $this->consoleFormatter = new ConsoleFormatter();
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
        if ($this->hasChecked || $this->isDisabledByEnvironment()) {
            return;
        }

        $updateInfo = $this->detectUpdate($event);
        if (!$updateInfo) {
            return;
        }

        [$currentVersion, $targetVersion] = $updateInfo;

        $this->announceUpdate($currentVersion, $targetVersion);

        $releases = $this->fetchReleases($currentVersion);
        if ($releases === null) {
            $this->writeFooter();

            return;
        }

        $versions = $this->versionsBetween($releases, $currentVersion, $targetVersion);
        if ($versions === []) {
            $this->io->write('No intermediate versions found.');
            $this->writeFooter();

            return;
        }

        $hasImportantChanges = $this->processVersions($versions, $releases, $currentVersion, $targetVersion);
        $this->writeFooter();
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
        $this->writeHeader();
        $this->io->write(sprintf(
            '<info>TYPO3 core will be updated from %s to %s</info>',
            $currentVersion,
            $targetVersion
        ));
        $this->io->write('Fetching version information...');
    }

    /**
     * @return Release[]|null
     *
     * @throws \Exception
     */
    private function fetchReleases(string $fromVersion): ?array
    {
        $majorVersion = (int) explode('.', $fromVersion)[0];

        try {
            return $this->getReleaseProvider()->getReleasesForMajorVersion($majorVersion);
        } catch (ApiFailureException $exception) {
            $this->io->write(sprintf(
                '<error>%s — proceeding with update without breaking-change preview.</error>',
                $this->humanizeFailure($exception->failure),
            ));

            return null;
        }
    }

    /**
     * @param Release[] $releases
     *
     * @return string[]
     */
    private function versionsBetween(array $releases, string $fromVersion, string $toVersion): array
    {
        $allVersions = [];
        foreach ($releases as $release) {
            $normalized = $this->versionParser->normalize($release->version);
            if ($normalized) {
                $allVersions[] = $normalized;
            }
        }

        return $this->updateChecker->filterVersionsBetween($allVersions, $fromVersion, $toVersion);
    }

    /**
     * @param string[]  $versions
     * @param Release[] $releases
     */
    private function processVersions(array $versions, array $releases, string $currentVersion, string $targetVersion): bool
    {
        $batch = $this->getReleaseProvider()->getReleaseContents($versions);

        $lines = $this->consoleFormatter->formatBatchReport($batch, $currentVersion, $targetVersion);
        $lines = array_merge($lines, $this->consoleFormatter->formatSecurityGap(
            $targetVersion,
            $this->updateChecker->securityReleasesAbove($releases, $targetVersion),
        ));

        foreach ($lines as $line) {
            $this->io->write($line);
        }

        return $batch->hasImportantChanges();
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

    private function isDisabledByEnvironment(): bool
    {
        $value = getenv('TYPO3_UPDATE_CHECK');
        if ($value === false) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) === false;
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

    private function writeHeader(): void
    {
        $this->io->write('');
        $this->io->write('<bg=yellow;fg=black;options=bold> TYPO3 Update Check </>');
        $this->io->write('');
    }

    private function writeFooter(): void
    {
        $this->io->write('<fg=blue>────────────────────────────────────────────────────────────</>');
        $this->io->write('');
    }

    private function getReleaseProvider(): ReleaseProvider
    {
        if ($this->releaseProvider === null) {
            $cacheDir = $this->composer->getConfig()->get('cache-dir');
            $this->releaseProvider = ReleaseProviderFactory::create(
                $this->composer->getLoop()->getHttpDownloader(),
                is_string($cacheDir) ? $cacheDir : null,
            );
        }

        return $this->releaseProvider;
    }
}
