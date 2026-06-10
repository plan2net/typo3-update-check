<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Http;

interface HttpClient
{
    /**
     * Single request. Adapter-level retries (HTTP 429) are already applied.
     *
     * @throws HttpTransportException on connection failure or final HTTP error status
     */
    public function get(string $url): HttpResponse;

    /**
     * Concurrent fetch. Never throws; per-URL failures are returned in the result.
     *
     * @param array<int|string, string> $urls
     *
     * @return array<int|string, HttpResponse|HttpTransportException> keyed like $urls
     */
    public function getMany(array $urls): array;
}
