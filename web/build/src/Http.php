<?php

declare(strict_types=1);

namespace Typo3UpdateCheckWeb\Build;

final class Http
{
    /** @return array<mixed> */
    public function get(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'typo3-update-check-web-build',
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // PHP 8 frees the CurlHandle when $ch goes out of scope — no curl_close needed.

        if (!is_string($body) || $status >= 400) {
            throw new \RuntimeException("HTTP {$status} for {$url}");
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new \RuntimeException("Malformed JSON from {$url}");
        }

        return $data;
    }
}
