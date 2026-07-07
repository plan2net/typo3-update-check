<?php

declare(strict_types=1);

namespace Typo3UpdateCheckWeb\Build\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Typo3UpdateCheckWeb\Build\Overrides;

final class OverridesTest extends TestCase
{
    /** @return array<string,mixed> */
    private function advisory(string $cve, bool $optional = false): array
    {
        return [
            'id' => 'SA-1', 'cve' => $cve, 'package' => 'typo3/cms-core', 'optional' => $optional,
            'severity' => 'low', 'title' => 'T', 'affectedVersions' => '>=12.0.0,<12.4.20', 'link' => 'https://t',
            'affected' => ['12' => ['from' => '12.0.0', 'fixedIn' => '12.4.20', 'fixedInElts' => false]],
        ];
    }

    #[Test]
    public function aCorrectionRewritesTheMatchedAdvisorysFields(): void
    {
        $overrides = ['corrections' => [[
            'match' => ['cve' => 'CVE-1', 'optional' => false],
            'set' => [
                'affectedVersions' => '>=12.0.0,<12.4.21',
                'affected' => ['12' => ['from' => '12.0.0', 'fixedIn' => '12.4.21', 'fixedInElts' => false]],
            ],
        ]]];

        $result = Overrides::apply([$this->advisory('CVE-1')], $overrides);

        $this->assertSame('12.4.21', $result[0]['affected']['12']['fixedIn']);
        $this->assertSame('>=12.0.0,<12.4.21', $result[0]['affectedVersions']);
        $this->assertSame('T', $result[0]['title']); // untouched fields survive
    }

    #[Test]
    public function aCorrectionMatchingNothingFailsTheBuild(): void
    {
        $overrides = ['corrections' => [[
            'match' => ['cve' => 'CVE-GONE', 'optional' => false],
            'set' => ['severity' => 'high'],
        ]]];

        $this->expectException(\RuntimeException::class);

        Overrides::apply([$this->advisory('CVE-1')], $overrides);
    }

    #[Test]
    public function aCorrectionDoesNotTouchTheOtherCoreOptionalBucket(): void
    {
        $overrides = ['corrections' => [[
            'match' => ['cve' => 'CVE-1', 'optional' => true],
            'set' => ['severity' => 'high'],
        ]]];

        $result = Overrides::apply([$this->advisory('CVE-1'), $this->advisory('CVE-1', true)], $overrides);

        $this->assertSame('low', $result[0]['severity']);  // core record untouched
        $this->assertSame('high', $result[1]['severity']); // optional record corrected
    }

    #[Test]
    public function anAdditionAppendsACompleteAdvisory(): void
    {
        $added = $this->advisory('CVE-NEW');
        $overrides = ['additions' => [['advisory' => $added]]];

        $result = Overrides::apply([$this->advisory('CVE-1')], $overrides);

        $this->assertCount(2, $result);
        $this->assertSame('CVE-NEW', $result[1]['cve']);
    }

    #[Test]
    public function anAdditionWhoseCveAlreadyExistsInTheSameBucketFailsTheBuild(): void
    {
        // If upstream later publishes the advisory we hand-added, the duplicate must surface
        // loudly so the obsolete addition is removed — never two records for one exposure.
        $overrides = ['additions' => [['advisory' => $this->advisory('CVE-1')]]];

        $this->expectException(\RuntimeException::class);

        Overrides::apply([$this->advisory('CVE-1')], $overrides);
    }
}
