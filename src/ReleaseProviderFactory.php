<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck;

use GuzzleHttp\Client;
use Plan2net\Typo3UpdateCheck\Cache\CacheManager;
use Plan2net\Typo3UpdateCheck\Change\ChangeFactory;
use Plan2net\Typo3UpdateCheck\Change\ChangeParser;
use Plan2net\Typo3UpdateCheck\Release\ReleaseProvider;
use Plan2net\Typo3UpdateCheck\Security\SecurityBulletinFetcher;

final class ReleaseProviderFactory
{
    public static function create(?string $cacheDir = null): ReleaseProvider
    {
        $httpClient = new Client([
            'timeout' => 10,
            'headers' => ['Accept' => 'application/json'],
        ]);

        $cache = $cacheDir !== null ? new CacheManager($cacheDir) : null;
        $bulletinFetcher = new SecurityBulletinFetcher($httpClient, $cache);
        $changeParser = new ChangeParser(new ChangeFactory(), $bulletinFetcher);

        return new ReleaseProvider($httpClient, $changeParser, $cache);
    }
}
