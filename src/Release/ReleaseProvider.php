<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Release;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Plan2net\Typo3UpdateCheck\Cache\CacheInterface;
use Plan2net\Typo3UpdateCheck\Change\ChangeParser;

final class ReleaseProvider
{
    private const API_BASE_URL = 'https://get.typo3.org/api/v1';

    private ApiFailureClassifier $classifier;

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly ChangeParser $changeParser,
        private readonly ?CacheInterface $cache = null,
        ?ApiFailureClassifier $classifier = null,
        private readonly string $apiBaseUrl = self::API_BASE_URL,
    ) {
        $this->classifier = $classifier ?? new ApiFailureClassifier();
    }

    /**
     * @return Release[]
     *
     * @throws ApiFailureException
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

        $url = sprintf('%s/major/%d/release/', $this->apiBaseUrl, $majorVersion);
        try {
            $response = $this->httpClient->request('GET', $url);
            $body = (string) $response->getBody();
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            throw new ApiFailureException($this->classifier->fromThrowable($exception));
        }

        $this->cache?->set($cacheKey, $data);

        return $this->mapReleases($data);
    }

    /**
     * @param string[] $versions
     */
    public function getReleaseContents(array $versions): ReleaseContentBatch
    {
        $results = [];
        $failures = [];
        $uncached = [];

        foreach ($versions as $version) {
            $cacheKey = "content-{$version}";
            $cached = $this->cache?->get($cacheKey);
            if ($cached !== null) {
                $results[$version] = $this->changeParser->parse($cached);
            } else {
                $uncached[] = $version;
            }
        }

        if ($uncached === []) {
            uksort($results, static fn (string $a, string $b) => version_compare($a, $b));

            return new ReleaseContentBatch(results: $results, failures: []);
        }

        $requests = function () use ($uncached) {
            foreach ($uncached as $version) {
                yield $version => new Request('GET', $this->apiBaseUrl . '/release/' . $version . '/content');
            }
        };

        $pool = new Pool($this->httpClient, $requests(), [
            'concurrency' => 5,
            'fulfilled' => function (Response $response, string $version) use (&$results, &$failures) {
                try {
                    $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
                    $this->cache?->set("content-{$version}", $data);
                    $results[$version] = $this->changeParser->parse($data);
                } catch (\JsonException $exception) {
                    $failures[$version] = $this->classifier->fromThrowable($exception);
                }
            },
            'rejected' => function (\Throwable $reason, string $version) use (&$failures) {
                $failures[$version] = $this->classifier->fromThrowable($reason);
            },
        ]);

        $pool->promise()->wait();

        uksort($results, static fn (string $a, string $b) => version_compare($a, $b));
        uksort($failures, static fn (string $a, string $b) => version_compare($a, $b));

        return new ReleaseContentBatch(results: $results, failures: $failures);
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
