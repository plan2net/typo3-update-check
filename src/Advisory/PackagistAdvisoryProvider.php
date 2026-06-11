<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Advisory;

use Composer\Semver\Semver;
use Plan2net\Typo3UpdateCheck\Cache\CacheInterface;
use Plan2net\Typo3UpdateCheck\Http\HttpClient;

final class PackagistAdvisoryProvider implements AdvisoryProvider
{
    private const API_BASE_URL = 'https://packagist.org';
    private const CACHE_KEY = 'advisories-typo3';
    private const SEVERITIES = ['critical', 'high', 'medium', 'low'];

    private const PACKAGES = [
        'typo3/cms',
        'typo3/cms-about',
        'typo3/cms-adminpanel',
        'typo3/cms-backend',
        'typo3/cms-base-distribution',
        'typo3/cms-belog',
        'typo3/cms-beuser',
        'typo3/cms-cli',
        'typo3/cms-composer-installers',
        'typo3/cms-context-help',
        'typo3/cms-core',
        'typo3/cms-cshmanual',
        'typo3/cms-css-styled-content',
        'typo3/cms-dashboard',
        'typo3/cms-documentation',
        'typo3/cms-extbase',
        'typo3/cms-extensionmanager',
        'typo3/cms-feedit',
        'typo3/cms-felogin',
        'typo3/cms-filelist',
        'typo3/cms-filemetadata',
        'typo3/cms-fluid',
        'typo3/cms-fluid-styled-content',
        'typo3/cms-form',
        'typo3/cms-frontend',
        'typo3/cms-func',
        'typo3/cms-impexp',
        'typo3/cms-indexed-search',
        'typo3/cms-info',
        'typo3/cms-info-pagetsconfig',
        'typo3/cms-install',
        'typo3/cms-introduction',
        'typo3/cms-lang',
        'typo3/cms-linkvalidator',
        'typo3/cms-lowlevel',
        'typo3/cms-opendocs',
        'typo3/cms-reactions',
        'typo3/cms-recordlist',
        'typo3/cms-recycler',
        'typo3/cms-redirects',
        'typo3/cms-reports',
        'typo3/cms-rsaauth',
        'typo3/cms-rte-ckeditor',
        'typo3/cms-saltedpasswords',
        'typo3/cms-scheduler',
        'typo3/cms-seo',
        'typo3/cms-setup',
        'typo3/cms-styleguide',
        'typo3/cms-sv',
        'typo3/cms-sys-action',
        'typo3/cms-sys-note',
        'typo3/cms-t3editor',
        'typo3/cms-taskcenter',
        'typo3/cms-tstemplate',
        'typo3/cms-version',
        'typo3/cms-viewpage',
        'typo3/cms-webhooks',
        'typo3/cms-wizard-crpages',
        'typo3/cms-wizard-sortpages',
        'typo3/cms-workspaces',
    ];

    /** @var array<string, mixed>|null */
    private ?array $pool = null;
    private bool $loadFailed = false;

    public function __construct(
        private readonly HttpClient $httpClient,
        private readonly ?CacheInterface $cache = null,
        private readonly string $apiBaseUrl = self::API_BASE_URL,
    ) {
    }

    public static function packagesQueryString(): string
    {
        return implode('&', array_map(
            static fn (string $package): string => 'packages[]=' . urlencode($package),
            self::PACKAGES,
        ));
    }

    /**
     * @return list<string>
     */
    public static function packageNames(): array
    {
        return self::PACKAGES;
    }

    public function advisoriesFixedIn(string $previousVersion, string $version): array
    {
        $pool = $this->loadPool();
        if ($pool === null) {
            return [];
        }

        $advisories = [];
        $seen = [];

        foreach ($pool as $advisoryData) {
            $constraint = $advisoryData['affectedVersions'] ?? '';
            if (!is_string($constraint) || $constraint === '') {
                continue;
            }

            try {
                $fixedInVersion = Semver::satisfies($previousVersion, $constraint)
                    && !Semver::satisfies($version, $constraint);
            } catch (\UnexpectedValueException) {
                continue;
            }

            if (!$fixedInVersion) {
                continue;
            }

            $cve = is_string($advisoryData['cve'] ?? null) ? $advisoryData['cve'] : null;
            $deduplicationKey = $cve
                ?? (isset($advisoryData['advisoryId']) ? (string) $advisoryData['advisoryId'] : '__keyless-' . count($seen));
            if (isset($seen[$deduplicationKey])) {
                continue;
            }
            $seen[$deduplicationKey] = true;

            $advisories[] = new Advisory(
                packageName: (string) ($advisoryData['packageName'] ?? ''),
                title: (string) ($advisoryData['title'] ?? ''),
                cve: $cve,
                severity: $this->normalizeSeverity($advisoryData['severity'] ?? null),
                link: (string) ($advisoryData['link'] ?? ''),
                affectedVersions: $constraint,
            );
        }

        return $advisories;
    }

    /**
     * @return list<array<string, mixed>>|null null when the pool is unavailable
     */
    private function loadPool(): ?array
    {
        if ($this->loadFailed) {
            return null;
        }
        if ($this->pool !== null) {
            return $this->flattenPool($this->pool);
        }

        $cached = $this->cache?->get(self::CACHE_KEY);
        if (is_array($cached)) {
            /** @var array<string, mixed> $cached */
            $this->pool = $cached;

            return $this->flattenPool($cached);
        }

        try {
            $response = $this->httpClient->get(
                $this->apiBaseUrl . '/api/security-advisories/?' . self::packagesQueryString(),
            );
            $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $this->loadFailed = true;

            return null;
        }

        $advisoriesByPackage = is_array($data) && is_array($data['advisories'] ?? null)
            ? $data['advisories']
            : null;
        if ($advisoriesByPackage === null) {
            $this->loadFailed = true;

            return null;
        }

        /** @var array<string, mixed> $advisoriesByPackage */
        $this->pool = $advisoriesByPackage;
        try {
            $this->cache?->set(self::CACHE_KEY, $advisoriesByPackage);
        } catch (\Throwable) {
            // Ignore cache write failures
        }

        return $this->flattenPool($advisoriesByPackage);
    }

    /**
     * @param array<string, mixed> $pool
     *
     * @return list<array<string, mixed>>
     */
    private function flattenPool(array $pool): array
    {
        $flattened = [];
        foreach ($pool as $advisories) {
            if (!is_array($advisories)) {
                continue;
            }
            foreach ($advisories as $advisoryData) {
                if (is_array($advisoryData)) {
                    $flattened[] = $advisoryData;
                }
            }
        }

        return $flattened;
    }

    private function normalizeSeverity(mixed $severity): ?string
    {
        if (!is_string($severity)) {
            return null;
        }

        $normalized = strtolower($severity);

        return in_array($normalized, self::SEVERITIES, true) ? $normalized : null;
    }
}
