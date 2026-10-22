<?php

declare(strict_types=1);

namespace Typo3UpdateCheckWeb\Build;

use Composer\Semver\Comparator;
use Composer\Semver\Semver;

final class AffectedResolver
{
    /**
     * @param \Closure(string):void|null $warningSink receives data-quality warnings; STDERR by default.
     *        Tests inject a collector so exercising the warning paths cannot leak alarming lines
     *        into the CI log — a warning there always means REAL data.
     */
    public function __construct(private readonly ?\Closure $warningSink = null) {}

    /**
     * @param list<array{version:string,elts:bool}> $releasesAsc releases of one major (any order)
     * @return array{from:string,fixedIn:string|null,fixedInElts:bool}|null
     *         null if the constraint does not match any release in this major OR is malformed (mirrors the plugin's skip).
     *         For a non-contiguous match it returns a single CONSERVATIVE span (first..after-last affected) and logs —
     *         it may over-report gap versions (the safe direction for security) but never under-reports.
     */
    public function resolve(string $affectedVersions, array $releasesAsc): ?array
    {
        try {
            return $this->resolveBy(
                static fn (string $version): bool => Semver::satisfies($version, $affectedVersions),
                $releasesAsc,
                $affectedVersions,
            );
        } catch (\UnexpectedValueException) {
            return null; // malformed constraint: skip, do not abort the build (mirrors PackagistAdvisoryProvider)
        }
    }

    /**
     * @param \Closure(string):bool $isAffected receives a release version
     * @param list<array{version:string,elts:bool}> $releasesAsc releases of one major (any order)
     * @param string $label names the advisory in data-quality warnings
     * @return array{from:string,fixedIn:string|null,fixedInElts:bool}|null
     */
    public function resolveBy(\Closure $isAffected, array $releasesAsc, string $label): ?array
    {
        usort(
            $releasesAsc,
            static fn (array $a, array $b): int => Comparator::lessThan($a['version'], $b['version']) ? -1
                : (Comparator::greaterThan($a['version'], $b['version']) ? 1 : 0),
        );

        $matched = array_map(static fn (array $r): bool => $isAffected($r['version']), $releasesAsc);

        /** @var list<int> $indices */
        $indices = array_keys(array_filter($matched, static fn (bool $m): bool => $m));
        if ($indices === []) {
            return null;
        }

        $min = $indices[0];
        $max = $indices[count($indices) - 1];
        if (count($indices) !== ($max - $min + 1)) {
            // Non-contiguous match. Throwing aborts the daily build; skipping is a silent security
            // false negative. Instead, conservatively treat the whole span first..last-affected as
            // affected — this may over-report the gap versions (the safe direction) but never
            // under-reports — and log it for review.
            $warn = $this->warningSink ?? static function (string $warning): void {
                fwrite(STDERR, $warning);
            };
            $warn("warning: non-contiguous affected range, treating the span conservatively as affected: {$label}\n");
        }

        $fix = $releasesAsc[$max + 1] ?? null; // first release after the LAST affected one
        return [
            'from' => $releasesAsc[$min]['version'],
            'fixedIn' => $fix['version'] ?? null,
            'fixedInElts' => $fix['elts'] ?? false,
        ];
    }
}
