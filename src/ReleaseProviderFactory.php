<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck;

use Composer\Util\HttpDownloader;
use Plan2net\Typo3UpdateCheck\Cache\CacheManager;
use Plan2net\Typo3UpdateCheck\Change\ChangeFactory;
use Plan2net\Typo3UpdateCheck\Change\ChangeParser;
use Plan2net\Typo3UpdateCheck\Http\ComposerHttpClient;
use Plan2net\Typo3UpdateCheck\Release\ApiFailureClassifier;
use Plan2net\Typo3UpdateCheck\Release\ReleaseProvider;
use Plan2net\Typo3UpdateCheck\Security\SecurityBulletinFetcher;

final class ReleaseProviderFactory
{
    public static function create(HttpDownloader $httpDownloader, ?string $cacheDir = null): ReleaseProvider
    {
        $apiHttpClient = new ComposerHttpClient($httpDownloader, ['Accept: application/json']);
        $bulletinHttpClient = new ComposerHttpClient($httpDownloader);

        $cache = $cacheDir !== null ? new CacheManager($cacheDir) : null;
        $bulletinFetcher = new SecurityBulletinFetcher($bulletinHttpClient, $cache);
        $changeParser = new ChangeParser(new ChangeFactory(), $bulletinFetcher);

        return new ReleaseProvider($apiHttpClient, $changeParser, $cache, new ApiFailureClassifier());
    }
}
