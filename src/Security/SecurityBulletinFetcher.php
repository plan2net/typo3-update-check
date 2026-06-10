<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Security;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Plan2net\Typo3UpdateCheck\Cache\CacheInterface;
use Psr\Http\Message\ResponseInterface;

final class SecurityBulletinFetcher implements SecurityBulletinFetcherInterface
{
    private const CONCURRENCY = 5;

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

        $requests = static function () use ($uncachedUrls) {
            foreach ($uncachedUrls as $index => $url) {
                yield $index => new Request('GET', $url);
            }
        };

        $pool = new Pool($this->httpClient, $requests(), [
            'concurrency' => self::CONCURRENCY,
            'fulfilled' => function (ResponseInterface $response, int $index) use (&$severities, $uncachedUrls) {
                $severity = $this->parseSeverity((string) $response->getBody());
                if ($severity === null) {
                    return;
                }

                try {
                    $this->cache?->set($this->cacheKey($uncachedUrls[$index]), ['severity' => $severity]);
                } catch (\Throwable) {
                    // Ignore cache write failures
                }

                $severities[$severity] = ($severities[$severity] ?? 0) + 1;
            },
            'rejected' => static function (): void {
                // Ignore failed bulletin fetches
            },
        ]);

        $pool->promise()->wait();

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
