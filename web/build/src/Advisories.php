<?php

declare(strict_types=1);

namespace Typo3UpdateCheckWeb\Build;

use Composer\Semver\Comparator;
use Plan2net\Typo3UpdateCheck\Advisory\PackagistAdvisoryProvider;

final class Advisories
{
    private const ADVISORY_URL = 'https://packagist.org/api/security-advisories/';

    /**
     * Packages present in every TYPO3 install. Advisories filed under these are "core"
     * (counted in the headline); anything else is "optional" — may apply only if installed (§7).
     */
    private const ALWAYS_PRESENT = [
        'typo3/cms',
        'typo3/cms-core',
        'typo3/cms-backend',
        'typo3/cms-frontend',
        'typo3/cms-install',
        'typo3/cms-extbase',
        'typo3/cms-fluid',
    ];

    /** Highest first — used to take the max severity across duplicate records of one CVE. */
    private const SEVERITY_RANK = ['critical', 'high', 'medium', 'low'];

    public function __construct(
        private readonly Http $http,
        private readonly AffectedResolver $resolver,
    ) {}

    /**
     * @param array<string, array{releases:list<array{version:string,date:?string,type:string,elts:bool}>}> $majors
     * @return list<array<string,mixed>>
     */
    public function build(array $majors): array
    {
        // Reuse the plugin's public query builder (single source of truth, caught by static analysis).
        $response = $this->http->get(self::ADVISORY_URL . '?' . PackagistAdvisoryProvider::packagesQueryString());
        /** @var array<string, list<array<string,mixed>>> $pool */
        $pool = is_array($response['advisories'] ?? null) ? $response['advisories'] : [];

        // TYPO3's always-present core packages always carry advisories. A partial response that drops
        // the core package keys (but keeps an optional one) would otherwise pass and publish core
        // releases as a fresh "all clear" — so require advisories from at least one core package.
        $coreRecords = 0;
        foreach (self::ALWAYS_PRESENT as $package) {
            $coreRecords += isset($pool[$package]) && is_array($pool[$package]) ? count($pool[$package]) : 0;
        }
        if ($coreRecords === 0) {
            throw new \RuntimeException('Packagist returned no core (typo3/cms*) advisories — refusing to publish an "all clear" dataset.');
        }

        return $this->aggregate($pool, $majors);
    }

    /**
     * Pure, order-independent aggregation: the same pool in any record order yields identical output.
     *
     * @param array<string, list<array<string,mixed>>> $pool Packagist advisories keyed by package
     * @param array<string, array{releases:list<array{version:string,date:?string,type:string,elts:bool}>}> $majors
     * @return list<array<string,mixed>>
     */
    public function aggregate(array $pool, array $majors): array
    {
        // Pass 1 — group by dedup key (CVE-first), AGGREGATING across duplicate records.
        // The key is split by core-ness, so a CVE filed under both a core and an optional package
        // becomes two groups: unioning their ranges would let an optional package's later fix mask
        // the core fix (falsely reporting the core-fixed release as still vulnerable). Within a group
        // (all core, or all optional) the constraints ARE unioned — a site runs all its core packages,
        // so it stays vulnerable until the last of them is fixed. The displayed id/title/link/package
        // come from a canonical "primary" record (advisoryId asc, then package asc), so selection is
        // independent of pool order. Mirrors PackagistAdvisoryProvider.php:134.
        $groups = [];
        foreach ($pool as $list) {
            foreach ($list as $advisory) {
                $constraint = (string) ($advisory['affectedVersions'] ?? '');
                if ($constraint === '') {
                    continue;
                }
                $cve = is_string($advisory['cve'] ?? null) ? $advisory['cve'] : null;
                $id = (string) ($advisory['advisoryId'] ?? '');
                $package = (string) ($advisory['packageName'] ?? '');
                $isCore = in_array($package, self::ALWAYS_PRESENT, true);
                $base = $cve ?? ($id !== '' ? $id : '__keyless-' . count($groups));
                $key = $base . ($isCore ? '|core' : '|optional'); // never conflate core and optional ranges
                $rank = [$id, $package]; // smaller = preferred primary (core-ness is constant per group)

                if (!isset($groups[$key])) {
                    $groups[$key] = [
                        'rank' => $rank, 'id' => $id, 'cve' => $cve, 'package' => $package,
                        'title' => (string) ($advisory['title'] ?? ''), 'link' => (string) ($advisory['link'] ?? ''),
                        'severity' => (string) ($advisory['severity'] ?? 'unknown'),
                        'core' => $isCore, 'constraints' => [$constraint => true],
                    ];
                    continue;
                }

                $group = &$groups[$key];
                $group['constraints'][$constraint] = true; // set => de-dupes identical constraints
                $group['severity'] = $this->higherSeverity($group['severity'], (string) ($advisory['severity'] ?? 'unknown'));
                if ($rank < $group['rank']) { // element-wise tuple comparison -> deterministic primary
                    $group['rank'] = $rank;
                    $group['id'] = $id;
                    $group['package'] = $package;
                    $group['title'] = (string) ($advisory['title'] ?? '');
                    $group['link'] = (string) ($advisory['link'] ?? '');
                }
                unset($group);
            }
        }
        ksort($groups); // deterministic output order, independent of pool order

        // Pass 2 — resolve each group's UNION of SORTED constraints (Composer '|' = OR) per major.
        $advisories = [];
        foreach ($groups as $group) {
            $constraints = array_keys($group['constraints']);
            sort($constraints); // sort before join -> combined string is order-independent
            $combined = implode('|', $constraints);

            $affected = [];
            foreach ($majors as $majorKey => $major) {
                $releases = array_map(
                    static fn (array $release): array => ['version' => $release['version'], 'elts' => $release['elts']],
                    $major['releases'],
                );
                $entry = $this->resolver->resolve($combined, $releases); // null on malformed/no-match; conservative on gaps
                if ($entry !== null) {
                    $affected[$majorKey] = $entry;
                }
            }
            if ($affected === []) {
                continue; // does not touch any tracked major
            }

            $advisories[] = [
                'id' => $group['id'],
                'cve' => $group['cve'],
                'package' => $group['package'],
                'optional' => !$group['core'], // core and optional records are now separate groups
                'severity' => $group['severity'],
                'title' => $group['title'],
                'affectedVersions' => $combined,
                'link' => $group['link'],
                'affected' => $affected,
            ];
        }

        return $this->dropOptionalDuplicatesCoveredByCore($advisories);
    }

