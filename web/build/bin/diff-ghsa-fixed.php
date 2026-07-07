<?php

declare(strict_types=1);

// Audit tool: diff every published advisory's per-major fixedIn against the GitHub Advisory
// Database's first_patched_version. The Packagist ranges we build from occasionally contradict
// GHSA's own first-patched field (upstream data bugs); this script finds those cases so they can
// be corrected via data-overrides.json.
//
// The GHSA dump is fetched separately (gh needs host auth, PHP runs in the container):
//   for cve in $(jq -r '.advisories[].cve // empty' typo3.json); do
//     echo "{\"$cve\": $(gh api "/advisories?cve_id=$cve")}"; sleep 0.3;
//   done | jq -s 'add' > ghsa-dump.json
//
// Usage: php bin/diff-ghsa-fixed.php <path-to-typo3.json> <path-to-ghsa-dump.json>

$path = $argv[1] ?? (__DIR__ . '/../../public/data/typo3.json');
$dumpPath = $argv[2] ?? null;
$dataset = json_decode((string) file_get_contents($path), true);
if (!is_array($dataset) || !isset($dataset['advisories'])) {
    fwrite(STDERR, "Could not read dataset at {$path}\n");
    exit(1);
}
$dump = $dumpPath !== null ? json_decode((string) file_get_contents($dumpPath), true) : null;
if (!is_array($dump)) {
    fwrite(STDERR, "Could not read the GHSA dump — see the fetch one-liner in this script's header.\n");
    exit(1);
}

$mismatches = 0;
$unverifiable = 0;
foreach ($dataset['advisories'] as $advisory) {
    $cve = $advisory['cve'] ?? null;
    if (!is_string($cve) || $cve === '') {
        ++$unverifiable;
        fwrite(STDOUT, "SKIP  {$advisory['id']}: no CVE to query by\n");
        continue;
    }

    $records = $dump[$cve] ?? null;
    if (!is_array($records) || $records === []) {
        ++$unverifiable;
        fwrite(STDOUT, "SKIP  {$cve}: not found in the GitHub Advisory Database\n");
        continue;
    }

    // Expected fix per major: the LATEST first_patched_version across the composer packages the
    // record covers (our core records union constraints, so the last-fixed package wins).
    $expectedByMajor = [];
    foreach ($records as $record) {
        foreach (($record['vulnerabilities'] ?? []) as $vulnerability) {
            if (($vulnerability['package']['ecosystem'] ?? '') !== 'composer') {
                continue;
            }
            $patched = $vulnerability['first_patched_version'] ?? null;
            if (!is_string($patched) || !preg_match('/^(\d+)\./', $patched, $match)) {
                continue;
            }
            $major = $match[1];
            if (!isset($expectedByMajor[$major]) || version_compare($patched, $expectedByMajor[$major], '>')) {
                $expectedByMajor[$major] = $patched;
            }
        }
    }

    foreach (($advisory['affected'] ?? []) as $major => $entry) {
        $ours = $entry['fixedIn'];
        $ghsa = $expectedByMajor[$major] ?? null;
        if ($ghsa === null || $ours === null) {
            continue; // GHSA has no first-patched for this major (or we report unfixed) — nothing to diff
        }
        if ($ours !== $ghsa) {
            ++$mismatches;
            fwrite(STDOUT, "DIFF  {$cve} ({$advisory['package']}, major {$major}): dataset fixedIn={$ours}, GHSA first_patched={$ghsa}\n");
        }
    }
}

fwrite(STDOUT, "\nDone: {$mismatches} mismatch(es), {$unverifiable} unverifiable.\n");
exit($mismatches > 0 ? 1 : 0);
