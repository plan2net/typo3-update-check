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
            $key = self::cacheKey($advisory);
            $hash = self::contentHash($advisory);

            if (self::isFresh($existing[$key] ?? null, $hash, $promptVersion, $requiredLangs)) {
                continue; // fresh under the current composite key -> reuse
            }

            // Migration: older caches were keyed by the advisory id alone. Re-home a legacy entry only
            // if it still matches THIS advisory's content + prompt + languages — so a split id can never
            // migrate the wrong package's text — then drop the legacy key so it is not left orphaned.
            $legacyKey = (string) ($advisory['id'] ?? '');
            if ($legacyKey !== $key && self::isFresh($existing[$legacyKey] ?? null, $hash, $promptVersion, $requiredLangs)) {
                $existing[$key] = $existing[$legacyKey];
                unset($existing[$legacyKey]);
                continue;
            }

            $langs = $explain($advisory);
            if ($langs !== null) {
                $existing[$key] = ['contentHash' => $hash, 'promptVersion' => $promptVersion, 'langs' => $langs];
                ++$newlyExplained;
            }
        }

        // Prune entries no current advisory owns (aged-out advisories, unmigratable legacy keys) —
        // they can never be served, and the file is committed back on every CI run. Trade-off: an
        // advisory that transiently disappears loses its cache and is re-explained when it returns.
        $currentKeys = [];
        foreach ($advisories as $advisory) {
            $currentKeys[self::cacheKey($advisory)] = true;
        }

        return ['explanations' => array_intersect_key($existing, $currentKeys), 'newlyExplained' => $newlyExplained];
    }

    /**
     * A cache entry is reusable only if it matches the advisory's current content hash + prompt version
     * and is complete in every required language. Used both here (reuse gate) and by the orchestrator
     * (publish gate) — the two gates must never drift apart.
     *
     * @param list<string> $requiredLangs
     */
    public static function isFresh(mixed $entry, string $hash, int $promptVersion, array $requiredLangs): bool
    {
        return is_array($entry)
            && ($entry['contentHash'] ?? null) === $hash
            && ($entry['promptVersion'] ?? null) === $promptVersion
            && self::hasAllLangs($entry, $requiredLangs);
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
     * Unique cache key per PUBLISHED advisory. The core/optional split (Advisories::aggregate) can emit
     * two advisories with the same id — one core, one optional — so the id alone is not unique; keying
     * by id would let one overwrite the other's explanation. Include the core/optional flag + package.
     *
     * @param array<string,mixed> $advisory
     */
    public static function cacheKey(array $advisory): string
    {
        return implode('|', [
            (string) ($advisory['id'] ?? ''),
            ($advisory['optional'] ?? false) ? 'optional' : 'core',
            (string) ($advisory['package'] ?? ''),
        ]);
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
