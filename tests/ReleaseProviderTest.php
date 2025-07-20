<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Tests;

use GuzzleHttp\ClientInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\Typo3UpdateCheck\Cache\CacheInterface;
use Plan2net\Typo3UpdateCheck\Release\Release;
use Plan2net\Typo3UpdateCheck\Release\ReleaseProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

final class ReleaseProviderTest extends TestCase
{
    #[Test]
    public function fetchesReleasesForMajorVersion(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $stream = $this->createMock(StreamInterface::class);

        $stream->method('__toString')->willReturn(json_encode([
            [
                'version' => '12.4.34',
                'date' => '2025-07-08T09:21:19+02:00',
                'type' => 'regular',
                'elts' => false,
                'tar_package' => [
                    'md5sum' => '9aade6e978903acaff8c3beff2a61ec0',
                    'sha1sum' => '098ee8d1d0d2c41587dbf0828b0bbf69bd9a4b66',
                    'sha256sum' => 'd38c1cbe1612a971dda885520e73e5d6e89f1f71af7765e2c16781e64bd37d7a',
                ],
                'zip_package' => [
                    'md5sum' => '6e5cd4fc0ff06b22e7d706efb05daffa',
                    'sha1sum' => '58366ce39c246d8d702321990ff59309d9c73335',
                    'sha256sum' => '231ff3b059fb86450dfa6c039839d64b060b7683d7b9ff680d0992bb1fb364c0',
                ],
            ],
            [
                'version' => '12.4.31',
                'date' => '2025-05-20T09:30:27+02:00',
                'type' => 'security',
                'elts' => false,
                'tar_package' => [
                    'md5sum' => '6566f7f1298c3771bb19ea145aa96377',
                    'sha1sum' => '03eba7cda48986ba0d0a12bc1f6f9af9a1d20488',
                    'sha256sum' => '69e71c0be15291eb56db09ab305c942b499da434a9d9042b9e0662b1a9783681',
                ],
                'zip_package' => [
                    'md5sum' => '2821c7ff8d470dbecd099b879ec8abd2',
                    'sha1sum' => 'c8910bbc09b9296194d0d3638b4b77f542814048',
                    'sha256sum' => '7784b53d9c30b53971ffaaae70a4aa1c51eea77bba9a1da58674f34975c048b4',
                ],
            ],
        ]));

        $response->method('getBody')->willReturn($stream);

        $httpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'https://get.typo3.org/api/v1/major/12/release/')
            ->willReturn($response);

        $parser = new \Plan2net\Typo3UpdateCheck\Change\ChangeParser(new \Plan2net\Typo3UpdateCheck\Change\ChangeFactory());
        $provider = new ReleaseProvider($httpClient, $parser);
        $releases = $provider->getReleasesForMajorVersion(12);

        $this->assertCount(2, $releases);
        $this->assertContainsOnlyInstancesOf(Release::class, $releases);

        $this->assertSame('12.4.34', $releases[0]->version);
        $this->assertSame('regular', $releases[0]->type);
        $this->assertSame('2025-07-08T09:21:19+02:00', $releases[0]->date->format(\DateTimeInterface::ATOM));

        $this->assertSame('12.4.31', $releases[1]->version);
        $this->assertSame('security', $releases[1]->type);
        $this->assertSame('2025-05-20T09:30:27+02:00', $releases[1]->date->format(\DateTimeInterface::ATOM));
    }

    #[Test]
    public function usesReleaseCacheWhenAvailable(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $cache = $this->createMock(CacheInterface::class);

        $cachedData = [
            [
                'version' => '12.4.34',
                'date' => '2025-07-08T09:21:19+02:00',
                'type' => 'regular',
                'elts' => false,
                'tar_package' => ['md5sum' => '9aade6e978903acaff8c3beff2a61ec0'],
                'zip_package' => ['md5sum' => '6e5cd4fc0ff06b22e7d706efb05daffa'],
            ],
        ];

        $cache->expects($this->once())
            ->method('get')
            ->with('releases-v12')
            ->willReturn($cachedData);

        $cache->expects($this->never())
            ->method('set');

        $httpClient->expects($this->never())
            ->method('request');

        $parser = new \Plan2net\Typo3UpdateCheck\Change\ChangeParser(new \Plan2net\Typo3UpdateCheck\Change\ChangeFactory());
        $provider = new ReleaseProvider($httpClient, $parser, $cache);
        $releases = $provider->getReleasesForMajorVersion(12);

        $this->assertCount(1, $releases);
        $this->assertSame('12.4.34', $releases[0]->version);
    }

    #[Test]
    public function fetchesAndCachesReleasesWhenCacheMisses(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $stream = $this->createMock(StreamInterface::class);
        $cache = $this->createMock(CacheInterface::class);

        $apiData = [
            [
                'version' => '12.4.34',
                'date' => '2025-07-08T09:21:19+02:00',
                'type' => 'regular',
                'elts' => false,
                'tar_package' => ['md5sum' => '9aade6e978903acaff8c3beff2a61ec0'],
                'zip_package' => ['md5sum' => '6e5cd4fc0ff06b22e7d706efb05daffa'],
            ],
        ];

        $stream->method('__toString')->willReturn(json_encode($apiData));
        $response->method('getBody')->willReturn($stream);

        $cache->expects($this->once())
            ->method('get')
            ->with('releases-v12')
            ->willReturn(null);

        $cache->expects($this->once())
            ->method('set')
            ->with('releases-v12', $apiData);

        $httpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'https://get.typo3.org/api/v1/major/12/release/')
            ->willReturn($response);

        $parser = new \Plan2net\Typo3UpdateCheck\Change\ChangeParser(new \Plan2net\Typo3UpdateCheck\Change\ChangeFactory());
        $provider = new ReleaseProvider($httpClient, $parser, $cache);
        $releases = $provider->getReleasesForMajorVersion(12);

        $this->assertCount(1, $releases);
        $this->assertSame('12.4.34', $releases[0]->version);
    }

    #[Test]
    public function worksWithoutCacheManager(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $stream = $this->createMock(StreamInterface::class);

        $stream->method('__toString')->willReturn(json_encode([
            [
                'version' => '12.4.34',
                'date' => '2025-07-08T09:21:19+02:00',
                'type' => 'regular',
                'elts' => false,
                'tar_package' => ['md5sum' => '9aade6e978903acaff8c3beff2a61ec0'],
                'zip_package' => ['md5sum' => '6e5cd4fc0ff06b22e7d706efb05daffa'],
            ],
        ]));

        $response->method('getBody')->willReturn($stream);

        $httpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'https://get.typo3.org/api/v1/major/12/release/')
            ->willReturn($response);

        $parser = new \Plan2net\Typo3UpdateCheck\Change\ChangeParser(new \Plan2net\Typo3UpdateCheck\Change\ChangeFactory());
        $provider = new ReleaseProvider($httpClient, $parser, null);
        $releases = $provider->getReleasesForMajorVersion(12);

        $this->assertCount(1, $releases);
        $this->assertSame('12.4.34', $releases[0]->version);
    }
}
