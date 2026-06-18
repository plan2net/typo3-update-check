<?php

declare(strict_types=1);

namespace Typo3UpdateCheckWeb\Build;

use Composer\Semver\Comparator;

final class Typo3Releases
{
    private const MAJORS_URL = 'https://get.typo3.org/api/v1/major/';
    private const RELEASES_URL = 'https://get.typo3.org/api/v1/major/%d/release/';
    private const MIN_MAJOR = 9; // composer-era TYPO3 only

    public function __construct(private readonly Http $http) {}

    /**
     * @return array<string, array{maintainedUntil:?string,eltsUntil:?string,latestFree:?string,latestElts:?string,releases:list<array{version:string,date:?string,type:string,elts:bool}>}>
     */
    public function build(): array
    {
        $out = [];
        foreach ($this->http->get(self::MAJORS_URL) as $major) {
            $version = (int) ($major['version'] ?? 0);
            if ($version < self::MIN_MAJOR) {
                continue;
            }

            $releases = [];
            foreach ($this->http->get(sprintf(self::RELEASES_URL, $version)) as $release) {
                $releases[] = [
                    'version' => (string) $release['version'],
                    'date' => $release['date'] ?? null,
                    'type' => (string) ($release['type'] ?? 'regular'),
                    'elts' => (bool) ($release['elts'] ?? false),
                ];
            }
            if ($releases === []) {
                continue;
            }

            usort(
                $releases,
                static fn (array $a, array $b): int => Comparator::greaterThan($a['version'], $b['version']) ? -1
                    : (Comparator::lessThan($a['version'], $b['version']) ? 1 : 0),
            ); // descending

            $free = array_values(array_filter($releases, static fn (array $r): bool => !$r['elts']));

            $out[(string) $version] = [
                'maintainedUntil' => $major['maintained_until'] ?? null,
                'eltsUntil' => $major['elts_until'] ?? null,
                'latestFree' => $free[0]['version'] ?? null,
                'latestElts' => $releases[0]['version'] ?? null,
                'releases' => $releases,
            ];
        }

        return $out;
    }
}
