<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PrePoolCreateEvent;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Plan2net\Typo3UpdateCheck\Cache\CacheInterface;
use Plan2net\Typo3UpdateCheck\Cache\CacheManager;
use Plan2net\Typo3UpdateCheck\Change\ChangeFactory;
use Plan2net\Typo3UpdateCheck\Change\ChangeParser;
use Plan2net\Typo3UpdateCheck\Release\ReleaseProvider;

final class Plugin implements PluginInterface, EventSubscriberInterface
{
    private Composer $composer;
    private IOInterface $io;
    private VersionParser $versionParser;
    private UpdateChecker $updateChecker;
    private ?ReleaseProvider $releaseProvider = null;
    private ConsoleFormatter $consoleFormatter;
    private ClientInterface $httpClient;
    private ?CacheInterface $cacheManager = null;
    private bool $hasChecked = false;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
        $this->versionParser = new VersionParser();
        $this->updateChecker = new UpdateChecker($this->versionParser);
        $this->consoleFormatter = new ConsoleFormatter();
        $this->httpClient = new Client([
            'timeout' => 10,
            'headers' => ['Accept' => 'application/json'],
        ]);

        $cacheDir = $composer->getConfig()->get('cache-dir');
        if (is_string($cacheDir)) {
            $this->cacheManager = new CacheManager($cacheDir);
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PluginEvents::PRE_POOL_CREATE => ['checkForBreakingChanges', 1000],
        ];
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

        $hasImportantChanges = $this->processVersions($versions);
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
        } catch (\RuntimeException $e) {
            $this->io->write(sprintf('<error>%s</error>', $e->getMessage()));

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
    private function processVersions(array $versions): bool
    {
        $hasImportantChanges = false;
        $successfullyProcessed = 0;
        $failedVersions = [];
        $versionsWithImportantChanges = [];

        foreach ($versions as $version) {
            try {
                $releaseContent = $this->getReleaseProvider()->getReleaseContent($version);
                $successfullyProcessed++;

                if ($releaseContent->getBreakingChanges() || $releaseContent->getSecurityUpdates()) {
                    $hasImportantChanges = true;
                    $versionsWithImportantChanges[] = $releaseContent;
                }
            } catch (\RuntimeException $e) {
                $this->io->write(sprintf('<error>%s</error>', $e->getMessage()));
                $failedVersions[] = $version;
            } catch (\Throwable $e) {
                $this->io->write(sprintf('<error>Failed to fetch release content for %s: %s</error>', $version, $e->getMessage()));
                $failedVersions[] = $version;
            }
        }

        foreach ($versionsWithImportantChanges as $releaseContent) {
            $this->io->write($this->consoleFormatter->format($releaseContent));
        }

        if ($successfullyProcessed === 0) {
            $this->io->write('<error>Failed to fetch release information for all versions.</error>');
            $this->io->write('<comment>The TYPO3 API might be temporarily unavailable. Proceeding with update.</comment>');
        } elseif (!$hasImportantChanges) {
            $this->io->write('✓ No breaking changes or security updates found.');
        }

        if (!empty($failedVersions) && $successfullyProcessed > 0) {
            $this->io->write(sprintf(
                '<comment>Note: Could not fetch information for versions: %s</comment>',
                implode(', ', $failedVersions)
            ));
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

    private function getReleaseProvider(): ReleaseProvider
    {
        if ($this->releaseProvider === null) {
            $changeFactory = new ChangeFactory();
            $bulletinFetcher = new Security\SecurityBulletinFetcher($this->httpClient, $this->cacheManager);
            $changeParser = new ChangeParser($changeFactory, $bulletinFetcher);

            $this->releaseProvider = new ReleaseProvider($this->httpClient, $changeParser, $this->cacheManager);
        }

        return $this->releaseProvider;
    }
}
