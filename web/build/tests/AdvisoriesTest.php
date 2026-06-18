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
    public function aCveSharedWithCoreIsCoreWithMaxSeverityAndUnionedRange(): void
    {
        $advisories = new Advisories(new Http(), new AffectedResolver());
        $result = $advisories->aggregate($this->pool(false), $this->majors());

        $this->assertCount(1, $result);
        $this->assertFalse($result[0]['optional']);            // cms-core present -> core
        $this->assertSame('critical', $result[0]['severity']); // max(high, critical)
        $this->assertSame('typo3/cms-core', $result[0]['package']);
        $this->assertSame('12.4.46', $result[0]['affected']['12']['fixedIn']); // union -> later fix
    }
}
