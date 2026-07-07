<?php

declare(strict_types=1);

namespace Typo3UpdateCheckWeb\Build\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Typo3UpdateCheckWeb\Build\FreshnessStamp;

final class FreshnessStampTest extends TestCase
{
    #[Test]
    public function addsACheckedAtTimestampAndPreservesTheDataset(): void
    {
        $dataset = ['generatedAt' => '2026-01-01T00:00:00+00:00', 'majors' => ['12' => []], 'advisories' => [['id' => 'SA-1']]];

        $stamped = FreshnessStamp::stamp($dataset, new \DateTimeImmutable('2026-07-07T04:17:00+02:00'));

        $this->assertSame('2026-07-07T02:17:00+00:00', $stamped['checkedAt']); // normalised to UTC
        $this->assertSame($dataset['generatedAt'], $stamped['generatedAt']);
        $this->assertSame($dataset['majors'], $stamped['majors']);
        $this->assertSame($dataset['advisories'], $stamped['advisories']);
    }

    #[Test]
    public function rejectsADatasetMissingItsCorePayload(): void
    {
        $this->expectException(\RuntimeException::class);

        FreshnessStamp::stamp(['majors' => ['12' => []]], new \DateTimeImmutable()); // no advisories
    }
}
