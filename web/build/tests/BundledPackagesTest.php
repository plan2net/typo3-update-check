<?php

declare(strict_types=1);

namespace Typo3UpdateCheckWeb\Build\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Typo3UpdateCheckWeb\Build\BundledPackages;

final class BundledPackagesTest extends TestCase
{
    private string $cachePath;

    protected function setUp(): void
    {
        $this->cachePath = sys_get_temp_dir() . '/bundled-packages-' . bin2hex(random_bytes(4)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->cachePath);
    }

    /** @return array<string, array{releases:list<array{version:string,date:?string,type:string,elts:bool}>}> */
    private function majors(): array
    {
        return ['12' => ['releases' => [
            ['version' => '12.4.45', 'date' => null, 'type' => 'regular', 'elts' => false],
            ['version' => '12.4.46', 'date' => null, 'type' => 'security', 'elts' => true],
        ]]];
    }

    private function lock(string $yamlVersion): string
    {
        return (string) json_encode(['packages' => [
            ['name' => 'symfony/yaml', 'version' => 'v' . $yamlVersion],
            ['name' => 'typo3/cms-core', 'version' => 'v12.4.45'],
        ]]);
    }

    #[Test]
    public function readsTheThirdPartyPackagesOfEachFreeReleaseFromItsTaggedLockFile(): void
    {
        $fetched = [];
        $fetch = function (string $url) use (&$fetched): ?string {
            $fetched[] = $url;

            return $this->lock('6.4.26');
        };

        $packages = (new BundledPackages($fetch, $this->cachePath))->forReleases($this->majors());

        $this->assertSame(['12.4.45' => ['symfony/yaml' => '6.4.26']], $packages);
        $this->assertSame(['https://raw.githubusercontent.com/TYPO3/typo3/v12.4.45/composer.lock'], $fetched);
    }

    #[Test]
    public function aCachedReleaseIsNotFetchedAgain(): void
    {
        (new BundledPackages(fn (string $url): ?string => $this->lock('6.4.26'), $this->cachePath))->forReleases($this->majors());

        $packages = (new BundledPackages(function (string $url): ?string {
            $this->fail("unexpected fetch of {$url}");
        }, $this->cachePath))->forReleases($this->majors());

        $this->assertSame(['12.4.45' => ['symfony/yaml' => '6.4.26']], $packages);
    }

    #[Test]
    public function aReleaseWithoutAReadableLockFileIsLeftOutAndRetriedNextRun(): void
    {
        $packages = (new BundledPackages(static fn (string $url): ?string => null, $this->cachePath))->forReleases($this->majors());
        $this->assertSame([], $packages);

        $packages = (new BundledPackages(fn (string $url): ?string => $this->lock('6.4.26'), $this->cachePath))->forReleases($this->majors());
        $this->assertSame(['12.4.45' => ['symfony/yaml' => '6.4.26']], $packages);
    }
}
