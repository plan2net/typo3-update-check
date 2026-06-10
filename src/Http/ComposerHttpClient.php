<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Http;

use Composer\Downloader\TransportException;
use Composer\Util\Http\Response as ComposerResponse;
use Composer\Util\HttpDownloader;

final class ComposerHttpClient implements HttpClient
{
    private const TIMEOUT_SECONDS = 10;

    private readonly \Closure $sleep;

    /**
     * @param string[] $requestHeaders raw header lines, e.g. 'Accept: application/json'
     */
    public function __construct(
        private readonly HttpDownloader $httpDownloader,
        private readonly array $requestHeaders = [],
        ?\Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (int $delayMs): void {
            usleep($delayMs * 1000);
        };
    }

    public function get(string $url): HttpResponse
    {
        $attempt = 0;
        while (true) {
            try {
                return $this->toHttpResponse($this->httpDownloader->get($url, $this->requestOptions()));
            } catch (\Throwable $throwable) {
                $translated = $this->translate($throwable);
                if ($attempt >= RetryPolicy::MAX_RETRIES || !RetryPolicy::shouldRetry($translated)) {
                    throw $translated;
                }

                ++$attempt;
                ($this->sleep)(RetryPolicy::delayMs($attempt, $translated->headers));
            }
        }
    }

    public function getMany(array $urls): array
    {
        /** @var array<int|string, HttpResponse|HttpTransportException> $outcomes */
        $outcomes = [];
        $pending = $urls;
        $attempt = 0;

        while ($pending !== []) {
            /** @var array<int|string, HttpTransportException> $failures */
            $failures = [];
            foreach ($pending as $key => $url) {
                try {
                    $this->httpDownloader->add($url, $this->requestOptions())->then(
                        function (ComposerResponse $response) use (&$outcomes, $key): void {
                            $outcomes[$key] = $this->toHttpResponse($response);
                        },
                        function (\Throwable $throwable) use (&$failures, $key): void {
                            $failures[$key] = $this->translate($throwable);
                        },
                    );
                } catch (\Throwable $throwable) {
                    $failures[$key] = $this->translate($throwable);
                }
            }

            try {
                $this->httpDownloader->wait();
            } catch (\Throwable $throwable) {
                foreach ($pending as $key => $url) {
                    if (!isset($outcomes[$key]) && !isset($failures[$key])) {
                        $failures[$key] = $this->translate($throwable);
                    }
                }
            }

            $pending = [];
            $maxDelayMs = 0;
            foreach ($failures as $key => $failure) {
                if ($attempt < RetryPolicy::MAX_RETRIES && RetryPolicy::shouldRetry($failure)) {
                    $pending[$key] = $urls[$key];
                    $maxDelayMs = max($maxDelayMs, RetryPolicy::delayMs($attempt + 1, $failure->headers));
                } else {
                    $outcomes[$key] = $failure;
                }
            }

            if ($pending !== []) {
                ($this->sleep)($maxDelayMs);
            }
            ++$attempt;
        }

        foreach ($urls as $key => $url) {
            if (!isset($outcomes[$key])) {
                $outcomes[$key] = HttpTransportException::forUnexpectedError(
                    sprintf('No outcome recorded for %s', $url),
                );
            }
        }

        return $outcomes;
    }

    /**
     * @return array<string, mixed>
     */
    private function requestOptions(): array
    {
        $options = [
            'http' => ['timeout' => self::TIMEOUT_SECONDS],
            'retry-auth-failure' => false,
        ];
        if ($this->requestHeaders !== []) {
            $options['http']['header'] = $this->requestHeaders;
        }

        return $options;
    }

    private function toHttpResponse(ComposerResponse $response): HttpResponse
    {
        return new HttpResponse(
            $response->getStatusCode(),
            $this->parseHeaderLines($response->getHeaders()),
            (string) $response->getBody(),
        );
    }

    private function translate(\Throwable $throwable): HttpTransportException
    {
        if ($throwable instanceof HttpTransportException) {
            return $throwable;
        }

        if ($throwable instanceof TransportException) {
            $statusCode = $throwable->getStatusCode();
            if ($statusCode === null) {
                return HttpTransportException::forConnectionError($throwable->getMessage());
            }

            return HttpTransportException::forHttpError(
                $throwable->getMessage(),
                $statusCode,
                $this->parseHeaderLines($throwable->getHeaders()),
            );
        }

        return HttpTransportException::forUnexpectedError($throwable->getMessage());
    }

    /**
     * @param string[]|null $headerLines
     *
     * @return array<string, string>
     */
    private function parseHeaderLines(?array $headerLines): array
    {
        $headers = [];
        foreach ($headerLines ?? [] as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }

        return $headers;
    }
}
