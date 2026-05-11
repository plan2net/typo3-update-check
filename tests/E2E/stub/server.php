<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (preg_match('#^/api/v1/major/(\d+)/release/?$#', $path, $matches)) {
    header('Content-Type: application/json');
    if ((int) $matches[1] === 503) {
        http_response_code(503);

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
    if ($version === 'error-retry-after') {
        http_response_code(503);
        header('Retry-After: 60');
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
