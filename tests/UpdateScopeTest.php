<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\Typo3UpdateCheck\UpdateScope;

final class UpdateScopeTest extends TestCase
{
    #[Test]
    public function derivesMajorVersionsFromVersionStrings(): void
    {
        $scope = new UpdateScope('12.4.10', '13.4.5');

        $this->assertSame('12.4.10', $scope->fromVersion);
        $this->assertSame('13.4.5', $scope->toVersion);
        $this->assertSame(12, $scope->fromMajor);
        $this->assertSame(13, $scope->toMajor);
    }

    #[Test]
    public function detectsMajorBump(): void
    {
        $this->assertTrue((new UpdateScope('12.4.10', '13.4.5'))->isMajorBump());
        $this->assertFalse((new UpdateScope('12.4.10', '12.4.20'))->isMajorBump());
    }

    #[Test]
    public function countsMajorsCrossed(): void
    {
        $this->assertSame(0, (new UpdateScope('12.4.10', '12.4.20'))->majorsCrossed());
        $this->assertSame(1, (new UpdateScope('12.4.10', '13.4.5'))->majorsCrossed());
        $this->assertSame(2, (new UpdateScope('12.4.10', '14.0.0'))->majorsCrossed());
    }
}
