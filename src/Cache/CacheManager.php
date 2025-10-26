<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Cache;

final class CacheManager implements CacheInterface
{
    private readonly string $cacheDir;

    public function __construct(
        string $composerCacheDir
    ) {
        $this->cacheDir = $composerCacheDir . '/plan2net/typo3-update-check';
        @mkdir($this->cacheDir, 0755, true);
    }

    /**
     * @return array<mixed>|null
     */
    public function get(string $key): ?array
    {
        $filePath = $this->getFilePath($key);

        if (!file_exists($filePath)) {
            return null;
        }

        $ttl = $this->getTtlForKey($key);
        if ($ttl > 0 && (time() - filemtime($filePath)) > $ttl) {
            @unlink($filePath);

            return null;
        }

        $content = @file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        try {
            return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            @unlink($filePath);

            return null;
        }
    }

    /**
     * @param array<mixed> $data
     *
     * @throws \JsonException
     */
    public function set(string $key, array $data): void
    {
        if (!is_dir($this->cacheDir) || !is_writable($this->cacheDir)) {
            return;
        }

        $filePath = $this->getFilePath($key);
        $tempPath = $filePath . '.' . uniqid('', true) . '.tmp';

        $json = json_encode($data, JSON_THROW_ON_ERROR);
        if (@file_put_contents($tempPath, $json, LOCK_EX) !== false) {
            @rename($tempPath, $filePath);
        } else {
            @unlink($tempPath);
        }
    }

    private function getFilePath(string $key): string
    {
        $prefix = match (true) {
            str_starts_with($key, 'content-') => 'c_',
            str_starts_with($key, 'releases-') => 'r_',
            default => 'x_',
        };

        return $this->cacheDir . '/' . $prefix . md5($key) . '.json';
    }

    private function getTtlForKey(string $key): int
    {
        // Release content and security advisories never change - cache forever
        if (str_starts_with($key, 'content-') || str_starts_with($key, 'security-bulletin-')) {
            return 0;
        }

        // Default TTL
        return 3600;
    }
}
