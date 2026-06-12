<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$stateFile = sys_get_temp_dir() . '/typo3-update-check-e2e-stub-state.json';

if ($path === '/reset') {
    @unlink($stateFile);
    http_response_code(204);

    return;
}

$attemptNumber = static function (string $key) use ($stateFile): int {
    $state = is_file($stateFile) ? (json_decode((string) file_get_contents($stateFile), true) ?: []) : [];
    $state[$key] = ($state[$key] ?? 0) + 1;
    file_put_contents($stateFile, json_encode($state));

    return $state[$key];
};

if (str_starts_with($path, '/packagist/api/security-advisories/')) {
    header('Content-Type: application/json');
    echo json_encode([
        'advisories' => [
            'typo3/cms-core' => [
                [
                    'advisoryId' => 'PKSA-e2e-0001',
                    'packageName' => 'typo3/cms-core',
                    'title' => 'E2E stub vulnerability',
                    'link' => 'https://github.com/advisories/GHSA-e2e1',
                    'cve' => 'CVE-2026-0001',
                    'affectedVersions' => '>=14.0.0,<14.3.0',
                    'severity' => 'high',
                ],
            ],
        ],
    ]);

    return;
}

if (preg_match('#^/api/v1/major/(\d+)/release/?$#', $path, $matches)) {
    header('Content-Type: application/json');
    $major = (int) $matches[1];
    if ($major === 503 || $major === 16) {
        http_response_code(503);

        return;
    }
    if ($major === 15) {
        echo json_encode([
            ['version' => '15.1.0', 'date' => '2026-10-06T08:00:00+02:00', 'type' => 'regular'],
            ['version' => '15.0.0', 'date' => '2026-09-01T08:00:00+02:00', 'type' => 'regular'],
        ]);

        return;
    }
    echo json_encode([
        ['version' => '14.3.0', 'date' => '2026-04-21T09:30:20+02:00', 'type' => 'regular'],
        ['version' => '14.2.0', 'date' => '2026-03-31T07:38:51+02:00', 'type' => 'regular'],
    ]);

    return;
}

if (preg_match('#^/api/v1/release/([^/]+)/content$#', $path, $matches)) {
    $version = $matches[1];
    if ($version === 'error-404') {
        http_response_code(404);

        return;
    }
    if ($version === 'error-503') {
        http_response_code(503);
        header('Content-Type: application/json');

        return;
    }
    if ($version === 'error-429') {
        http_response_code(429);
        header('Content-Type: application/json');

        return;
    }
    if ($version === 'error-429-retry-after') {
        http_response_code(429);
        header('Retry-After: 60');
        header('Content-Type: application/json');

        return;
    }
    if ($version === 'flaky-429' && $attemptNumber('flaky-429') === 1) {
        http_response_code(429);
        header('Content-Type: application/json');

        return;
    }
    if ($version === 'flaky-503' && $attemptNumber('flaky-503') === 1) {
        http_response_code(503);
        header('Content-Type: application/json');

        return;
    }
    if ($version === 'error-malformed') {
        header('Content-Type: text/html');
        echo '<html>not json</html>';

        return;
    }
    header('Content-Type: application/json');
    echo json_encode([
        'version' => $version,
        'release_notes' => [
            'version' => $version,
            'news_link' => 'https://example.com/news',
            'news' => "TYPO3 {$version} is here!",
            'changes' => " * 2026-04-21 abc123 [!!!][FEATURE] Breaking change (thanks to Alice)\n"
                . ' * 2026-04-21 def456 [SECURITY] Security fix (thanks to Bob)',
        ],
    ]);

    return;
}

http_response_code(404);
