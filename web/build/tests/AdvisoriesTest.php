<?php

declare(strict_types=1);

namespace Typo3UpdateCheckWeb\Build\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Typo3UpdateCheckWeb\Build\Advisories;
use Typo3UpdateCheckWeb\Build\AffectedResolver;
use Typo3UpdateCheckWeb\Build\Http;

final class AdvisoriesTest extends TestCase
{
    /** @return array<string, array{releases:list<array{version:string,date:?string,type:string,elts:bool}>}> */
    private function majors(): array
    {
        return ['12' => ['releases' => [
            ['version' => '12.4.10', 'date' => null, 'type' => 'regular', 'elts' => false],
            ['version' => '12.4.45', 'date' => null, 'type' => 'regular', 'elts' => false],
            ['version' => '12.4.46', 'date' => null, 'type' => 'security', 'elts' => true],
        ]]];
    }

    /** Same CVE under cms-core and cms-form, different constraints; $coreFirst flips pool order. */
    private function pool(bool $coreFirst): array
    {
        $core = ['advisoryId' => 'SA-2', 'cve' => 'CVE-X', 'packageName' => 'typo3/cms-core', 'severity' => 'high', 'title' => 'Core', 'link' => 'https://core', 'affectedVersions' => '>=12.0.0,<12.4.45'];
        $form = ['advisoryId' => 'SA-1', 'cve' => 'CVE-X', 'packageName' => 'typo3/cms-form', 'severity' => 'critical', 'title' => 'Form', 'link' => 'https://form', 'affectedVersions' => '>=12.0.0,<12.4.46'];

        return $coreFirst
            ? ['typo3/cms-core' => [$core], 'typo3/cms-form' => [$form]]
            : ['typo3/cms-form' => [$form], 'typo3/cms-core' => [$core]];
    }

    #[Test]
    public function aggregationIsIndependentOfRecordOrder(): void
    {
        $advisories = new Advisories(new Http(), new AffectedResolver());
        $this->assertEquals(
            $advisories->aggregate($this->pool(true), $this->majors()),
            $advisories->aggregate($this->pool(false), $this->majors()),
        );
    }

    #[Test]
    public function aCveAffectingCoreAndOptionalIsSplitSoTheCoreFixIsNotMaskedByTheOptionalOne(): void
    {
        $advisories = new Advisories(new Http(), new AffectedResolver());
        $result = $advisories->aggregate($this->pool(false), $this->majors());

        // Core (fixed .45) and optional (fixed .46) ranges must NOT be unioned — that would falsely
        // report .45 as still core-vulnerable. They are aggregated separately instead.
        $this->assertCount(2, $result);

        $byPackage = [];
        foreach ($result as $r) {
            $byPackage[$r['package']] = $r;
        }

        $core = $byPackage['typo3/cms-core'];
        $this->assertFalse($core['optional']);
        $this->assertSame('high', $core['severity']);                  // core record's own severity
        $this->assertSame('12.4.45', $core['affected']['12']['fixedIn']); // core fix, not pushed to .46

        $form = $byPackage['typo3/cms-form'];
        $this->assertTrue($form['optional']);
        $this->assertSame('critical', $form['severity']);
        $this->assertSame('12.4.46', $form['affected']['12']['fixedIn']);

        $this->assertSame('CVE-X', $core['cve']); // same CVE, surfaced once per remediation path
        $this->assertSame('CVE-X', $form['cve']);
    }

    #[Test]
    public function aRedundantOptionalDuplicateOfACoreCveIsDropped(): void
    {
        // Same CVE, SAME range under a core and an optional package. The site always runs core
        // packages, so the optional entry adds no exposure (same fix) — it is a duplicate and dropped.
        $core = ['advisoryId' => 'SA-9', 'cve' => 'CVE-Y', 'packageName' => 'typo3/cms-core', 'severity' => 'high', 'title' => 'Core', 'link' => 'https://c', 'affectedVersions' => '>=12.0.0,<12.4.45'];
        $opt = ['advisoryId' => 'SA-8', 'cve' => 'CVE-Y', 'packageName' => 'typo3/cms-recycler', 'severity' => 'high', 'title' => 'Opt', 'link' => 'https://o', 'affectedVersions' => '>=12.0.0,<12.4.45'];

        $advisories = new Advisories(new Http(), new AffectedResolver());
        $result = $advisories->aggregate(['typo3/cms-core' => [$core], 'typo3/cms-recycler' => [$opt]], $this->majors());

        $this->assertCount(1, $result);
        $this->assertFalse($result[0]['optional']);
        $this->assertSame('typo3/cms-core', $result[0]['package']);
    }

    #[Test]
    public function droppingAnOptionalDuplicatePropagatesItsHigherSeverityToCore(): void
    {
        // Core LOW + optional CRITICAL, identical range → the optional is dropped as covered, but its
        // severity must carry over to the surviving core record (never silently downgrade to low).
        $core = ['advisoryId' => 'SA-C', 'cve' => 'CVE-Z', 'packageName' => 'typo3/cms-core', 'severity' => 'low', 'title' => 'C', 'link' => 'https://c', 'affectedVersions' => '>=12.0.0,<12.4.45'];
        $opt = ['advisoryId' => 'SA-O', 'cve' => 'CVE-Z', 'packageName' => 'typo3/cms-form', 'severity' => 'critical', 'title' => 'O', 'link' => 'https://o', 'affectedVersions' => '>=12.0.0,<12.4.45'];

        $advisories = new Advisories(new Http(), new AffectedResolver());
        $result = $advisories->aggregate(['typo3/cms-core' => [$core], 'typo3/cms-form' => [$opt]], $this->majors());

        $this->assertCount(1, $result);
        $this->assertFalse($result[0]['optional']);
        $this->assertSame('critical', $result[0]['severity']); // propagated from the dropped optional
    }
}
