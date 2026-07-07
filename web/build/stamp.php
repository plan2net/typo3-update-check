<?php

declare(strict_types=1);

use Typo3UpdateCheckWeb\Build\DataFile;
use Typo3UpdateCheckWeb\Build\FreshnessStamp;

require __DIR__ . '/vendor/autoload.php';

// Stamps the checkedAt freshness heartbeat into the DEPLOYED data artifact (not the committed
// source). Run in CI after the site build, before re-validation and deploy.

$path = $argv[1] ?? null;
if ($path === null) {
    fwrite(STDERR, "Usage: php stamp.php <path-to-typo3.json>\n");
    exit(1);
}

try {
    $dataset = json_decode((string) @file_get_contents($path), true);
    if (!is_array($dataset)) {
        throw new \RuntimeException("{$path} is missing or not valid JSON");
    }
    DataFile::write($path, FreshnessStamp::stamp($dataset, new \DateTimeImmutable()));
} catch (\Throwable $failure) {
    fwrite(STDERR, 'stamp: ' . $failure->getMessage() . "\n");
    exit(1);
}

echo "Stamped checkedAt into {$path}\n";
