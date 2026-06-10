<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Http;

final class RetryPolicy
{
    public const MAX_RETRIES = 2;
    public const RETRY_AFTER_CAP_SECONDS = 5;
    private const BACKOFF_MS = [1000, 2000];

    public static function shouldRetry(HttpTransportException $exception): bool
    {
        return $exception->statusCode === 429;
    }

    /**
     * @param array<string, string> $headers lowercased header names
     */
    public static function delayMs(int $attempt, array $headers): int
    {
        $retryAfter = $headers['retry-after'] ?? null;
        if ($retryAfter !== null) {
            $seconds = is_numeric($retryAfter)
                ? (int) $retryAfter
                : max(0, (int) strtotime($retryAfter) - time());

            return min(max(0, $seconds), self::RETRY_AFTER_CAP_SECONDS) * 1000;
        }

        $index = max(0, $attempt - 1);

        return self::BACKOFF_MS[$index] ?? self::BACKOFF_MS[count(self::BACKOFF_MS) - 1];
    }
}
