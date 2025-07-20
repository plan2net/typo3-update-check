<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Cache;

interface CacheInterface
{
    /**
     * @return array<mixed>|null
     */
    public function get(string $key): ?array;

    /**
     * @param array<mixed> $data
     */
    public function set(string $key, array $data): void;
}
