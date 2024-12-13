<?php
declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PrePoolCreateEvent;

final class Plugin implements PluginInterface, EventSubscriberInterface
{
    private const TYPO3_CORE_PACKAGE = 'typo3/cms-core';
    private const TYPO3_API_URL = 'https://get.typo3.org/api/v1/release/%s/content';
    private const TYPO3_VERSIONS_URL = 'https://get.typo3.org/api/v1/major/%d/release';

    private Composer $composer;
    private IOInterface $io;
    private ?object $client = null;
    private bool $askedForConfirmation = false;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public function deactivate(Composer $composer, IOInterface $io): void {}
    public function uninstall(Composer $composer, IOInterface $io): void {}

    public static function getSubscribedEvents(): array
    {
        return [
            PluginEvents::PRE_POOL_CREATE => ['onPrePoolCreate', 1000],
        ];
    }

    public function onPrePoolCreate(PrePoolCreateEvent $event): void
    {
        if ($this->askedForConfirmation) {
            return;
        }

        $localRepository = $this->composer->getRepositoryManager()->getLocalRepository();
        $currentPackage = $localRepository->findPackage(self::TYPO3_CORE_PACKAGE, '*');

        if (!$currentPackage) {
            return;
        }

        $currentVersion = $this->normalizeVersion($currentPackage->getVersion());
        $targetVersion = $this->findTargetVersion($event, $currentVersion);

        if (!$targetVersion) {
            return;
        }

        $this->io->write(sprintf(
            '<info>TYPO3 core will be updated from %s to %s</info>',
            $currentVersion,
            $targetVersion
        ));

        $this->processUpdate($currentVersion, $targetVersion);
    }

    private function processUpdate(string $currentVersion, string $targetVersion): void
    {
        try {
            $versions = $this->getVersionsBetween($currentVersion, $targetVersion);
            $allIssues = $this->collectIssues($versions);

            $this->displayIssuesIfPresent($allIssues);

            if (!$this->checkChangelogAndConfirm($allIssues)) {
                $this->io->write('<info>Update cancelled by user.</info>');
                exit(0);
            }

            $this->askedForConfirmation = true;
        } catch (\RuntimeException $e) {
            $this->io->write(sprintf('<error>%s</error>', $e->getMessage()));
            exit(0);
        }
    }

    private function findTargetVersion(PrePoolCreateEvent $event, string $currentVersion): ?string
    {
        $highestVersion = null;
        foreach ($event->getPackages() as $package) {
            if ($package->getName() !== self::TYPO3_CORE_PACKAGE) {
                continue;
            }

            $newVersion = $this->normalizeVersion($package->getPrettyVersion());
            if (!$newVersion) {
                continue;
            }

            if (version_compare($newVersion, $currentVersion, '>') &&
                (!$highestVersion || version_compare($newVersion, $highestVersion, '>'))) {
                $highestVersion = $newVersion;
            }
        }

        return $highestVersion;
    }

    private function getVersionsBetween(string $fromVersion, string $toVersion): array
    {
        preg_match('/^(\d+)/', $fromVersion, $matches);
        $major = (int)$matches[1];

        try {
            $url = sprintf(self::TYPO3_VERSIONS_URL, $major);
            $response = $this->getClient()->get($url);
            $data = json_decode((string)$response->getBody(), true);

            if (!$data || !is_array($data)) {
                throw new \RuntimeException('Could not fetch TYPO3 versions');
            }

            $versions = array_map(fn($release) => $this->normalizeVersion($release['version']), $data);

            return array_values(array_filter($versions, function($version) use ($fromVersion, $toVersion) {
                return version_compare($version, $fromVersion, '>')
                    && version_compare($version, $toVersion, '<=');
            }));

        } catch (\Exception $e) {
            throw new \RuntimeException('Could not fetch versions: ' . $e->getMessage());
        }
    }

    private function collectIssues(array $versions): array
    {
        $allIssues = [];
        foreach ($versions as $version) {
            $releaseData = $this->fetchReleaseData($version);
            $issues = $this->parseReleaseData($releaseData);

            if (!empty($issues['breaking']) || !empty($issues['security'])) {
                $allIssues[$version] = [
                    'issues' => $issues,
                    'releaseData' => $releaseData
                ];
            }
        }
        return $allIssues;
    }

    private function displayIssuesIfPresent(array $allIssues): void
    {
        if (!empty($allIssues)) {
            $this->displayAllIssues($allIssues);
        } else {
            $this->io->write('<info>No breaking changes or security updates found in the versions between.</info>');
        }
    }

