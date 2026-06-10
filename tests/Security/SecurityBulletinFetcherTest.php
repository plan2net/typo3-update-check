<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Tests\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\Typo3UpdateCheck\Cache\CacheInterface;
use Plan2net\Typo3UpdateCheck\Http\HttpResponse;
use Plan2net\Typo3UpdateCheck\Http\HttpTransportException;
use Plan2net\Typo3UpdateCheck\Security\SecurityBulletinFetcher;
use Plan2net\Typo3UpdateCheck\Tests\Http\FakeHttpClient;

final class SecurityBulletinFetcherTest extends TestCase
{
    #[Test]
    public function fetchesBulletinsConcurrentlyViaGetMany(): void
    {
        $http = new FakeHttpClient();
        $http->queue('https://typo3.org/security/advisory/typo3-core-sa-2025-001', new HttpResponse(200, [], '<li><strong>Severity:</strong> High</li>'));
        $http->queue('https://typo3.org/security/advisory/typo3-core-sa-2025-002', new HttpResponse(200, [], '<li><strong>Severity:</strong> High</li>'));

        $fetcher = new SecurityBulletinFetcher($http);
        $severities = $fetcher->fetchSeverities([
            'https://typo3.org/security/advisory/typo3-core-sa-2025-001',
            'https://typo3.org/security/advisory/typo3-core-sa-2025-002',
        ]);

        $this->assertEquals(['High' => 2], $severities);
        $this->assertSame(['getMany', 'getMany'], array_column($http->requests, 'method'));
    }

    #[Test]
    public function fetchesSeveritiesFromSecurityBulletins(): void
    {
        $http = new FakeHttpClient();
        $http->queue('https://typo3.org/security/advisory/typo3-core-sa-2025-001', new HttpResponse(200, [], '<li><strong>Severity:</strong> High</li>'));
        $http->queue('https://typo3.org/security/advisory/typo3-core-sa-2025-002', new HttpResponse(200, [], '<li><strong>Severity:</strong> Low</li>'));
        $http->queue('https://typo3.org/security/advisory/typo3-core-sa-2025-003', new HttpResponse(200, [], '<li><strong>Severity:</strong> High</li>'));

        $fetcher = new SecurityBulletinFetcher($http);

        $severities = $fetcher->fetchSeverities([
            'https://typo3.org/security/advisory/typo3-core-sa-2025-001',
            'https://typo3.org/security/advisory/typo3-core-sa-2025-002',
            'https://typo3.org/security/advisory/typo3-core-sa-2025-003',
        ]);

        $this->assertEquals(['High' => 2, 'Low' => 1], $severities);
    }

    #[Test]
    public function handlesMissingAndFailedBulletins(): void
    {
        $http = new FakeHttpClient();
        $http->queue('https://typo3.org/security/advisory/typo3-core-sa-2025-001', new HttpResponse(200, [], '<li><strong>Severity:</strong> Medium</li>'));
        $http->queue('https://typo3.org/security/advisory/typo3-core-sa-2025-002', HttpTransportException::forConnectionError('network error'));
        $http->queue('https://typo3.org/security/advisory/typo3-core-sa-2025-003', new HttpResponse(200, [], '<html>No severity information</html>'));

        $fetcher = new SecurityBulletinFetcher($http);

        $severities = $fetcher->fetchSeverities([
            'https://typo3.org/security/advisory/typo3-core-sa-2025-001',
            'https://typo3.org/security/advisory/typo3-core-sa-2025-002',
            'https://typo3.org/security/advisory/typo3-core-sa-2025-003',
        ]);

        // Only the successful fetch with severity should be counted
        $this->assertEquals(['Medium' => 1], $severities);
    }

    #[Test]
    public function usesCacheForBulletinFetches(): void
    {
        $cache = $this->createMock(CacheInterface::class);

        // First fetch - cache miss, fetches from HTTP
        $cache->expects($this->exactly(2))
            ->method('get')
            ->willReturn(null);

        $cache->expects($this->exactly(2))
            ->method('set')
            ->with(
                $this->matchesRegularExpression('/^security-bulletin-/'),
                $this->equalTo(['severity' => 'High'])
            );

        $http = new FakeHttpClient();
        $http->queue('https://typo3.org/security/advisory/typo3-core-sa-2025-001', new HttpResponse(200, [], '<li><strong>Severity:</strong> High</li>'));
        $http->queue('https://typo3.org/security/advisory/typo3-core-sa-2025-002', new HttpResponse(200, [], '<li><strong>Severity:</strong> High</li>'));

        $fetcher = new SecurityBulletinFetcher($http, $cache);

        $severities = $fetcher->fetchSeverities([
            'https://typo3.org/security/advisory/typo3-core-sa-2025-001',
            'https://typo3.org/security/advisory/typo3-core-sa-2025-002',
        ]);
        $this->assertEquals(['High' => 2], $severities);
    }

    #[Test]
    public function returnsCachedSeverities(): void
    {
        $http = new FakeHttpClient();
        $cache = $this->createMock(CacheInterface::class);

        // Cache hits - no HTTP requests
        $cache->expects($this->exactly(2))
            ->method('get')
            ->willReturnOnConsecutiveCalls(
                ['severity' => 'High'],
                ['severity' => 'Low']
            );

        $fetcher = new SecurityBulletinFetcher($http, $cache);

        $severities = $fetcher->fetchSeverities([
            'https://typo3.org/security/advisory/typo3-core-sa-2025-001',
            'https://typo3.org/security/advisory/typo3-core-sa-2025-002',
        ]);
        $this->assertEquals(['High' => 1, 'Low' => 1], $severities);
        $this->assertSame([], $http->requests);
    }
}
