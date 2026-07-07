<?php

declare(strict_types=1);

namespace Typo3UpdateCheckWeb\Build;

/**
 * Curated corrections for upstream data bugs, loaded from data-overrides.json. Every entry cites
 * its official source. Two operations:
 *  - corrections: rewrite fields of the advisory matched by cve + core/optional bucket. A
 *    correction that matches nothing fails the build — stale overrides must be removed, not
 *    silently ignored.
 *  - additions: append a hand-mapped advisory the automatic pool cannot see (e.g. filed under a
 *    non-typo3/cms* package whose constraints are not TYPO3 version numbers). If the CVE later
 *    appears upstream in the same bucket, the build fails so the obsolete addition is removed.
 */
final class Overrides
{
    /**
     * @param list<array<string,mixed>> $advisories
     * @param array<string,mixed> $overrides
     * @return list<array<string,mixed>>
     */
    public static function apply(array $advisories, array $overrides): array
    {
        foreach (($overrides['corrections'] ?? []) as $correction) {
            $cve = (string) ($correction['match']['cve'] ?? '');
            $optional = (bool) ($correction['match']['optional'] ?? false);
            $matched = false;
            foreach ($advisories as &$advisory) {
                if ($advisory['cve'] === $cve && $advisory['optional'] === $optional) {
                    $advisory = array_replace($advisory, (array) ($correction['set'] ?? []));
                    $matched = true;
                }
            }
            unset($advisory);
            if (!$matched) {
                throw new \RuntimeException("Override correction for {$cve} matched no advisory — remove or update it.");
            }
        }

        foreach (($overrides['additions'] ?? []) as $addition) {
            $added = (array) ($addition['advisory'] ?? []);
            foreach ($advisories as $advisory) {
                if ($advisory['cve'] === ($added['cve'] ?? null) && $advisory['optional'] === ($added['optional'] ?? false)) {
                    throw new \RuntimeException(
                        "Override addition for {$added['cve']} duplicates an upstream advisory — remove the addition."
                    );
                }
            }
            $advisories[] = $added;
        }

        return $advisories;
    }
}
