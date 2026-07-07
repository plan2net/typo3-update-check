<?php

declare(strict_types=1);

namespace Typo3UpdateCheckWeb\Build\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Typo3UpdateCheckWeb\Build\ExplanationCache;

final class ExplanationCacheTest extends TestCase
{
    /** @return array<string,mixed> */
    private function advisory(string $id, string $severity = 'high'): array
    {
        return ['id' => $id, 'title' => 't', 'severity' => $severity, 'package' => 'typo3/cms-core', 'affectedVersions' => '>=1.0.0'];
    }

    #[Test]
    public function explainsOnceThenReusesUntilContentOrPromptChanges(): void
    {
        $calls = 0;
        $explain = function (array $advisory) use (&$calls): array {
            ++$calls;
            return [
                'en' => ['plainImpact' => 'p' . $calls, 'urgency' => 'u'],
                'de' => ['plainImpact' => 'd' . $calls, 'urgency' => 'u'],
            ];
        };
        $cache = new ExplanationCache();
        $langs = ['en', 'de'];

        // First time: explained.
        $r1 = $cache->merge([$this->advisory('SA-1')], [], 1, $langs, $explain);
        $this->assertSame(1, $calls);
        $this->assertSame(1, $r1['newlyExplained']);

        // Same content + same promptVersion: reused, no new call.
        $r2 = $cache->merge([$this->advisory('SA-1')], $r1['explanations'], 1, $langs, $explain);
        $this->assertSame(1, $calls);
        $this->assertSame(0, $r2['newlyExplained']);

        // Changed content (severity): re-explained.
        $r3 = $cache->merge([$this->advisory('SA-1', 'critical')], $r2['explanations'], 1, $langs, $explain);
        $this->assertSame(2, $calls);

        // Bumped promptVersion: re-explained.
        $r4 = $cache->merge([$this->advisory('SA-1', 'critical')], $r3['explanations'], 2, $langs, $explain);
        $this->assertSame(3, $calls);
    }

    #[Test]
    public function aPartialCacheEntryIsNotReusedEvenWhenHashAndVersionMatch(): void
    {
        $calls = 0;
        $explain = function (array $advisory) use (&$calls): array {
            ++$calls;
            return ['en' => ['plainImpact' => 'p', 'urgency' => 'u'], 'de' => ['plainImpact' => 'd', 'urgency' => 'u']];
        };
        $cache = new ExplanationCache();
        $langs = ['en', 'de'];

        $key = ExplanationCache::cacheKey($this->advisory('SA-1'));
        $complete = $cache->merge([$this->advisory('SA-1')], [], 1, $langs, $explain)['explanations'];
        $this->assertSame(1, $calls);

        // Simulate a pre-existing entry missing German (e.g. an older partial cache on disk).
        $partial = $complete;
        unset($partial[$key]['langs']['de']);

        $result = $cache->merge([$this->advisory('SA-1')], $partial, 1, $langs, $explain);
        $this->assertSame(2, $calls); // re-explained despite matching hash + promptVersion
        $this->assertTrue(ExplanationCache::hasAllLangs($result['explanations'][$key], $langs));
    }

    #[Test]
    public function failedExplanationIsNotCachedAndRetriesNextTime(): void
    {
        $explain = static fn (array $advisory): ?array => null; // fail-soft

        $result = (new ExplanationCache())->merge([$this->advisory('SA-3')], [], 1, ['en', 'de'], $explain);

        $this->assertSame(0, $result['newlyExplained']);
        $this->assertArrayNotHasKey(ExplanationCache::cacheKey($this->advisory('SA-3')), $result['explanations']);
    }

    #[Test]
    public function prunesEntriesThatNoLongerBelongToAnyCurrentAdvisory(): void
    {
        // Orphans — an aged-out advisory's entry, or a stale legacy id-keyed entry that could not be
        // migrated — must not be re-committed forever; only current advisories' keys survive.
        $advisory = $this->advisory('SA-1');
        $langs = [
            'en' => ['plainImpact' => 'p', 'urgency' => 'u'],
            'de' => ['plainImpact' => 'd', 'urgency' => 'u'],
        ];
        $existing = [
            ExplanationCache::cacheKey($advisory) => ['contentHash' => ExplanationCache::contentHash($advisory), 'promptVersion' => 1, 'langs' => $langs],
            'SA-GONE|core|typo3/cms-core' => ['contentHash' => 'x', 'promptVersion' => 1, 'langs' => $langs],
            'SA-1' => ['contentHash' => 'stale-hash', 'promptVersion' => 1, 'langs' => $langs],
        ];
        $explain = static fn (array $currentAdvisory): ?array => null;

        $result = (new ExplanationCache())->merge([$advisory], $existing, 1, ['en', 'de'], $explain);

        $this->assertSame([ExplanationCache::cacheKey($advisory)], array_keys($result['explanations']));
    }

