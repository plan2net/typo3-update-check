<?php

declare(strict_types=1);

namespace Typo3UpdateCheckWeb\Build;

/**
 * The classic distribution's vendor/ is installed from the core repository's composer.lock at the
 * release tag. ELTS tags are not public, so only free releases can be read.
 */
final class BundledPackages
{
    private const LOCK_URL = 'https://raw.githubusercontent.com/TYPO3/typo3/v%s/composer.lock';

    /** @param \Closure(string):?string $fetch */
    public function __construct(
        private readonly \Closure $fetch,
        private readonly string $cachePath,
    ) {}

    /**
     * @param array<string, array{releases:list<array{version:string,date:?string,type:string,elts:bool}>}> $majors
     * @return array<string, array<string, string>> release version => package name => bundled version
     */
    public function forReleases(array $majors): array
    {
        $cached = is_file($this->cachePath)
            ? (json_decode((string) file_get_contents($this->cachePath), true) ?: [])
            : [];

        $packages = [];
        foreach ($majors as $major) {
            foreach ($major['releases'] as $release) {
                if ($release['elts']) {
                    continue;
                }
                $version = $release['version'];
                // Tags are immutable; a failed fetch is not cached, so it is retried next run.
                $packages[$version] = $cached[$version] ?? $this->fetchThirdPartyPackages($version);
                if ($packages[$version] === null) {
                    unset($packages[$version]);
                }
            }
        }

        ksort($packages, SORT_NATURAL);
        DataFile::write($this->cachePath, $packages);

        return $packages;
    }

    /** @return array<string, string>|null */
    private function fetchThirdPartyPackages(string $version): ?array
    {
        $lock = json_decode((string) ($this->fetch)(sprintf(self::LOCK_URL, $version)), true);
        if (!is_array($lock['packages'] ?? null)) {
            return null;
        }

        $packages = [];
        foreach ($lock['packages'] as $package) {
            $name = (string) ($package['name'] ?? '');
            if ($name === '' || str_starts_with($name, 'typo3/')) {
                continue;
            }
            $packages[$name] = ltrim((string) ($package['version'] ?? ''), 'v');
        }
        ksort($packages);

        return $packages;
    }
}
