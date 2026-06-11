<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Release;

use Plan2net\Typo3UpdateCheck\Advisory\AdvisoryProvider;
use Plan2net\Typo3UpdateCheck\Cache\CacheInterface;
use Plan2net\Typo3UpdateCheck\Change\ChangeParser;
use Plan2net\Typo3UpdateCheck\Http\HttpClient;
use Plan2net\Typo3UpdateCheck\Http\HttpResponse;
use Plan2net\Typo3UpdateCheck\Http\HttpTransportException;

final class ReleaseProvider
{
    private const API_BASE_URL = 'https://get.typo3.org/api/v1';

    private ApiFailureClassifier $classifier;

    public function __construct(
        private readonly HttpClient $httpClient,
        private readonly ChangeParser $changeParser,
        private readonly ?CacheInterface $cache = null,
        ?ApiFailureClassifier $classifier = null,
        private readonly ?AdvisoryProvider $advisoryProvider = null,
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
            $response = $this->httpClient->get($url);
            $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $exception) {
            throw new ApiFailureException($this->classifier->fromThrowable($exception));
        }

        $this->cache?->set($cacheKey, $data);

        return $this->mapReleases($data);
    }

    /**
     * @param string[] $versions
     */
    public function getReleaseContents(array $versions, ?string $fromVersion = null): ReleaseContentBatch
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

            return new ReleaseContentBatch(
                results: $this->enrichWithAdvisories($results, $versions, $fromVersion),
                failures: [],
            );
        }

        $urls = [];
        foreach ($uncached as $version) {
            $urls[$version] = $this->apiBaseUrl . '/release/' . $version . '/content';
        }

        /** @var array<string, HttpResponse|HttpTransportException> $outcomes */
        $outcomes = $this->httpClient->getMany($urls);
        foreach ($outcomes as $version => $outcome) {
            if (!$outcome instanceof HttpResponse) {
                $failures[$version] = $this->classifier->fromThrowable($outcome);
                continue;
            }

            try {
                $data = json_decode($outcome->body, true, 512, JSON_THROW_ON_ERROR);
                $this->cache?->set("content-{$version}", $data);
                $results[$version] = $this->changeParser->parse($data);
            } catch (\JsonException $exception) {
                $failures[$version] = $this->classifier->fromThrowable($exception);
            }
        }

        uksort($results, static fn (string $a, string $b) => version_compare($a, $b));
        uksort($failures, static fn (string $a, string $b) => version_compare($a, $b));

        return new ReleaseContentBatch(
            results: $this->enrichWithAdvisories($results, $versions, $fromVersion),
            failures: $failures,
        );
    }

    /**
     * @param array<string, ReleaseContent> $results
     * @param string[]                      $requestedVersions
     *
     * @return array<string, ReleaseContent>
     */
    private function enrichWithAdvisories(array $results, array $requestedVersions, ?string $fromVersion): array
    {
        if ($this->advisoryProvider === null) {
            return $results;
        }

        usort($requestedVersions, static fn (string $a, string $b) => version_compare($a, $b));

        $previousVersion = $fromVersion;
        foreach ($requestedVersions as $version) {
            if ($previousVersion !== null && isset($results[$version])) {
                $advisories = $this->advisoryProvider->advisoriesFixedIn($previousVersion, $version);
                if ($advisories !== []) {
                    $results[$version] = $results[$version]->withAdvisories(...$advisories);
                }
            }
            $previousVersion = $version;
        }

        return $results;
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