    #[Test]
    public function coreAndOptionalRecordsSharingAnIdGetSeparateCacheEntries(): void
    {
        // The core/optional split can emit two advisories with the SAME id (one core, one optional).
        // Keying by id alone would let one overwrite the other's explanation — key per published record.
        $core = ['id' => 'SAME', 'title' => 't', 'severity' => 'high', 'package' => 'typo3/cms-core', 'affectedVersions' => '>=1.0.0', 'optional' => false];
        $optionalAdvisory = ['id' => 'SAME', 'title' => 't', 'severity' => 'high', 'package' => 'typo3/cms-form', 'affectedVersions' => '>=1.0.0', 'optional' => true];
        $explain = static fn (array $explained): array => ['en' => ['plainImpact' => $explained['package'], 'urgency' => 'u'], 'de' => ['plainImpact' => $explained['package'], 'urgency' => 'u']];

        $result = (new ExplanationCache())->merge([$core, $optionalAdvisory], [], 1, ['en', 'de'], $explain);

        $this->assertSame(2, $result['newlyExplained']); // both explained — neither overwrote the other
        $this->assertNotSame(ExplanationCache::cacheKey($core), ExplanationCache::cacheKey($optionalAdvisory));
        $this->assertArrayHasKey(ExplanationCache::cacheKey($core), $result['explanations']);
        $this->assertArrayHasKey(ExplanationCache::cacheKey($optionalAdvisory), $result['explanations']);
    }

    #[Test]
    public function aValidLegacyIdKeyedEntryIsMigratedToTheCompositeKeyWithoutReexplaining(): void
    {
        // Older caches were keyed by advisory id alone. A legacy entry that still matches the advisory's
        // content + prompt + languages must be reused (re-homed under the composite key), not discarded.
        $advisory = $this->advisory('SA-1');
        $hash = ExplanationCache::contentHash($advisory);
        $legacy = ['SA-1' => ['contentHash' => $hash, 'promptVersion' => 1, 'langs' => [
            'en' => ['plainImpact' => 'p', 'urgency' => 'u'],
            'de' => ['plainImpact' => 'd', 'urgency' => 'u'],
        ]]];
        $calls = 0;
        $explain = function (array $advisoryToExplain) use (&$calls): array {
            ++$calls;
            return ['en' => ['plainImpact' => 'x', 'urgency' => 'u'], 'de' => ['plainImpact' => 'y', 'urgency' => 'u']];
        };

        $result = (new ExplanationCache())->merge([$advisory], $legacy, 1, ['en', 'de'], $explain);

        $key = ExplanationCache::cacheKey($advisory);
        $this->assertSame(0, $calls);                                  // reused, not re-explained
        $this->assertSame(0, $result['newlyExplained']);
        $this->assertArrayHasKey($key, $result['explanations']);       // re-homed under the composite key
        $this->assertArrayNotHasKey('SA-1', $result['explanations']);  // legacy key removed (no orphan)
        $this->assertSame('p', $result['explanations'][$key]['langs']['en']['plainImpact']); // content preserved
    }

    #[Test]
    public function aStaleLegacyEntryIsNotMigratedAndAFailedRegenerationPublishesNothing(): void
    {
        // A legacy entry whose content no longer matches must NOT be migrated; if regeneration then
        // fails (e.g. no API key), nothing is published rather than serving outdated text.
        $advisory = $this->advisory('SA-1', 'critical'); // content differs from the legacy hash below
        $legacy = ['SA-1' => ['contentHash' => 'stale-hash', 'promptVersion' => 1, 'langs' => [
            'en' => ['plainImpact' => 'p', 'urgency' => 'u'],
            'de' => ['plainImpact' => 'd', 'urgency' => 'u'],
        ]]];
        $explain = static fn (array $staleAdvisory): ?array => null; // regeneration fails

        $result = (new ExplanationCache())->merge([$advisory], $legacy, 1, ['en', 'de'], $explain);

        $key = ExplanationCache::cacheKey($advisory);
        $this->assertSame(0, $result['newlyExplained']);
        $this->assertArrayNotHasKey($key, $result['explanations']); // not migrated, not fabricated
    }
}
