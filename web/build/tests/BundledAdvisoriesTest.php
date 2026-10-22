<?php

declare(strict_types=1);

namespace Typo3UpdateCheckWeb\Build\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Typo3UpdateCheckWeb\Build\AffectedResolver;
use Typo3UpdateCheckWeb\Build\BundledAdvisories;
use Typo3UpdateCheckWeb\Build\Http;

final class BundledAdvisoriesTest extends TestCase
{
    /** @return array<string, array{releases:list<array{version:string,date:?string,type:string,elts:bool}>}> */
    private function majors(): array
    {
        return ['13' => ['releases' => [
            ['version' => '13.4.37', 'date' => null, 'type' => 'regular', 'elts' => false],
            ['version' => '13.4.36', 'date' => null, 'type' => 'regular', 'elts' => false],
            ['version' => '13.4.35', 'date' => null, 'type' => 'security', 'elts' => false],
        ]]];
    }

    /** @return array<string, array<string, string>> */
    private function bundled(): array
    {
        return [
            '13.4.35' => ['symfony/expression-language' => '7.4.18'],
            '13.4.36' => ['symfony/expression-language' => '7.4.18'],
            '13.4.37' => ['symfony/expression-language' => '7.4.21'],
        ];
    }

    /** @return array<string, mixed> */
    private function record(string $package, string $affectedVersions, ?string $severity = 'medium', string $id = 'PKSA-1'): array
    {
        return [
            'advisoryId' => $id, 'cve' => 'CVE-2026-109449', 'packageName' => $package, 'severity' => $severity,
            'title' => 'Unbounded nesting', 'link' => 'https://symfony.com/cve', 'affectedVersions' => $affectedVersions,
        ];
    }

    private function advisories(): BundledAdvisories
    {
        return new BundledAdvisories(new Http(), new AffectedResolver());
    }

    #[Test]
    public function theFixIsTheFirstReleaseThatBundlesAPatchedLibraryVersion(): void
    {
        $pool = ['symfony/expression-language' => [$this->record('symfony/expression-language', '>=7.4.0,<7.4.21')]];

        $result = $this->advisories()->aggregate($pool, $this->majors(), $this->bundled(), []);

        $this->assertSame([[
            'id' => 'PKSA-1',
            'cve' => 'CVE-2026-109449',
            'package' => 'symfony/expression-language',
            'optional' => false,
            'severity' => 'medium',
            'title' => 'Unbounded nesting',
            'affectedVersions' => '>=7.4.0,<7.4.21',
            'link' => 'https://symfony.com/cve',
            'affected' => ['13' => ['from' => '13.4.35', 'fixedIn' => '13.4.37', 'fixedInElts' => false, 'enforcedIn' => null]],
            'explanation' => null,
        ]], $result);
    }

    #[Test]
    public function aReleaseWhoseCoreRangeExcludesEveryAffectedVersionEnforcesTheFixForComposerInstalls(): void
    {
        $pool = ['symfony/expression-language' => [$this->record('symfony/expression-language', '>=7.4.0,<7.4.21')]];
        $constraints = [
            '13.4.35' => ['symfony/expression-language' => ['^7.4']],
            '13.4.36' => ['symfony/expression-language' => ['^7.4', '^7.4.21']],
            '13.4.37' => ['symfony/expression-language' => ['^7.4.21']],
        ];

        $result = $this->advisories()->aggregate($pool, $this->majors(), $this->bundled(), $constraints);

        $this->assertSame('13.4.36', $result[0]['affected']['13']['enforcedIn']);
    }

    #[Test]
    public function aLibraryNoCorePackageRequiresDirectlyIsNeverReportedAsEnforced(): void
    {
        $pool = ['symfony/expression-language' => [$this->record('symfony/expression-language', '>=7.4.0,<7.4.21')]];
        $constraints = ['13.4.37' => ['symfony/yaml' => ['^7.4.21']]];

        $result = $this->advisories()->aggregate($pool, $this->majors(), $this->bundled(), $constraints);

        $this->assertNull($result[0]['affected']['13']['enforcedIn']);
    }

    #[Test]
    public function aReleaseWithoutKnownBundledPackagesIsNeverReportedAsTheFix(): void
    {
        $pool = ['symfony/expression-language' => [$this->record('symfony/expression-language', '>=7.4.0,<7.4.21')]];
        $bundled = $this->bundled();
        unset($bundled['13.4.37']);

        $result = $this->advisories()->aggregate($pool, $this->majors(), $bundled, []);

        $this->assertNull($result[0]['affected']['13']['fixedIn']);
    }

    #[Test]
    public function anAdvisoryNoReleaseBundlesAnAffectedVersionOfIsDropped(): void
    {
        $pool = ['symfony/expression-language' => [$this->record('symfony/expression-language', '<7.0.0')]];

        $this->assertSame([], $this->advisories()->aggregate($pool, $this->majors(), $this->bundled(), []));
    }

    #[Test]
    public function recordsOfOneCveAreMergedWithTheHighestSeverity(): void
    {
        $pool = [
            'symfony/expression-language' => [$this->record('symfony/expression-language', '>=7.4.0,<7.4.21', null, 'PKSA-2')],
            'symfony/symfony' => [$this->record('symfony/symfony', '>=7.4.0,<7.4.21', 'high', 'PKSA-1')],
        ];

        $result = $this->advisories()->aggregate($pool, $this->majors(), $this->bundled(), []);

        $this->assertCount(1, $result);
        $this->assertSame('symfony/expression-language', $result[0]['package']);
        $this->assertSame('high', $result[0]['severity']);
    }
}
