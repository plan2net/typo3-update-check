<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Release;

use GuzzleHttp\Exception\ConnectException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class RetryPolicy
{
    public const MAX_RETRIES = 2;
    public const RETRY_AFTER_CAP_SECONDS = 5;
    private const BACKOFF_MS = [1000, 2000];

    public static function decider(): \Closure
    {
        return static function (
            int $retries,
            RequestInterface $request,
            ?ResponseInterface $response = null,
            ?\Throwable $exception = null,
        ): bool {
            if ($retries >= self::MAX_RETRIES) {
                return false;
            }

            if ($exception instanceof ConnectException) {
                return true;
            }

            if ($response === null) {
                return false;
            }

            $status = $response->getStatusCode();

            return $status === 429 || $status >= 500;
        };
    }

    public static function defaultDelay(): \Closure
    {
        return static function (int $retries, ?ResponseInterface $response = null): int {
            if ($response !== null && $response->hasHeader('Retry-After')) {
                $header = $response->getHeaderLine('Retry-After');
                $seconds = is_numeric($header)
                    ? (int) $header
                    : max(0, (int) strtotime($header) - time());
                $capped = min(max(0, $seconds), self::RETRY_AFTER_CAP_SECONDS);

                return $capped * 1000;
            }

            $index = max(0, $retries - 1);

            return self::BACKOFF_MS[$index] ?? self::BACKOFF_MS[count(self::BACKOFF_MS) - 1];
        };
    }
}
