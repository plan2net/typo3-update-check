<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Tests\Security;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\Typo3UpdateCheck\Cache\CacheInterface;
use Plan2net\Typo3UpdateCheck\Security\SecurityBulletinFetcher;

final class SecurityBulletinFetcherTest extends TestCase
{
    #[Test]
    public function fetchesSeveritiesFromSecurityBulletins(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);

        $httpClient->expects($this->exactly(3))
            ->method('request')
            ->willReturnCallback(function ($method, $url) {
                return match ($url) {
                    'https://typo3.org/security/advisory/typo3-core-sa-2025-001' => new Response(
                        200,
                        [],
                        '<li><strong>Severity:</strong> High</li>'
                    ),
                    'https://typo3.org/security/advisory/typo3-core-sa-2025-002' => new Response(
                        200,
                        [],
                        '<li><strong>Severity:</strong> Low</li>'
                    ),
                    'https://typo3.org/security/advisory/typo3-core-sa-2025-003' => new Response(
                        200,
                        [],
                        '<li><strong>Severity:</strong> High</li>'
                    ),
                    default => new Response(404),
                };
            });

        $fetcher = new SecurityBulletinFetcher($httpClient);

        $bulletinUrls = [
            'https://typo3.org/security/advisory/typo3-core-sa-2025-001',
            'https://typo3.org/security/advisory/typo3-core-sa-2025-002',
            'https://typo3.org/security/advisory/typo3-core-sa-2025-003',
        ];

        $severities = $fetcher->fetchSeverities($bulletinUrls);

        $this->assertEquals(['High' => 2, 'Low' => 1], $severities);
    }

    #[Test]
    public function handlesMissingAndFailedBulletins(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);

        $httpClient->expects($this->exactly(3))
            ->method('request')
            ->willReturnCallback(function ($method, $url) {
                return match ($url) {
                    'https://typo3.org/security/advisory/typo3-core-sa-2025-001' => new Response(
                        200,
                        [],
                        '<li><strong>Severity:</strong> Medium</li>'
                    ),
                    'https://typo3.org/security/advisory/typo3-core-sa-2025-002' => throw new \RuntimeException('Network error'),
                    'https://typo3.org/security/advisory/typo3-core-sa-2025-003' => new Response(
                        200,
                        [],
                        '<html>No severity information</html>'
                    ),
                    default => new Response(404),
                };
            });

        $fetcher = new SecurityBulletinFetcher($httpClient);

        $bulletinUrls = [
            'https://typo3.org/security/advisory/typo3-core-sa-2025-001',
            'https://typo3.org/security/advisory/typo3-core-sa-2025-002',
            'https://typo3.org/security/advisory/typo3-core-sa-2025-003',
        ];

        $severities = $fetcher->fetchSeverities($bulletinUrls);

        // Only the successful fetch with severity should be counted
        $this->assertEquals(['Medium' => 1], $severities);
    }

    #[Test]
    public function usesCacheForBulletinFetches(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
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

        $httpClient->expects($this->exactly(2))
            ->method('request')
            ->willReturn(new Response(
                200,
                [],
                '<li><strong>Severity:</strong> High</li>'
            ));

        $fetcher = new SecurityBulletinFetcher($httpClient, $cache);

        $bulletinUrls = [
            'https://typo3.org/security/advisory/typo3-core-sa-2025-001',
            'https://typo3.org/security/advisory/typo3-core-sa-2025-002',
        ];

        $severities = $fetcher->fetchSeverities($bulletinUrls);
        $this->assertEquals(['High' => 2], $severities);
    }

    #[Test]
    public function returnsCachedSeverities(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $cache = $this->createMock(CacheInterface::class);

        // Cache hits - no HTTP requests
        $cache->expects($this->exactly(2))
            ->method('get')
            ->willReturnOnConsecutiveCalls(
                ['severity' => 'High'],
                ['severity' => 'Low']
            );

        $httpClient->expects($this->never())
            ->method('request');

        $fetcher = new SecurityBulletinFetcher($httpClient, $cache);

        $bulletinUrls = [
            'https://typo3.org/security/advisory/typo3-core-sa-2025-001',
            'https://typo3.org/security/advisory/typo3-core-sa-2025-002',
        ];

        $severities = $fetcher->fetchSeverities($bulletinUrls);
        $this->assertEquals(['High' => 1, 'Low' => 1], $severities);
    }
}
