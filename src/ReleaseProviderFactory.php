<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck;

use Composer\Util\HttpDownloader;
use Plan2net\Typo3UpdateCheck\Advisory\PackagistAdvisoryProvider;
use Plan2net\Typo3UpdateCheck\Cache\CacheManager;
use Plan2net\Typo3UpdateCheck\Change\ChangeFactory;
use Plan2net\Typo3UpdateCheck\Change\ChangeParser;
use Plan2net\Typo3UpdateCheck\Http\ComposerHttpClient;
use Plan2net\Typo3UpdateCheck\Release\ApiFailureClassifier;
use Plan2net\Typo3UpdateCheck\Release\ReleaseProvider;

final class ReleaseProviderFactory
{
    public static function create(HttpDownloader $httpDownloader, ?string $cacheDir = null): ReleaseProvider
    {
        $httpClient = new ComposerHttpClient($httpDownloader, ['Accept: application/json']);

        $cache = $cacheDir !== null ? new CacheManager($cacheDir) : null;
        $changeParser = new ChangeParser(new ChangeFactory());
        $advisoryProvider = new PackagistAdvisoryProvider($httpClient, $cache);

        return new ReleaseProvider($httpClient, $changeParser, $cache, new ApiFailureClassifier(), $advisoryProvider);
    }
}
