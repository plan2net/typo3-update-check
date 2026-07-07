<?php

declare(strict_types=1);

// Validates the generated typo3.json against the full contract. Exits non-zero listing every
// violation. Run in CI after build.php and before the site build / commit / deploy.

$path = $argv[1] ?? (__DIR__ . '/../public/data/typo3.json');
$raw = is_file($path) ? file_get_contents($path) : false;
$data = is_string($raw) ? json_decode($raw, true) : null;

if (!is_array($data)) {
    fwrite(STDERR, "typo3.json is not valid JSON\n");
    exit(1);
}

$errors = [];
$fail = static function (string $msg) use (&$errors): void { $errors[] = $msg; };
$str = static fn ($v): bool => is_string($v) && $v !== '';
$strOrNull = static fn ($v): bool => $v === null || is_string($v);

if (!$str($data['generatedAt'] ?? null)) $fail('generatedAt');
if (!is_array($data['majors'] ?? null)) $fail('majors');
if (!is_array($data['advisories'] ?? null)) {
    $fail('advisories');
} elseif ($data['advisories'] === []) {
    $fail('advisories list is empty — refusing to publish an all-clear dataset');
}

foreach (($data['majors'] ?? []) as $mk => $major) {
    $p = "major {$mk}";
    foreach (['maintainedUntil', 'eltsUntil', 'latestFree', 'latestElts'] as $k) {
        if (!$str($major[$k] ?? null)) $fail("{$p}.{$k}");
    }
    $versions = [];
    if (!is_array($major['releases'] ?? null) || $major['releases'] === []) {
        $fail("{$p}.releases empty");
    } else {
        foreach ($major['releases'] as $i => $r) {
            if (!is_array($r) || !$str($r['version'] ?? null)) { $fail("{$p}.releases[{$i}].version"); continue; }
            if (!$strOrNull($r['date'] ?? null)) $fail("{$p}.releases[{$i}].date");
            if (!$str($r['type'] ?? null)) $fail("{$p}.releases[{$i}].type");
            if (!is_bool($r['elts'] ?? null)) $fail("{$p}.releases[{$i}].elts");
            $versions[$r['version']] = true;
        }
        foreach (['latestFree', 'latestElts'] as $k) {
            $v = $major[$k] ?? null;
            if (is_string($v) && !isset($versions[$v])) $fail("{$p}.{$k} '{$v}' not in releases");
        }
    }
}

$seenKeys = [];
$coreCount = 0;
foreach (($data['advisories'] ?? []) as $i => $a) {
    $p = "advisory[{$i}]";
    if (!is_array($a)) { $fail("{$p} not an object"); continue; }
    if (!$str($a['id'] ?? null)) $fail("{$p}.id");
    // Must stay unique per published record (matches ExplanationCache::cacheKey) or one advisory's
    // explanation would overwrite another's in the cache.
    $key = (string) ($a['id'] ?? '') . '|' . (($a['optional'] ?? false) ? 'optional' : 'core') . '|' . (string) ($a['package'] ?? '');
    if (isset($seenKeys[$key])) $fail("{$p} duplicate id/optional/package key '{$key}'");
    $seenKeys[$key] = true;
    if (($a['optional'] ?? null) === false) $coreCount++;
    if (!$strOrNull($a['cve'] ?? null)) $fail("{$p}.cve");
    if (!$str($a['package'] ?? null)) $fail("{$p}.package");
    if (!is_bool($a['optional'] ?? null)) $fail("{$p}.optional");
    if (!$str($a['severity'] ?? null)) $fail("{$p}.severity");
    if (!$str($a['title'] ?? null)) $fail("{$p}.title");
    if (!$str($a['affectedVersions'] ?? null)) $fail("{$p}.affectedVersions");
    if (!$strOrNull($a['link'] ?? null)) $fail("{$p}.link");

    if (!is_array($a['affected'] ?? null) || $a['affected'] === []) {
        $fail("{$p}.affected empty");
    } else {
        foreach ($a['affected'] as $mk => $e) {
            if (!is_array($e) || !$str($e['from'] ?? null)) $fail("{$p}.affected.{$mk}.from");
            if (!array_key_exists('fixedIn', (array) $e) || !$strOrNull($e['fixedIn'] ?? null)) $fail("{$p}.affected.{$mk}.fixedIn");
            if (!is_bool($e['fixedInElts'] ?? null)) $fail("{$p}.affected.{$mk}.fixedInElts");
        }
    }

    $exp = $a['explanation'] ?? null;
    if ($exp !== null) {
        if (!is_array($exp)) {
            $fail("{$p}.explanation");
        } else {
            foreach ($exp as $lang => $t) {
                if (!is_array($t) || !$str($t['plainImpact'] ?? null) || !$str($t['urgency'] ?? null)) {
                    $fail("{$p}.explanation.{$lang}");
                }
            }
        }
    }
}

// A healthy dataset always has core (non-optional) advisories; their absence means partial/broken
// advisory data that could publish core releases as a false "all clear".
if (is_array($data['advisories'] ?? null) && $data['advisories'] !== [] && $coreCount === 0) {
    $fail('no core (non-optional) advisory — advisory data looks partial or broken');
}

if ($errors !== []) {
    fwrite(STDERR, "Invalid typo3.json:\n - " . implode("\n - ", $errors) . "\n");
    exit(1);
}
echo "typo3.json valid\n";
