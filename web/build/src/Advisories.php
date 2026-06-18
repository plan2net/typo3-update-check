<?php

declare(strict_types=1);

namespace Typo3UpdateCheckWeb\Build;

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
        return $this->aggregate($response['advisories'] ?? [], $majors);
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
        // The same CVE can be filed under several packages with DIFFERENT constraints, so we union
        // the constraints and remember whether any package is core. The displayed id/title/link/package
        // come from a canonical "primary" record (core first, then advisoryId asc, then package asc) —
        // so selection is independent of pool order. Mirrors PackagistAdvisoryProvider.php:134.
        $groups = [];
        foreach ($pool as $list) {
            foreach ($list as $advisory) {
                $constraint = (string) ($advisory['affectedVersions'] ?? '');
                if ($constraint === '') {
                    continue;
                }
                $cve = is_string($advisory['cve'] ?? null) ? $advisory['cve'] : null;
                $id = (string) ($advisory['advisoryId'] ?? '');
                $key = $cve ?? ($id !== '' ? $id : '__keyless-' . count($groups));
                $package = (string) ($advisory['packageName'] ?? '');
                $isCore = in_array($package, self::ALWAYS_PRESENT, true);
                $rank = [$isCore ? 0 : 1, $id, $package]; // smaller = preferred primary

                if (!isset($groups[$key])) {
                    $groups[$key] = [
                        'rank' => $rank, 'id' => $id, 'cve' => $cve, 'package' => $package,
                        'title' => (string) ($advisory['title'] ?? ''), 'link' => (string) ($advisory['link'] ?? ''),
                        'severity' => (string) ($advisory['severity'] ?? 'unknown'),
                        'anyCore' => $isCore, 'constraints' => [$constraint => true],
                    ];
                    continue;
                }

                $g = &$groups[$key];
                $g['constraints'][$constraint] = true; // set => de-dupes identical constraints
                $g['severity'] = $this->higherSeverity($g['severity'], (string) ($advisory['severity'] ?? 'unknown'));
                $g['anyCore'] = $g['anyCore'] || $isCore;
                if ($rank < $g['rank']) { // element-wise tuple comparison -> deterministic primary
                    $g['rank'] = $rank;
                    $g['id'] = $id;
                    $g['package'] = $package;
                    $g['title'] = (string) ($advisory['title'] ?? '');
                    $g['link'] = (string) ($advisory['link'] ?? '');
                }
                unset($g);
            }
        }

        // Pass 2 — resolve each group's UNION of SORTED constraints (Composer '|' = OR) per major.
        $advisories = [];
        foreach ($groups as $g) {
            $constraints = array_keys($g['constraints']);
            sort($constraints); // sort before join -> combined string is order-independent
            $combined = implode('|', $constraints);

            $affected = [];
            foreach ($majors as $majorKey => $major) {
                $releases = array_map(
                    static fn (array $r): array => ['version' => $r['version'], 'elts' => $r['elts']],
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
                'id' => $g['id'],
                'cve' => $g['cve'],
                'package' => $g['package'],
                'optional' => !$g['anyCore'], // core if ANY package it's filed under is core
                'severity' => $g['severity'],
                'title' => $g['title'],
                'affectedVersions' => $combined,
                'link' => $g['link'],
                'affected' => $affected,
            ];
        }

        return $advisories;
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
