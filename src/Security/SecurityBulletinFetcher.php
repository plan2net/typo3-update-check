<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Security;

use Plan2net\Typo3UpdateCheck\Cache\CacheInterface;
use Plan2net\Typo3UpdateCheck\Http\HttpClient;
use Plan2net\Typo3UpdateCheck\Http\HttpResponse;
use Plan2net\Typo3UpdateCheck\Http\HttpTransportException;

final class SecurityBulletinFetcher implements SecurityBulletinFetcherInterface
{
    public function __construct(
        private readonly HttpClient $httpClient,
        private readonly ?CacheInterface $cache = null,
    ) {
    }

    /**
     * @param string[] $bulletinUrls
     *
     * @return array<string, int>
     */
    public function fetchSeverities(array $bulletinUrls): array
    {
        $severities = [];
        $uncachedUrls = [];

        foreach ($bulletinUrls as $url) {
            $cachedSeverity = $this->cachedSeverity($url);
            if ($cachedSeverity !== null) {
                $severities[$cachedSeverity] = ($severities[$cachedSeverity] ?? 0) + 1;
            } else {
                $uncachedUrls[] = $url;
            }
        }

        if ($uncachedUrls === []) {
            return $severities;
        }

        /** @var array<int, HttpResponse|HttpTransportException> $outcomes */
        $outcomes = $this->httpClient->getMany($uncachedUrls);
        foreach ($outcomes as $index => $outcome) {
            if (!$outcome instanceof HttpResponse) {
                continue; // Ignore failed bulletin fetches
            }

            $severity = $this->parseSeverity($outcome->body);
            if ($severity === null) {
                continue;
            }

            try {
                $this->cache?->set($this->cacheKey($uncachedUrls[$index]), ['severity' => $severity]);
            } catch (\Throwable) {
                // Ignore cache write failures
            }

            $severities[$severity] = ($severities[$severity] ?? 0) + 1;
        }

        return $severities;
    }

    private function cachedSeverity(string $url): ?string
    {
        $cached = $this->cache?->get($this->cacheKey($url));
        $severity = $cached['severity'] ?? null;

        return is_string($severity) ? $severity : null;
    }

    private function parseSeverity(string $html): ?string
    {
        if (preg_match('/<strong>Severity:<\/strong>\s*(\w+)/i', $html, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function cacheKey(string $url): string
    {
        return 'security-bulletin-' . md5($url);
    }
}
