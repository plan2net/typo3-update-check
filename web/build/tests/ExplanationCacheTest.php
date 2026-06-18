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

        $complete = $cache->merge([$this->advisory('SA-1')], [], 1, $langs, $explain)['explanations'];
        $this->assertSame(1, $calls);

        // Simulate a pre-existing entry missing German (e.g. an older partial cache on disk).
        $partial = $complete;
        unset($partial['SA-1']['langs']['de']);

        $result = $cache->merge([$this->advisory('SA-1')], $partial, 1, $langs, $explain);
        $this->assertSame(2, $calls); // re-explained despite matching hash + promptVersion
        $this->assertTrue(ExplanationCache::hasAllLangs($result['explanations']['SA-1'], $langs));
    }

    #[Test]
    public function failedExplanationIsNotCachedAndRetriesNextTime(): void
    {
        $explain = static fn (array $advisory): ?array => null; // fail-soft

        $result = (new ExplanationCache())->merge([$this->advisory('SA-3')], [], 1, ['en', 'de'], $explain);

        $this->assertSame(0, $result['newlyExplained']);
        $this->assertArrayNotHasKey('SA-3', $result['explanations']);
    }
}