    private function displayAllIssues(array $allIssues): void
    {
        foreach ($allIssues as $version => $data) {
            $this->io->write(sprintf("\n<info>Changes in version %s:</info>", $version));
            $this->displayIssues($data['issues'], $data['releaseData']);
        }
    }

    private function checkChangelogAndConfirm(array $allIssues): bool
    {
        if (!$this->io->isInteractive()) {
            return true;
        }

        $hasBreakingChanges = array_reduce($allIssues,
            fn($carry, $data) => $carry || !empty($data['issues']['breaking']),
            false
        );

        $question = $hasBreakingChanges
            ? 'Breaking changes were found. Do you want to continue with the update? [y/N] '
            : 'Do you want to continue with the update? [y/N] ';

        return $this->io->askConfirmation($question, false);
    }

    private function fetchReleaseData(string $version): array
    {
        $response = $this->getClient()->get(sprintf(self::TYPO3_API_URL, $version));
        $data = json_decode((string)$response->getBody(), true);

        if (!$data || !isset($data['release_notes'])) {
            throw new \RuntimeException('Invalid release notes format');
        }

        return $data;
    }

    private function parseReleaseData(array $data): array
    {
        $notes = $data['release_notes'];
        $issues = [
            'breaking' => [],
            'security' => [],
            'security_advisories' => [],
        ];

        if (isset($notes['news'])) {
            preg_match_all(
                '#https://typo3\.org/security/advisory/[^\s\n]+#',
                $notes['news'],
                $matches
            );
            $issues['security_advisories'] = $matches[0] ?? [];
        }

        $changes = explode("\n", $notes['changes']);
        foreach ($changes as $change) {
            if (str_contains($change, '[!!!]')) {
                $issues['breaking'][] = trim($change, " *\n");
            }
            if (str_contains($change, '[SECURITY]')) {
                $issues['security'][] = trim($change, " *\n");
            }
        }

        return $issues;
    }

    private function displayIssues(array $issues, array $releaseData): void
    {
        $hasIssues = false;

        if (!empty($issues['breaking'])) {
            $hasIssues = true;
            $this->io->warning('Breaking Changes Found:');
            foreach ($issues['breaking'] as $change) {
                $this->io->write("  <error>!</error> " . $change);
            }
        }

        if (!empty($issues['security'])) {
            $hasIssues = true;
            $this->io->warning('Security Updates Found:');
            foreach ($issues['security'] as $update) {
                $this->io->write("  <comment>✓</comment> " . $update);
            }
        }

        if (!empty($issues['security_advisories'])) {
            $this->io->write("\n<info>Security Advisories:</info>");
            foreach ($issues['security_advisories'] as $advisory) {
                $this->io->write("  - " . $advisory);
            }
        }

        if (!empty($releaseData['release_notes']['news_link'])) {
            $this->io->write("\n<info>Release announcement:</info> " . $releaseData['release_notes']['news_link']);
        }

        if ($hasIssues && !empty($releaseData['release_notes']['upgrading_instructions'])) {
            $this->io->write("\n<info>Upgrade instructions:</info>");
            $this->io->write("  " . str_replace("\n", "\n  ", $releaseData['release_notes']['upgrading_instructions']));
        }
    }

    private function normalizeVersion(string $version): ?string
    {
        $version = preg_replace('/(-dev|-alpha|-beta|-rc\d*)/i', '', trim($version, 'v'));

        // Validate version format (major.minor.patch)
        if (!preg_match('/^\d+\.\d+\.\d+(?:\.\d+)?$/', $version)) {
            return null;
        }

        // Reject versions with placeholder numbers
        if (str_contains($version, '9999')) {
            return null;
        }

        $parts = array_slice(explode('.', $version), 0, 3);
        while (count($parts) > 1 && end($parts) === '0') {
            array_pop($parts);
        }

        return implode('.', $parts);
    }

    private function getClient(): object
    {
        if ($this->client === null) {
            if (!class_exists('GuzzleHttp\Client')) {
                throw new \RuntimeException(
                    'The guzzlehttp/guzzle package is required. Please run: composer require guzzlehttp/guzzle:^7.0'
                );
            }

            $this->client = new \GuzzleHttp\Client([
                'timeout' => 10,
                'headers' => ['Accept' => 'application/json'],
            ]);
        }

        return $this->client;
    }
}
