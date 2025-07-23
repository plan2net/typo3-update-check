<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Security;

use GuzzleHttp\ClientInterface;
use Plan2net\Typo3UpdateCheck\Cache\CacheInterface;

final class SecurityBulletinFetcher implements SecurityBulletinFetcherInterface
{
    public function __construct(
        private readonly ClientInterface $httpClient,
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

        foreach ($bulletinUrls as $url) {
            $severity = $this->fetchSeverityForBulletin($url);
            if ($severity !== null) {
                $severities[$severity] = ($severities[$severity] ?? 0) + 1;
            }
        }

        return $severities;
    }

    private function fetchSeverityForBulletin(string $url): ?string
    {
        $cacheKey = 'security-bulletin-' . md5($url);

        if ($this->cache !== null) {
            $cachedSeverity = $this->cache->get($cacheKey);
            if ($cachedSeverity !== null && isset($cachedSeverity['severity'])) {
                return $cachedSeverity['severity'];
            }
        }

        try {
            $response = $this->httpClient->request('GET', $url);
            $html = (string) $response->getBody();

            if (preg_match('/<strong>Severity:<\/strong>\s*(\w+)/i', $html, $matches)) {
                $severity = $matches[1];
                $this->cache?->set($cacheKey, ['severity' => $severity]);

                return $severity;
            }
        } catch (\Throwable) {
            // Ignore failed bulletin fetches
        }

        return null;
    }
}
