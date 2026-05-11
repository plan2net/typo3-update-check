<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Release;

final class FailureMessageFormatter
{
    public function describe(string $version, ApiFailure $failure): string
    {
        return match ($failure->category) {
            ApiFailureCategory::ConnectionError => sprintf(
                'Could not reach the TYPO3 API for %s (network error or timeout).',
                $version,
            ),
            ApiFailureCategory::ServerError => sprintf(
                'TYPO3 API returned %d for %s after 3 attempts.',
                $failure->statusCode ?? 0,
                $version,
            ),
            ApiFailureCategory::NotFound => sprintf(
                'TYPO3 API has no release content for %s yet.',
                $version,
            ),
            ApiFailureCategory::MalformedResponse => sprintf(
                'TYPO3 API returned an unexpected response for %s.',
                $version,
            ),
            ApiFailureCategory::Unknown => sprintf(
                'Could not fetch information for %s: %s.',
                $version,
                $failure->detail,
            ),
        };
    }
}
