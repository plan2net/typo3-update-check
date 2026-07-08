<?php

declare(strict_types=1);

namespace Typo3UpdateCheckWeb\Build\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Typo3UpdateCheckWeb\Build\AffectedResolver;

final class AffectedResolverTest extends TestCase
{
    /** @var list<array{version:string,elts:bool}> */
    private array $releases12 = [
        ['version' => '12.4.10', 'elts' => false],
        ['version' => '12.4.45', 'elts' => false],
        ['version' => '12.4.46', 'elts' => true],
        ['version' => '12.4.47', 'elts' => true],
    ];

    #[Test]
    public function exclusiveUpperBoundFixesAtTheBound(): void
    {
        $r = (new AffectedResolver())->resolve('>=12.0.0,<12.4.46', $this->releases12);
        $this->assertSame('12.4.10', $r['from']);
        $this->assertSame('12.4.46', $r['fixedIn']);
        $this->assertTrue($r['fixedInElts']);
    }

    #[Test]
    public function inclusiveUpperBoundFixesAtTheNextRelease(): void
    {
        $r = (new AffectedResolver())->resolve('>=12.0.0,<=12.4.10', $this->releases12);
        $this->assertSame('12.4.10', $r['from']);
        $this->assertSame('12.4.45', $r['fixedIn']); // 12.4.10 is affected; next clean release is the fix
        $this->assertFalse($r['fixedInElts']);
    }

    #[Test]
    public function constraintThatDoesNotTouchTheMajorReturnsNull(): void
    {
        $this->assertNull((new AffectedResolver())->resolve('=13.0.0|>=11.0.0,<=11.5.34', $this->releases12));
    }

    #[Test]
    public function unfixedAdvisoryHasNullFixedIn(): void
    {
        $r = (new AffectedResolver())->resolve('>=12.0.0,<99.0.0', $this->releases12);
        $this->assertSame('12.4.10', $r['from']);
        $this->assertNull($r['fixedIn']);
    }

    #[Test]
    public function malformedConstraintIsSkipped(): void
    {
        // Mirror the plugin: a constraint Composer can't parse is skipped, not fatal.
        $this->assertNull((new AffectedResolver())->resolve('not a valid constraint', $this->releases12));
    }

    #[Test]
    public function nonContiguousAffectedRangeIsTreatedConservativelyAndWarnsViaTheSink(): void
    {
        // Matches 12.4.10 and 12.4.46+ but not 12.4.45 (a gap). Rather than throw or drop, the
        // whole span first..last-affected is treated as affected, so the gap version (12.4.45) is
        // over-reported as affected, never silently missed. The warning goes to the injected sink —
        // NOT to STDERR — so this test can't leak a scary-looking line into the CI log.
        $warnings = [];
        $resolver = new AffectedResolver(function (string $warning) use (&$warnings): void {
            $warnings[] = $warning;
        });

        $r = $resolver->resolve('<=12.4.10|>=12.4.46', $this->releases12);

        $this->assertSame('12.4.10', $r['from']);
        $this->assertNull($r['fixedIn']); // last affected is the newest release -> no fix above it
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('non-contiguous', $warnings[0]);
    }
}
