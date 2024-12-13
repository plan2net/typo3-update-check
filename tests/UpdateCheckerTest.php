<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Tests;

use Composer\Package\PackageInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\Typo3UpdateCheck\UpdateChecker;
use Plan2net\Typo3UpdateCheck\VersionParser;

final class UpdateCheckerTest extends TestCase
{
    private UpdateChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new UpdateChecker(new VersionParser());
    }

    #[Test]
    public function findsHighestTargetVersionFromPackages(): void
    {
        $package1 = $this->createMock(PackageInterface::class);
        $package1->method('getName')->willReturn('typo3/cms-core');
        $package1->method('getPrettyVersion')->willReturn('12.4.1');

        $package2 = $this->createMock(PackageInterface::class);
        $package2->method('getName')->willReturn('typo3/cms-core');
        $package2->method('getPrettyVersion')->willReturn('12.4.3');

        $package3 = $this->createMock(PackageInterface::class);
        $package3->method('getName')->willReturn('other/package');
        $package3->method('getPrettyVersion')->willReturn('1.0.0');

        $target = $this->checker->findTargetVersion(
            [$package1, $package2, $package3],
            '12.4.0'
        );

        $this->assertSame('12.4.3', $target);
    }

    #[Test]
    public function returnsNullWhenNoUpgradeAvailable(): void
    {
        $package1 = $this->createMock(PackageInterface::class);
        $package1->method('getName')->willReturn('typo3/cms-core');
        $package1->method('getPrettyVersion')->willReturn('12.4.0');

        $package2 = $this->createMock(PackageInterface::class);
        $package2->method('getName')->willReturn('typo3/cms-core');
        $package2->method('getPrettyVersion')->willReturn('12.3.9');

        // Current version is 12.4.0, packages only have 12.4.0 or lower
        $target = $this->checker->findTargetVersion([$package1, $package2], '12.4.0');

        $this->assertNull($target);
    }

    #[Test]
    public function filtersVersionsWithinRange(): void
    {
        $versions = ['12.4.0', '12.4.1', '12.4.2', '12.4.3', '12.4.4', '12.4.5'];

        $filtered = $this->checker->filterVersionsBetween($versions, '12.4.1', '12.4.4');

        $this->assertSame(['12.4.2', '12.4.3', '12.4.4'], $filtered);
    }
}
