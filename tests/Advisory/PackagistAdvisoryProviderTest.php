<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Tests\Advisory;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\Typo3UpdateCheck\Advisory\PackagistAdvisoryProvider;
use Plan2net\Typo3UpdateCheck\Cache\CacheInterface;
use Plan2net\Typo3UpdateCheck\Http\HttpResponse;
use Plan2net\Typo3UpdateCheck\Http\HttpTransportException;
use Plan2net\Typo3UpdateCheck\Tests\Http\FakeHttpClient;

final class PackagistAdvisoryProviderTest extends TestCase
{
    /**
     * @param array<string, list<array<string, mixed>>> $advisoriesByPackage
     */
    private function packagistJson(array $advisoriesByPackage): string
    {
        return json_encode(['advisories' => $advisoriesByPackage], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function advisoryData(
        string $package = 'typo3/cms-core',
        ?string $cve = 'CVE-2025-0001',
        ?string $severity = 'high',
        string $affectedVersions = '>=12.0.0,<12.4.31',
        string $title = 'Some vulnerability',
        string $advisoryId = 'PKSA-test-0001',
    ): array {
        return [
            'advisoryId' => $advisoryId,
            'packageName' => $package,
            'title' => $title,
            'link' => 'https://github.com/advisories/GHSA-test',
            'cve' => $cve,
            'affectedVersions' => $affectedVersions,
            'severity' => $severity,
        ];
    }

    private function providerWith(FakeHttpClient $http, ?CacheInterface $cache = null): PackagistAdvisoryProvider
    {
        return new PackagistAdvisoryProvider($http, $cache, 'https://packagist.test');
    }

    private function queuePool(FakeHttpClient $http, string $json): void
    {
        $http->queueJson(
            'https://packagist.test/api/security-advisories/?' . PackagistAdvisoryProvider::packagesQueryString(),
            $json,
        );
    }

    #[Test]
    public function matchesAdvisoryFixedInVersion(): void
    {
        $http = new FakeHttpClient();
        $this->queuePool($http, $this->packagistJson([
            'typo3/cms-core' => [$this->advisoryData(affectedVersions: '>=12.0.0,<12.4.31')],
        ]));

        $advisories = $this->providerWith($http)->advisoriesFixedIn('12.4.30', '12.4.31');

        $this->assertCount(1, $advisories);
        $this->assertSame('CVE-2025-0001', $advisories[0]->cve);
        $this->assertSame('high', $advisories[0]->severity);
    }

    #[Test]
    public function ignoresAdvisoriesNotCoveringThePreviousVersion(): void
    {
        $http = new FakeHttpClient();
        $this->queuePool($http, $this->packagistJson([
            'typo3/cms-core' => [$this->advisoryData(affectedVersions: '>=13.0.0,<13.4.3')],
        ]));

        $this->assertSame([], $this->providerWith($http)->advisoriesFixedIn('12.4.30', '12.4.31'));
    }

    #[Test]
    public function ignoresAdvisoriesStillAffectingTheVersion(): void
    {
        $http = new FakeHttpClient();
        $this->queuePool($http, $this->packagistJson([
            'typo3/cms-core' => [$this->advisoryData(affectedVersions: '>=12.0.0,<12.4.40')],
        ]));

        $this->assertSame([], $this->providerWith($http)->advisoriesFixedIn('12.4.30', '12.4.31'));
    }

    #[Test]
    public function matchesInclusiveUpperBoundConstraints(): void
    {
        $http = new FakeHttpClient();
        $this->queuePool($http, $this->packagistJson([
            'typo3/cms-core' => [$this->advisoryData(affectedVersions: '>=12.0.0,<=12.4.30')],
        ]));

        $this->assertCount(1, $this->providerWith($http)->advisoriesFixedIn('12.4.30', '12.4.31'));
    }

    #[Test]
    public function matchesMultiRangeConstraints(): void
    {
        $http = new FakeHttpClient();
        $this->queuePool($http, $this->packagistJson([
            'typo3/cms-core' => [$this->advisoryData(affectedVersions: '>=11.0.0,<11.5.40|>=12.0.0,<12.4.31')],
        ]));

        $this->assertCount(1, $this->providerWith($http)->advisoriesFixedIn('12.4.30', '12.4.31'));
    }

    #[Test]
    public function deduplicatesSameCveAcrossPackages(): void
    {
        $http = new FakeHttpClient();
        $this->queuePool($http, $this->packagistJson([
            'typo3/cms-core' => [$this->advisoryData(package: 'typo3/cms-core')],
            'typo3/cms-setup' => [$this->advisoryData(package: 'typo3/cms-setup')],
        ]));

        $this->assertCount(1, $this->providerWith($http)->advisoriesFixedIn('12.4.30', '12.4.31'));
    }

    #[Test]
    public function fallsBackToAdvisoryIdWhenCveIsNull(): void
    {
        $http = new FakeHttpClient();
        $this->queuePool($http, $this->packagistJson([
            'typo3/cms-core' => [
                $this->advisoryData(cve: null, advisoryId: 'PKSA-aaaa'),
                $this->advisoryData(cve: null, advisoryId: 'PKSA-bbbb'),
            ],
        ]));

        $this->assertCount(2, $this->providerWith($http)->advisoriesFixedIn('12.4.30', '12.4.31'));
    }

    #[Test]
    public function normalizesSeverityToLowercaseAndNullsUnknownValues(): void
    {
        $http = new FakeHttpClient();
        $this->queuePool($http, $this->packagistJson([
            'typo3/cms-core' => [
                $this->advisoryData(cve: 'CVE-1', severity: 'HIGH'),
                $this->advisoryData(cve: 'CVE-2', severity: 'bogus'),
                $this->advisoryData(cve: 'CVE-3', severity: null),
            ],
        ]));

        $advisories = $this->providerWith($http)->advisoriesFixedIn('12.4.30', '12.4.31');

        $this->assertSame('high', $advisories[0]->severity);
        $this->assertNull($advisories[1]->severity);
        $this->assertNull($advisories[2]->severity);
    }

    #[Test]
    public function skipsAdvisoriesWithMalformedConstraints(): void
    {
        $http = new FakeHttpClient();
        $this->queuePool($http, $this->packagistJson([
            'typo3/cms-core' => [
                $this->advisoryData(cve: 'CVE-1', affectedVersions: 'not a constraint !!'),
                $this->advisoryData(cve: 'CVE-2'),
            ],
        ]));

        $advisories = $this->providerWith($http)->advisoriesFixedIn('12.4.30', '12.4.31');

        $this->assertCount(1, $advisories);
        $this->assertSame('CVE-2', $advisories[0]->cve);
    }

    #[Test]
    public function returnsEmptyAndStopsRetryingAfterHttpFailure(): void
    {
        $http = new FakeHttpClient();
        $http->queue(
            'https://packagist.test/api/security-advisories/?' . PackagistAdvisoryProvider::packagesQueryString(),
            HttpTransportException::forHttpError('server error', 503),
        );

        $provider = $this->providerWith($http);

        $this->assertSame([], $provider->advisoriesFixedIn('12.4.30', '12.4.31'));
        $this->assertSame([], $provider->advisoriesFixedIn('12.4.31', '12.4.32'));
        $this->assertCount(1, $http->requests);
    }

    #[Test]
    public function returnsEmptyOnMalformedJson(): void
    {
        $http = new FakeHttpClient();
        $http->queue(
            'https://packagist.test/api/security-advisories/?' . PackagistAdvisoryProvider::packagesQueryString(),
            new HttpResponse(200, [], '<html>not json</html>'),
        );

        $this->assertSame([], $this->providerWith($http)->advisoriesFixedIn('12.4.30', '12.4.31'));
    }

    #[Test]
    public function loadsPoolOnlyOnceForMultipleCalls(): void
    {
        $http = new FakeHttpClient();
        $this->queuePool($http, $this->packagistJson([
            'typo3/cms-core' => [$this->advisoryData()],
        ]));

        $provider = $this->providerWith($http);
        $provider->advisoriesFixedIn('12.4.30', '12.4.31');
        $provider->advisoriesFixedIn('12.4.31', '12.4.32');

        $this->assertCount(1, $http->requests);
    }

    #[Test]
    public function usesCacheAndSkipsHttpOnHit(): void
    {
        $http = new FakeHttpClient();
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->with('advisories-typo3')->willReturn([
            'typo3/cms-core' => [$this->advisoryData()],
        ]);

        $advisories = $this->providerWith($http, $cache)->advisoriesFixedIn('12.4.30', '12.4.31');

        $this->assertCount(1, $advisories);
        $this->assertSame([], $http->requests);
    }

    #[Test]
    public function writesPoolToCacheAfterFetch(): void
    {
        $http = new FakeHttpClient();
        $this->queuePool($http, $this->packagistJson([
            'typo3/cms-core' => [$this->advisoryData()],
        ]));
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturn(null);
        $cache->expects($this->once())->method('set')
            ->with('advisories-typo3', $this->callback('is_array'));

        $this->providerWith($http, $cache)->advisoriesFixedIn('12.4.30', '12.4.31');
    }

    #[Test]
    public function toleratesNonListPackageValuesFromApi(): void
    {
        $http = new FakeHttpClient();
        $this->queuePool($http, (string) json_encode([
            'advisories' => [
                'typo3/cms-core' => 'garbage',
                'typo3/cms-backend' => [$this->advisoryData(package: 'typo3/cms-backend')],
            ],
        ]));

        $advisories = $this->providerWith($http)->advisoriesFixedIn('12.4.30', '12.4.31');

        $this->assertCount(1, $advisories);
        $this->assertSame('typo3/cms-backend', $advisories[0]->packageName);
    }

    #[Test]
    public function toleratesPoisonedCacheEntries(): void
    {
        $http = new FakeHttpClient();
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->with('advisories-typo3')->willReturn([
            'typo3/cms-core' => 'garbage',
            'typo3/cms-backend' => [$this->advisoryData(package: 'typo3/cms-backend')],
        ]);

        $advisories = $this->providerWith($http, $cache)->advisoriesFixedIn('12.4.30', '12.4.31');

        $this->assertCount(1, $advisories);
        $this->assertSame([], $http->requests);
    }

    #[Test]
    public function keylessAdvisoriesNeverCollideWithNumericAdvisoryIds(): void
    {
        $http = new FakeHttpClient();
        $advisoryWithoutId = $this->advisoryData(cve: null, advisoryId: 'placeholder');
        unset($advisoryWithoutId['advisoryId']);
        $anotherWithoutId = $this->advisoryData(cve: null, advisoryId: 'placeholder');
        unset($anotherWithoutId['advisoryId']);
        $this->queuePool($http, $this->packagistJson([
            'typo3/cms-core' => [
                $advisoryWithoutId,
                $anotherWithoutId,
                $this->advisoryData(cve: null, advisoryId: '1'),
            ],
        ]));

        $this->assertCount(3, $this->providerWith($http)->advisoriesFixedIn('12.4.30', '12.4.31'));
    }

    #[Test]
    public function returnsEmptyWhenAdvisoriesKeyIsMissing(): void
    {
        $http = new FakeHttpClient();
        $http->queueJson(
            'https://packagist.test/api/security-advisories/?' . PackagistAdvisoryProvider::packagesQueryString(),
            (string) json_encode(['unexpected' => 'shape']),
        );

        $provider = $this->providerWith($http);

        $this->assertSame([], $provider->advisoriesFixedIn('12.4.30', '12.4.31'));
        $this->assertSame([], $provider->advisoriesFixedIn('12.4.31', '12.4.32'));
        $this->assertCount(1, $http->requests);
    }

    #[Test]
    public function swallowsCacheWriteFailures(): void
    {
        $http = new FakeHttpClient();
        $this->queuePool($http, $this->packagistJson([
            'typo3/cms-core' => [$this->advisoryData()],
        ]));
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willReturn(null);
        $cache->method('set')->willThrowException(new \RuntimeException('disk full'));

        $this->assertCount(1, $this->providerWith($http, $cache)->advisoriesFixedIn('12.4.30', '12.4.31'));
    }

    #[Test]
    public function skipsAdvisoriesWithEmptyOrMissingConstraints(): void
    {
        $emptyConstraint = $this->advisoryData(cve: 'CVE-1', affectedVersions: '');
        $missingConstraint = $this->advisoryData(cve: 'CVE-2');
        unset($missingConstraint['affectedVersions']);
        $http = new FakeHttpClient();
        $this->queuePool($http, $this->packagistJson([
            'typo3/cms-core' => [$emptyConstraint, $missingConstraint, $this->advisoryData(cve: 'CVE-3')],
        ]));

        $advisories = $this->providerWith($http)->advisoriesFixedIn('12.4.30', '12.4.31');

        $this->assertCount(1, $advisories);
        $this->assertSame('CVE-3', $advisories[0]->cve);
    }
}
