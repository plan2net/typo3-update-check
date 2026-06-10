<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Tests;

use Composer\Util\HttpDownloader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\Typo3UpdateCheck\Http\ComposerHttpClient;
use Plan2net\Typo3UpdateCheck\Release\ReleaseProvider;
use Plan2net\Typo3UpdateCheck\ReleaseProviderFactory;

final class ReleaseProviderFactoryTest extends TestCase
{
    #[Test]
    public function createsProviderBackedByComposerHttpClient(): void
    {
        $downloader = $this->createMock(HttpDownloader::class);

        $provider = ReleaseProviderFactory::create($downloader);

        $this->assertInstanceOf(ReleaseProvider::class, $provider);

        $reflection = new \ReflectionObject($provider);
        $httpClientProperty = $reflection->getProperty('httpClient');
        $this->assertInstanceOf(ComposerHttpClient::class, $httpClientProperty->getValue($provider));
    }

    #[Test]
    public function createsCacheManagerOnlyWhenCacheDirGiven(): void
    {
        $downloader = $this->createMock(HttpDownloader::class);

        $providerWithoutCache = ReleaseProviderFactory::create($downloader);
        $reflection = new \ReflectionObject($providerWithoutCache);
        $cacheProperty = $reflection->getProperty('cache');
        $this->assertNull($cacheProperty->getValue($providerWithoutCache));

        $providerWithCache = ReleaseProviderFactory::create($downloader, sys_get_temp_dir() . '/typo3-update-check-factory-test');
        $reflection = new \ReflectionObject($providerWithCache);
        $cacheProperty = $reflection->getProperty('cache');
        $this->assertNotNull($cacheProperty->getValue($providerWithCache));
    }
}
