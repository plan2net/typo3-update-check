<?php

declare(strict_types=1);

namespace Typo3UpdateCheckWeb\Build;

final class ExplanationCache
{
    /**
     * `$explain` returns the per-language map for one advisory, e.g. `['en' => {plainImpact,urgency}, 'de' => {...}]`,
     * or null when generation failed entirely (fail-soft).
     *
     * @param list<array<string,mixed>> $advisories
     * @param array<string, array{contentHash:string,promptVersion:int,langs:array<string,array{plainImpact:string,urgency:string}>}> $existing
     * @param list<string> $requiredLangs every language an entry must contain to count as a cache hit
     * @param callable(array<string,mixed>): (array<string,array{plainImpact:string,urgency:string}>|null) $explain
     * @return array{explanations: array<string, array{contentHash:string,promptVersion:int,langs:array<string,array{plainImpact:string,urgency:string}>}>, newlyExplained: int}
     */
    public function merge(array $advisories, array $existing, int $promptVersion, array $requiredLangs, callable $explain): array
    {
        $newlyExplained = 0;
        foreach ($advisories as $advisory) {
            $id = (string) $advisory['id'];
            $hash = self::contentHash($advisory);

            $cached = $existing[$id] ?? null;
            if (is_array($cached)
                && ($cached['contentHash'] ?? null) === $hash
                && ($cached['promptVersion'] ?? null) === $promptVersion
                && self::hasAllLangs($cached, $requiredLangs)) {
                continue; // fresh, same prompt, AND complete in every required language -> reuse
            }

            $langs = $explain($advisory);
            if ($langs !== null) {
                $existing[$id] = ['contentHash' => $hash, 'promptVersion' => $promptVersion, 'langs' => $langs];
                ++$newlyExplained;
            }
        }

        return ['explanations' => $existing, 'newlyExplained' => $newlyExplained];
    }

    /**
     * True only if the cache entry has a non-empty explanation for every required language.
     * Used both here (reuse gate) and by the orchestrator (publish gate), so a partial entry
     * is neither reused nor served.
     *
     * @param list<string> $requiredLangs
     */
    public static function hasAllLangs(mixed $entry, array $requiredLangs): bool
    {
        if (!is_array($entry) || !is_array($entry['langs'] ?? null)) {
            return false;
        }
        foreach ($requiredLangs as $lang) {
            if (!isset($entry['langs'][$lang])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Hash of exactly the fields fed to Claude — changes here invalidate the cached explanation.
     *
     * @param array<string,mixed> $advisory
     */
    public static function contentHash(array $advisory): string
    {
        return hash('sha256', implode('|', [
            (string) ($advisory['title'] ?? ''),
            (string) ($advisory['severity'] ?? ''),
            (string) ($advisory['package'] ?? ''),
            (string) ($advisory['affectedVersions'] ?? ''),
        ]));
    }
}
