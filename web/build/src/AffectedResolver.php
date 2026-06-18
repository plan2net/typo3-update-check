<?php

declare(strict_types=1);

namespace Typo3UpdateCheckWeb\Build;

use Composer\Semver\Comparator;
use Composer\Semver\Semver;

final class AffectedResolver
{
    /**
     * @param list<array{version:string,elts:bool}> $releasesAsc releases of one major (any order)
     * @return array{from:string,fixedIn:string|null,fixedInElts:bool}|null
     *         null if the constraint does not match any release in this major OR is malformed (mirrors the plugin's skip).
     *         For a non-contiguous match it returns a single CONSERVATIVE span (first..after-last affected) and logs —
     *         it may over-report gap versions (the safe direction for security) but never under-reports.
     */
    public function resolve(string $affectedVersions, array $releasesAsc): ?array
    {
        usort(
            $releasesAsc,
            static fn (array $a, array $b): int => Comparator::lessThan($a['version'], $b['version']) ? -1
                : (Comparator::greaterThan($a['version'], $b['version']) ? 1 : 0),
        );

        try {
            $matched = array_map(
                static fn (array $r): bool => Semver::satisfies($r['version'], $affectedVersions),
                $releasesAsc,
            );
        } catch (\UnexpectedValueException) {
            return null; // malformed constraint: skip, do not abort the build (mirrors PackagistAdvisoryProvider)
        }

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
            fwrite(STDERR, "warning: non-contiguous affected range, treating the span conservatively as affected: {$affectedVersions}\n");
        }

        $fix = $releasesAsc[$max + 1] ?? null; // first release after the LAST affected one
        return [
            'from' => $releasesAsc[$min]['version'],
            'fixedIn' => $fix['version'] ?? null,
            'fixedInElts' => $fix['elts'] ?? false,
        ];
    }
}
