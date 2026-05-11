<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Plan2net\Typo3UpdateCheck\Cache\CacheManager;
use Plan2net\Typo3UpdateCheck\Change\ChangeFactory;
use Plan2net\Typo3UpdateCheck\Change\ChangeParser;
use Plan2net\Typo3UpdateCheck\Release\ApiFailureClassifier;
use Plan2net\Typo3UpdateCheck\Release\ReleaseProvider;
use Plan2net\Typo3UpdateCheck\Release\RetryPolicy;
use Plan2net\Typo3UpdateCheck\Security\SecurityBulletinFetcher;

final class ReleaseProviderFactory
{
    public static function create(?string $cacheDir = null): ReleaseProvider
    {
        $stack = HandlerStack::create();
        $stack->push(Middleware::retry(RetryPolicy::decider(), RetryPolicy::defaultDelay()), 'retry');

        $httpClient = new Client([
            'handler' => $stack,
            'timeout' => 10,
            'headers' => ['Accept' => 'application/json'],
        ]);

        $cache = $cacheDir !== null ? new CacheManager($cacheDir) : null;
        $bulletinFetcher = new SecurityBulletinFetcher($httpClient, $cache);
        $changeParser = new ChangeParser(new ChangeFactory(), $bulletinFetcher);

        return new ReleaseProvider($httpClient, $changeParser, $cache, new ApiFailureClassifier());
    }
}