    /**
     * Pass 3 — drop an optional advisory when a core advisory for the SAME CVE already covers its
     * entire exposure (core affected no later, and fixed no earlier, in every major it touches). Core
     * packages are always installed, so such an optional entry is a redundant duplicate. An optional
     * entry that stays vulnerable LONGER than core, or touches a major core does not, is kept — that
     * is real residual exposure the free/core fix would not resolve.
     *
     * @param list<array<string,mixed>> $advisories
     * @return list<array<string,mixed>>
     */
    private function dropOptionalDuplicatesCoveredByCore(array $advisories): array
    {
        $coreIndexByCve = [];
        foreach ($advisories as $index => $advisory) {
            if ($advisory['optional'] === false && is_string($advisory['cve'])) {
                $coreIndexByCve[$advisory['cve']] = $index;
            }
        }

        $drop = [];
        foreach ($advisories as $index => $advisory) {
            if ($advisory['optional'] === false || !is_string($advisory['cve']) || !isset($coreIndexByCve[$advisory['cve']])) {
                continue; // core, keyless, or no matching core entry → keep
            }
            $coreAffected = $advisories[$coreIndexByCve[$advisory['cve']]]['affected'];
            $covered = true;
            foreach ($advisory['affected'] as $majorKey => $optional) {
                $coreEntry = $coreAffected[$majorKey] ?? null;
                if ($coreEntry === null || Comparator::greaterThan($coreEntry['from'], $optional['from'])) {
                    $covered = false; // optional adds exposure (a major core misses, or an earlier window)
                    break;
                }
                $coreFix = $coreEntry['fixedIn'];
                if ($coreFix === null) {
                    continue; // core never fixed → it dominates this major
                }
                if ($optional['fixedIn'] === null || Comparator::greaterThan($optional['fixedIn'], $coreFix)) {
                    $covered = false; // optional stays vulnerable past the core fix
                    break;
                }
            }
            if ($covered) {
                // Carry the dropped record's severity onto the surviving core entry, so a
                // "core low + optional critical" duplicate is never silently downgraded.
                $coreIndex = $coreIndexByCve[$advisory['cve']];
                $advisories[$coreIndex]['severity'] = $this->higherSeverity($advisories[$coreIndex]['severity'], $advisory['severity']);
                $drop[$index] = true;
            }
        }

        return array_values(array_diff_key($advisories, $drop));
    }

    private function higherSeverity(string $a, string $b): string
    {
        $ra = array_search($a, self::SEVERITY_RANK, true);
        $rb = array_search($b, self::SEVERITY_RANK, true);
        $ra = $ra === false ? PHP_INT_MAX : $ra;
        $rb = $rb === false ? PHP_INT_MAX : $rb;

        return $ra <= $rb ? $a : $b; // lower index = higher severity
    }
}
