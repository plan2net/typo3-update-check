<?php

declare(strict_types=1);

use Typo3UpdateCheckWeb\Build\Advisories;
use Typo3UpdateCheckWeb\Build\AffectedResolver;
use Typo3UpdateCheckWeb\Build\DataFile;
use Typo3UpdateCheckWeb\Build\ExplanationCache;
use Typo3UpdateCheckWeb\Build\ExplanationJudge;
use Typo3UpdateCheckWeb\Build\Explainer;
use Typo3UpdateCheckWeb\Build\Http;
use Typo3UpdateCheckWeb\Build\Overrides;
use Typo3UpdateCheckWeb\Build\Typo3Releases;

require __DIR__ . '/vendor/autoload.php';

$dataDir = __DIR__ . '/../public/data';
$typoPath = $dataDir . '/typo3.json';
$cachePath = $dataDir . '/advisory-explanations.json';

/** @var array<string, array{contentHash:string,promptVersion:int,langs:array<string,array{plainImpact:string,urgency:string}>}> $explanations */
$explanations = is_file($cachePath)
    ? (json_decode((string) file_get_contents($cachePath), true) ?: [])
    : [];

$http = new Http();
$majors = (new Typo3Releases($http))->build();
$advisories = (new Advisories($http, new AffectedResolver()))->build($majors);

// Curated corrections for known upstream data bugs — applied before explanations so the
// added/changed records are explained and validated like everything else.
$overrides = json_decode((string) file_get_contents(__DIR__ . '/data-overrides.json'), true);
$advisories = Overrides::apply($advisories, is_array($overrides) ? $overrides : []);

// Explain new/changed advisories only, in EN + DE; with no API key, the explainer is a no-op (fail-soft).
// Each candidate (writer, medium effort) must pass the judge (reviewer, high effort) to be kept.
$wantLangs = ['en', 'de'];
$apiKey = (string) getenv('ANTHROPIC_API_KEY');
$explain = static fn (array $advisory): ?array => null;
if ($apiKey !== '') {
    $explainer = Explainer::fromEnv();
    $judge = ExplanationJudge::fromEnv();
    $explain = static function (array $advisory) use ($explainer, $judge, $wantLangs): ?array {
        $langs = [];
        foreach ($wantLangs as $lang) {
            $candidate = $explainer->explain($advisory, $lang);
            if ($candidate !== null && $judge->approve($advisory, $lang, $candidate)) {
                $langs[$lang] = $candidate;
            }
        }
        // Require ALL languages — never cache a partial (e.g. EN-only) entry as "complete",
        // or the missing language would be treated as done forever. Retry next run instead.
        return count($langs) === count($wantLangs) ? $langs : null;
    };
}

$merged = (new ExplanationCache())->merge($advisories, $explanations, Explainer::PROMPT_VERSION, $wantLangs, $explain);
$explanations = $merged['explanations'];

foreach ($advisories as &$advisory) {
    // Publish an explanation ONLY if the cached entry matches the CURRENT advisory content +
    // prompt version AND is complete in every required language — never serve a stale entry
    // (left behind when regeneration failed) or a partial one.
    $entry = $explanations[ExplanationCache::cacheKey($advisory)] ?? null;
    $fresh = ExplanationCache::isFresh($entry, ExplanationCache::contentHash($advisory), Explainer::PROMPT_VERSION, $wantLangs);
    $advisory['explanation'] = $fresh ? $entry['langs'] : null;
}
unset($advisory);

// Deterministic ordering so the committed files change only on real data changes.
ksort($majors);
usort($advisories, static fn (array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));
ksort($explanations);

// Only rewrite typo3.json (with a fresh timestamp) when the meaningful body changed — a new
// generatedAt every run would otherwise produce an empty daily commit.
$body = ['majors' => $majors, 'advisories' => $advisories];
$existing = is_file($typoPath) ? json_decode((string) file_get_contents($typoPath), true) : null;
$existingBody = is_array($existing)
    ? ['majors' => $existing['majors'] ?? null, 'advisories' => $existing['advisories'] ?? null]
    : null;
$changed = $body != $existingBody; // loose: ignore key order, compare values
if ($changed) {
    DataFile::write($typoPath, ['generatedAt' => gmdate('c')] + $body);
}
DataFile::write($cachePath, $explanations);

fwrite(STDOUT, sprintf(
    "Wrote %d majors, %d advisories (%d newly explained); data %s.\n",
    count($majors), count($advisories), $merged['newlyExplained'], $changed ? 'changed' : 'unchanged',
));
