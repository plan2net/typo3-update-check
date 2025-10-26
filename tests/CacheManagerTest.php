<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\Typo3UpdateCheck\Cache\CacheManager;

final class CacheManagerTest extends TestCase
{
    private string $testCacheDir;
    private CacheManager $cacheManager;

    protected function setUp(): void
    {
        $this->testCacheDir = sys_get_temp_dir() . '/typo3-update-check-test-' . uniqid();
        mkdir($this->testCacheDir, 0777, true);
        $this->cacheManager = new CacheManager($this->testCacheDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->testCacheDir);
    }

    #[Test]
    public function returnsNullWhenCacheDoesNotExist(): void
    {
        $result = $this->cacheManager->get('non-existent-key');

        $this->assertNull($result);
    }

    #[Test]
    public function storesAndRetrievesValidData(): void
    {
        $key = 'test-key';
        $data = ['version' => '12.4.20', 'date' => '2024-09-10'];

        $this->cacheManager->set($key, $data);
        $result = $this->cacheManager->get($key);

        $this->assertEquals($data, $result);
    }

    #[Test]
    public function returnsNullWhenReleaseCacheExpired(): void
    {
        $key = 'releases-v12';
        $data = ['test' => 'data'];

        $this->cacheManager->set($key, $data);

        $prefix = 'r_';
        $filePath = $this->testCacheDir . '/plan2net/typo3-update-check/' . $prefix . md5($key) . '.json';
        touch($filePath, time() - 3601);

        $result = $this->cacheManager->get($key);
        $this->assertNull($result);
    }

    #[Test]
    public function contentCacheNeverExpires(): void
    {
        $key = 'content-12.4.20';
        $data = ['permanent' => 'data'];

        $this->cacheManager->set($key, $data);

        $prefix = 'c_';
        $filePath = $this->testCacheDir . '/plan2net/typo3-update-check/' . $prefix . md5($key) . '.json';
        touch($filePath, time() - 86400);

        $result = $this->cacheManager->get($key);
        $this->assertEquals($data, $result);
    }

    #[Test]
    public function securityBulletinsNeverExpire(): void
    {
        $key = 'security-bulletin-typo3-core-sa-2025-001';
        $data = ['severity' => 'High'];

        $this->cacheManager->set($key, $data);

        $prefix = 'x_';
        $filePath = $this->testCacheDir . '/plan2net/typo3-update-check/' . $prefix . md5($key) . '.json';
        touch($filePath, time() - 86400);

        $result = $this->cacheManager->get($key);
        $this->assertEquals($data, $result);
    }

    #[Test]
    public function sanitizesKeysForSafeFilenames(): void
    {
        $unsafeKey = '../../../etc/passwd';
        $data = ['test' => 'data'];

        $this->cacheManager->set($unsafeKey, $data);
        $result = $this->cacheManager->get($unsafeKey);

        $this->assertEquals($data, $result);
        $this->assertFileDoesNotExist($this->testCacheDir . '/../../../etc/passwd.json');
    }

    #[Test]
    public function silentlyFailsWhenCacheDirectoryNotWritable(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('File permission test not applicable on Windows');
        }

        $readOnlyDir = $this->testCacheDir . '/readonly';
        mkdir($readOnlyDir, 0555, true);

        $cacheManager = new CacheManager($readOnlyDir);
        $cacheManager->set('test-key', ['data' => 'value']);

        $result = $cacheManager->get('test-key');
        $this->assertNull($result);

        chmod($readOnlyDir, 0755);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
