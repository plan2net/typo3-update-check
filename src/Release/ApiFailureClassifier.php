<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Release;

use Plan2net\Typo3UpdateCheck\Http\HttpTransportException;

final class ApiFailureClassifier
{
    public function fromThrowable(\Throwable $throwable): ApiFailure
    {
        if ($throwable instanceof \JsonException) {
            return new ApiFailure(
                ApiFailureCategory::MalformedResponse,
                'Malformed JSON: ' . $throwable->getMessage(),
            );
        }

        if ($throwable instanceof HttpTransportException) {
            if ($throwable->connectionError) {
                return new ApiFailure(
                    ApiFailureCategory::ConnectionError,
                    'Connection failed: ' . $throwable->getMessage(),
                );
            }

            $status = $throwable->statusCode ?? 0;
            $category = match (true) {
                $status >= 500 => ApiFailureCategory::ServerError,
                $status === 404 => ApiFailureCategory::NotFound,
                default => ApiFailureCategory::Unknown,
            };

            return new ApiFailure($category, $throwable->getMessage(), $throwable->statusCode);
        }

        return new ApiFailure(
            ApiFailureCategory::Unknown,
            $throwable->getMessage(),
        );
    }
}
