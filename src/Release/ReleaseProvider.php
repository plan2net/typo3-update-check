<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Release;

use GuzzleHttp\ClientInterface;
use Plan2net\Typo3UpdateCheck\Cache\CacheInterface;
use Plan2net\Typo3UpdateCheck\Change\ChangeParser;

final class ReleaseProvider
{
    private const API_BASE_URL = 'https://get.typo3.org/api/v1';

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly ChangeParser $changeParser,
        private readonly ?CacheInterface $cache = null,
    ) {
    }

    /**
     * @return Release[]
     *
     * @throws \Exception
     */
    public function getReleasesForMajorVersion(int $majorVersion): array
    {
        $cacheKey = "releases-v{$majorVersion}";

        if ($this->cache !== null) {
            $cachedData = $this->cache->get($cacheKey);
            if ($cachedData !== null) {
                return $this->mapReleases($cachedData);
            }
        }

        $url = sprintf('%s/major/%d/release/', self::API_BASE_URL, $majorVersion);
        try {
            $response = $this->httpClient->request('GET', $url);
            $body = (string) $response->getBody();
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new \RuntimeException('Failed to parse API response. The TYPO3 API might be temporarily unavailable.');
        }

        $this->cache?->set($cacheKey, $data);

        return $this->mapReleases($data);
    }

    /**
     * @throws \Exception
     */
    public function getReleaseContent(string $version): ReleaseContent
    {
        $cacheKey = "content-{$version}";

        if ($this->cache !== null) {
            $cachedData = $this->cache->get($cacheKey);
            if ($cachedData !== null) {
                return $this->changeParser->parse($cachedData);
            }
        }

        $url = sprintf('%s/release/%s/content', self::API_BASE_URL, $version);
        try {
            $response = $this->httpClient->request('GET', $url);
            $body = (string) $response->getBody();

            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new \RuntimeException('Failed to parse API response for version ' . $version . '. The TYPO3 API might be temporarily unavailable.');
        }

        $this->cache?->set($cacheKey, $data);

        return $this->changeParser->parse($data);
    }

    /**
     * @param array<mixed> $data
     *
     * @return Release[]
     *
     * @throws \Exception
     */
    private function mapReleases(array $data): array
    {
        return array_map(
            fn (array $item) => new Release(
                version: $item['version'],
                type: $item['type'],
                date: new \DateTimeImmutable($item['date']),
            ),
            $data
        );
    }
}
