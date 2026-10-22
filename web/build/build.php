<?php

declare(strict_types=1);

use Typo3UpdateCheckWeb\Build\Advisories;
use Typo3UpdateCheckWeb\Build\AdvisoryDescription;
use Typo3UpdateCheckWeb\Build\AffectedResolver;
use Typo3UpdateCheckWeb\Build\BundledAdvisories;
use Typo3UpdateCheckWeb\Build\BundledPackages;
use Typo3UpdateCheckWeb\Build\CoreConstraints;
use Typo3UpdateCheckWeb\Build\DataFile;
use Typo3UpdateCheckWeb\Build\ExplanationCache;
use Typo3UpdateCheckWeb\Build\ExplanationJudge;
use Typo3UpdateCheckWeb\Build\Explainer;
use Typo3UpdateCheckWeb\Build\Http;
use Typo3UpdateCheckWeb\Build\Overrides;
use Typo3UpdateCheckWeb\Build\SeverityBackfill;
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
$bundledPackages = (new BundledPackages($http->getText(...), __DIR__ . '/bundled-packages.json'))->forReleases($majors);
$coreConstraints = (new CoreConstraints($http->getText(...)))->forPackages(Advisories::ALWAYS_PRESENT);
$bundled = (new BundledAdvisories($http, new AffectedResolver()))->build($majors, $bundledPackages, $coreConstraints);

// Packagist serves a just-published advisory unrated; read the rating off TYPO3's own bulletin
// instead of showing "not rated". Before the overrides, so a curated severity still wins.
$advisories = (new SeverityBackfill($http->getText(...)))->apply($advisories);

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
    $githubToken = (string) getenv('GITHUB_TOKEN');
    $descriptions = new AdvisoryDescription(static fn (string $url): ?string => $http->getText(
        $url,
        $githubToken !== '' && str_starts_with($url, 'https://api.github.com/') ? ['Authorization: Bearer ' . $githubToken] : [],
    ));
    $explain = static function (array $advisory) use ($explainer, $judge, $descriptions, $wantLangs): ?array {
        // Explaining from the title alone means guessing; retry next run instead.
        $description = $descriptions->for($advisory);
        if ($description === null) {
            fwrite(STDERR, "no description for {$advisory['id']} ({$advisory['link']}); not explained\n");

            return null;
        }
        $langs = [];
        foreach ($wantLangs as $lang) {
            $rejection = null;
            for ($attempt = 1; $attempt <= 2 && !isset($langs[$lang]); ++$attempt) {
                $candidate = $explainer->explain($advisory, $description, $lang, $rejection);
                if ($candidate === null) {
                    continue;
                }
                $rejection = $judge->rejection($advisory, $description, $lang, $candidate);
                if ($rejection === null) {
                    $langs[$lang] = $candidate;
                }
            }
            // A partial entry would count as done forever; skip the rest and retry next run.
            if (!isset($langs[$lang])) {
                return null;
            }
        }

        return $langs;
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
usort($bundled, static fn (array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));
ksort($explanations);

// Only rewrite typo3.json (with a fresh timestamp) when the meaningful body changed — a new
// generatedAt every run would otherwise produce an empty daily commit.
$body = ['majors' => $majors, 'advisories' => $advisories, 'bundled' => $bundled];
$existing = is_file($typoPath) ? json_decode((string) file_get_contents($typoPath), true) : null;
$existingBody = is_array($existing)
    ? ['majors' => $existing['majors'] ?? null, 'advisories' => $existing['advisories'] ?? null, 'bundled' => $existing['bundled'] ?? null]
    : null;
$changed = $body != $existingBody; // loose: ignore key order, compare values
if ($changed) {
    DataFile::write($typoPath, ['generatedAt' => gmdate('c')] + $body);
}
DataFile::write($cachePath, $explanations);

fwrite(STDOUT, sprintf(
    "Wrote %d majors, %d advisories (%d newly explained), %d bundled-library advisories; data %s.\n",
    count($majors), count($advisories), $merged['newlyExplained'], count($bundled), $changed ? 'changed' : 'unchanged',
));
