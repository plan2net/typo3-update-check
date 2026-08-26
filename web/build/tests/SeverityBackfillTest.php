<?php

declare(strict_types=1);

namespace Typo3UpdateCheckWeb\Build\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Typo3UpdateCheckWeb\Build\SeverityBackfill;

final class SeverityBackfillTest extends TestCase
{
    /** @return array<string,mixed> */
    private function advisory(string $severity, string $link): array
    {
        return [
            'id' => 'SA-1', 'cve' => 'CVE-1', 'package' => 'typo3/cms-core', 'optional' => false,
            'severity' => $severity, 'title' => 'T', 'affectedVersions' => '>=14.0.0,<14.3.6', 'link' => $link,
            'affected' => ['14' => ['from' => '14.0.0', 'fixedIn' => '14.3.6', 'fixedInElts' => false]],
        ];
    }

    /** The shape the bulletin actually ships (verified against typo3.org bulletins back to 2020). */
    private function bulletin(string $severity): string
    {
        return '<ul><li><strong>Affected Versions:</strong><span> 14.0.0-14.3.5</span></li>'
            . "<li><strong>Severity:</strong><span> {$severity}</span></li>"
            . '<li><strong>Suggested CVSS:</strong><span> CVSS:4.0/AV:N/AC:L</span></li></ul>';
    }

    /** @param callable(string):?string $fetch */
    private function backfill(callable $fetch): SeverityBackfill
    {
        return new SeverityBackfill($fetch(...));
    }

    #[Test]
    public function anUnratedAdvisoryTakesTheSeverityFromItsOfficialBulletin(): void
    {
        $backfill = $this->backfill(fn (string $url): string => $this->bulletin('High'));

        $result = $backfill->apply([
            $this->advisory('unknown', 'https://news.typo3.com/security/advisory/typo3-core-sa-2026-021'),
        ]);

        $this->assertSame('high', $result[0]['severity']);
    }

    #[Test]
    public function aRatedAdvisoryIsNeverFetched(): void
    {
        $backfill = $this->backfill(function (string $url): string {
            $this->fail('an already-rated advisory must not cost an HTTP request');
        });

        $result = $backfill->apply([
            $this->advisory('medium', 'https://news.typo3.com/security/advisory/typo3-core-sa-2026-020'),
        ]);

        $this->assertSame('medium', $result[0]['severity']);
    }

    #[Test]
    public function aLinkOutsideTheOfficialBulletinHostsIsNeverFetched(): void
    {
        $backfill = $this->backfill(function (string $url): string {
            $this->fail('only official TYPO3 bulletins may be parsed for a severity');
        });

        $result = $backfill->apply([
            $this->advisory('unknown', 'https://github.com/advisories/GHSA-6c46-p6j5-3f49'),
        ]);

        $this->assertSame('unknown', $result[0]['severity']);
    }

    #[Test]
    public function aFailedFetchLeavesTheAdvisoryUnrated(): void
    {
        $backfill = $this->backfill(fn (string $url): ?string => null);

        $result = $backfill->apply([
            $this->advisory('unknown', 'https://typo3.org/security/advisory/typo3-core-sa-2026-021'),
        ]);

        $this->assertSame('unknown', $result[0]['severity']);
    }

    #[Test]
    public function aBulletinWithoutASeverityLeavesTheAdvisoryUnrated(): void
    {
        $backfill = $this->backfill(fn (string $url): string => '<p>Problem Description</p>');

        $result = $backfill->apply([
            $this->advisory('unknown', 'https://typo3.org/security/advisory/typo3-core-sa-2026-021'),
        ]);

        $this->assertSame('unknown', $result[0]['severity']);
    }

    #[Test]
    public function anUnexpectedSeverityWordIsRejectedRatherThanPublished(): void
    {
        $backfill = $this->backfill(fn (string $url): string => $this->bulletin('Catastrophic'));

        $result = $backfill->apply([
            $this->advisory('unknown', 'https://typo3.org/security/advisory/typo3-core-sa-2026-021'),
        ]);

        $this->assertSame('unknown', $result[0]['severity']);
    }

    #[Test]
    public function oneBulletinIsFetchedOnceEvenWhenSeveralAdvisoriesShareIt(): void
    {
        $fetches = 0;
        $backfill = $this->backfill(function (string $url) use (&$fetches): string {
            ++$fetches;

            return $this->bulletin('Medium');
        });

        $link = 'https://typo3.org/security/advisory/typo3-core-sa-2026-020';
        $result = $backfill->apply([$this->advisory('unknown', $link), $this->advisory('unknown', $link)]);

        $this->assertSame(1, $fetches);
        $this->assertSame(['medium', 'medium'], [$result[0]['severity'], $result[1]['severity']]);
    }
}
