<?php

declare(strict_types=1);

namespace Typo3UpdateCheckWeb\Build;

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;

final class BundledAdvisories
{
    private const ADVISORY_URL = 'https://packagist.org/api/security-advisories/';

    public function __construct(
        private readonly Http $http,
        private readonly AffectedResolver $resolver,
    ) {}

    /**
     * @param array<string, array{releases:list<array{version:string,date:?string,type:string,elts:bool}>}> $majors
     * @param array<string, array<string, string>> $bundled release version => package name => bundled version
     * @param array<string, array<string, list<string>>> $coreConstraints release version => library => constraints
     * @return list<array<string,mixed>>
     */
    public function build(array $majors, array $bundled, array $coreConstraints): array
    {
        $names = array_keys(array_merge(...array_values($bundled)));
        if ($names === []) {
            return [];
        }
        sort($names);
        $query = implode('&', array_map(static fn (string $name): string => 'packages[]=' . rawurlencode($name), $names));
        $response = $this->http->get(self::ADVISORY_URL . '?' . $query);

        $pool = is_array($response['advisories'] ?? null) ? $response['advisories'] : [];

        return $this->aggregate($pool, $majors, $bundled, $coreConstraints);
    }

    /**
     * @param array<string, list<array<string,mixed>>> $pool Packagist advisories keyed by package
     * @param array<string, array{releases:list<array{version:string,date:?string,type:string,elts:bool}>}> $majors
     * @param array<string, array<string, string>> $bundled release version => package name => bundled version
     * @param array<string, array<string, list<string>>> $coreConstraints release version => library => constraints
     * @return list<array<string,mixed>>
     */
    public function aggregate(array $pool, array $majors, array $bundled, array $coreConstraints): array
    {
        $groups = [];
        foreach ($pool as $list) {
            foreach ($list as $advisory) {
                $id = (string) ($advisory['advisoryId'] ?? '');
                $cve = is_string($advisory['cve'] ?? null) ? $advisory['cve'] : null;
                $key = $cve ?? $id;
                $package = (string) ($advisory['packageName'] ?? '');
                $constraint = (string) ($advisory['affectedVersions'] ?? '');
                $severity = (string) ($advisory['severity'] ?? 'unknown');
                if ($key === '' || $constraint === '') {
                    continue;
                }

                $group = $groups[$key] ?? ['severity' => $severity, 'records' => []];
                $group['severity'] = Advisories::higherSeverity($group['severity'], $severity);
                if ($this->isBundledAnywhere($package, $bundled)) {
                    $group['records'][] = [
                        'id' => $id, 'cve' => $cve, 'package' => $package, 'constraint' => $constraint,
                        'title' => (string) ($advisory['title'] ?? ''), 'link' => (string) ($advisory['link'] ?? ''),
                    ];
                }
                $groups[$key] = $group;
            }
        }
        ksort($groups);

        $advisories = [];
        foreach ($groups as $group) {
            if ($group['records'] === []) {
                continue;
            }
            usort($group['records'], static fn (array $a, array $b): int => [$a['id'], $a['package']] <=> [$b['id'], $b['package']]);
            $primary = $group['records'][0];

            $isAffected = static function (string $release) use ($group, $bundled): bool {
                foreach ($group['records'] as $record) {
                    $version = $bundled[$release][$record['package']] ?? null;
                    try {
                        if ($version !== null && Semver::satisfies($version, $record['constraint'])) {
                            return true;
                        }
                    } catch (\UnexpectedValueException) {
                        // an unparseable constraint or version (e.g. a dev branch) proves nothing
                    }
                }

                return false;
            };

            $composerMayInstallAffected = static function (string $release) use ($group, $coreConstraints): bool {
                foreach ($group['records'] as $record) {
                    $required = $coreConstraints[$release][$record['package']] ?? [];
                    // Not required by core directly: a transitive range is not checked, so nothing is enforced.
                    if ($required === [] || self::allIntersect($required, $record['constraint'])) {
                        return true;
                    }
                }

                return false;
            };

            $label = "{$primary['package']} {$primary['id']}";
            $affected = [];
            foreach ($majors as $majorKey => $major) {
                $entry = $this->resolver->resolveBy($isAffected, $this->releasesIn($major, $bundled), $label);
                if ($entry === null) {
                    continue;
                }
                $enforcement = $this->resolver->resolveBy($composerMayInstallAffected, $this->releasesIn($major, $coreConstraints), $label);
                $affected[$majorKey] = $entry + ['enforcedIn' => $enforcement['fixedIn'] ?? null];
            }
            if ($affected === []) {
                continue;
            }

            $constraints = array_unique(array_column($group['records'], 'constraint'));
            sort($constraints);
            $advisories[] = [
                'id' => $primary['id'],
                'cve' => $primary['cve'],
                'package' => $primary['package'],
                'optional' => false,
                'severity' => $group['severity'],
                'title' => $primary['title'],
                'affectedVersions' => implode('|', $constraints),
                'link' => $primary['link'],
                'affected' => $affected,
                'explanation' => null,
            ];
        }

        return $advisories;
    }

    /**
     * @param array{releases:list<array{version:string,date:?string,type:string,elts:bool}>} $major
     * @param array<string, mixed> $knownByRelease
     * @return list<array{version:string,elts:bool}>
     */
    private function releasesIn(array $major, array $knownByRelease): array
    {
        $releases = [];
        foreach ($major['releases'] as $release) {
            if (isset($knownByRelease[$release['version']])) {
                $releases[] = ['version' => $release['version'], 'elts' => $release['elts']];
            }
        }

        return $releases;
    }

    /** @param list<string> $required */
    private static function allIntersect(array $required, string $affectedVersions): bool
    {
        $parser = new VersionParser();
        try {
            $affected = $parser->parseConstraints($affectedVersions);
            foreach ($required as $constraint) {
                if (!$parser->parseConstraints($constraint)->matches($affected)) {
                    return false;
                }
            }
        } catch (\UnexpectedValueException) {
            return true;
        }

        return true;
    }

    /** @param array<string, array<string, string>> $bundled */
    private function isBundledAnywhere(string $package, array $bundled): bool
    {
        foreach ($bundled as $packages) {
            if (isset($packages[$package])) {
                return true;
            }
        }

        return false;
    }
}
