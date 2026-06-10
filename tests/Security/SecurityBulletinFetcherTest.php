<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Tests\Security;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\Typo3UpdateCheck\Cache\CacheInterface;
use Plan2net\Typo3UpdateCheck\Security\SecurityBulletinFetcher;

final class SecurityBulletinFetcherTest extends TestCase
{
    #[Test]
    public function fetchesBulletinsConcurrentlyThroughAsyncPool(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);

        $httpClient->expects($this->never())->method('request');
        $httpClient->expects($this->exactly(2))
            ->method('sendAsync')
            ->willReturnCallback(static fn () => new FulfilledPromise(
                new Response(200, [], '<li><strong>Severity:</strong> High</li>')
            ));

        $fetcher = new SecurityBulletinFetcher($httpClient);

        $severities = $fetcher->fetchSeverities([
            'https://typo3.org/security/advisory/typo3-core-sa-2025-001',
            'https://typo3.org/security/advisory/typo3-core-sa-2025-002',
        ]);

        $this->assertEquals(['High' => 2], $severities);
    }

    #[Test]
    public function fetchesSeveritiesFromSecurityBulletins(): void
    {
        $mock = new MockHandler([
            new Response(200, [], '<li><strong>Severity:</strong> High</li>'),
            new Response(200, [], '<li><strong>Severity:</strong> Low</li>'),
            new Response(200, [], '<li><strong>Severity:</strong> High</li>'),
        ]);
        $httpClient = new Client(['handler' => HandlerStack::create($mock)]);

        $fetcher = new SecurityBulletinFetcher($httpClient);

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
        $mock = new MockHandler([
            new Response(200, [], '<li><strong>Severity:</strong> Medium</li>'),
            new \RuntimeException('Network error'),
            new Response(200, [], '<html>No severity information</html>'),
        ]);
        $httpClient = new Client(['handler' => HandlerStack::create($mock)]);

        $fetcher = new SecurityBulletinFetcher($httpClient);

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

        $mock = new MockHandler([
            new Response(200, [], '<li><strong>Severity:</strong> High</li>'),
            new Response(200, [], '<li><strong>Severity:</strong> High</li>'),
        ]);
        $httpClient = new Client(['handler' => HandlerStack::create($mock)]);

        $fetcher = new SecurityBulletinFetcher($httpClient, $cache);

        $severities = $fetcher->fetchSeverities([
            'https://typo3.org/security/advisory/typo3-core-sa-2025-001',
            'https://typo3.org/security/advisory/typo3-core-sa-2025-002',
        ]);
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

        $httpClient->expects($this->never())->method('request');
        $httpClient->expects($this->never())->method('sendAsync');

        $fetcher = new SecurityBulletinFetcher($httpClient, $cache);

        $severities = $fetcher->fetchSeverities([
            'https://typo3.org/security/advisory/typo3-core-sa-2025-001',
            'https://typo3.org/security/advisory/typo3-core-sa-2025-002',
        ]);
        $this->assertEquals(['High' => 1, 'Low' => 1], $severities);
    }
}
