<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Security;

interface SecurityBulletinFetcherInterface
{
    /**
     * @param string[] $bulletinUrls
     *
     * @return array<string, int>
     */
    public function fetchSeverities(array $bulletinUrls): array;
}
