<?php

declare(strict_types=1);

namespace Typo3UpdateCheckWeb\Build;

/**
 * Which library ranges the public TYPO3 packages require per release: a Composer install can only
 * pick library versions inside them. ELTS releases are not on Packagist.
 */
final class CoreConstraints
{
    private const METADATA_URL = 'https://repo.packagist.org/p2/%s.json';

    /** @param \Closure(string):?string $fetch */
    public function __construct(private readonly \Closure $fetch) {}

    /**
     * @param list<string> $packages
     * @return array<string, array<string, list<string>>> release version => library => constraints
     */
    public function forPackages(array $packages): array
    {
        $constraints = [];
        foreach ($packages as $package) {
            $metadata = json_decode((string) ($this->fetch)(sprintf(self::METADATA_URL, $package)), true);
            foreach ($this->expand($metadata['packages'][$package] ?? []) as $release) {
                $version = ltrim((string) ($release['version'] ?? ''), 'v');
                foreach (is_array($release['require'] ?? null) ? $release['require'] : [] as $library => $constraint) {
                    if (str_contains($library, '/') && !str_starts_with($library, 'typo3/')) {
                        $constraints[$version][$library][] = (string) $constraint;
                    }
                }
            }
        }
        uksort($constraints, 'version_compare');

        return $constraints;
    }

    /**
     * Packagist's "composer/2.0" minified format: each entry only lists the keys that changed since
     * the previous one, and "__unset" removes a key.
     *
     * @param list<array<string,mixed>> $releases
     * @return list<array<string,mixed>>
     */
    private function expand(array $releases): array
    {
        $expanded = [];
        $previous = [];
        foreach ($releases as $release) {
            $current = array_filter($release + $previous, static fn (mixed $value): bool => $value !== '__unset');
            $expanded[] = $current;
            $previous = $current;
        }

        return $expanded;
    }
}
