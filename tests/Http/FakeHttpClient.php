<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Tests\Http;

use Plan2net\Typo3UpdateCheck\Http\HttpClient;
use Plan2net\Typo3UpdateCheck\Http\HttpResponse;
use Plan2net\Typo3UpdateCheck\Http\HttpTransportException;

final class FakeHttpClient implements HttpClient
{
    /** @var array<string, list<HttpResponse|HttpTransportException>> */
    private array $queues = [];

    /** @var list<array{method: string, url: string}> */
    public array $requests = [];

    public function queue(string $url, HttpResponse|HttpTransportException $outcome): void
    {
        $this->queues[$url][] = $outcome;
    }

    public function queueJson(string $url, string $jsonBody): void
    {
        $this->queue($url, new HttpResponse(200, ['content-type' => 'application/json'], $jsonBody));
    }

    public function get(string $url): HttpResponse
    {
        $this->requests[] = ['method' => 'get', 'url' => $url];
        $outcome = $this->nextOutcome($url);
        if ($outcome instanceof HttpTransportException) {
            throw $outcome;
        }

        return $outcome;
    }

    public function getMany(array $urls): array
    {
        $outcomes = [];
        foreach ($urls as $key => $url) {
            $this->requests[] = ['method' => 'getMany', 'url' => $url];
            $outcomes[$key] = $this->nextOutcome($url);
        }

        return $outcomes;
    }

    private function nextOutcome(string $url): HttpResponse|HttpTransportException
    {
        if (empty($this->queues[$url])) {
            throw new \LogicException("No queued outcome for {$url}");
        }

        return array_shift($this->queues[$url]);
    }
}
