<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Release;

use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ConnectException;
use Psr\Http\Message\ResponseInterface;

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

        if ($throwable instanceof ConnectException) {
            return new ApiFailure(
                ApiFailureCategory::ConnectionError,
                'Connection failed: ' . $throwable->getMessage(),
            );
        }

        if ($throwable instanceof BadResponseException) {
            return $this->fromResponse($throwable->getResponse(), $throwable->getMessage());
        }

        return new ApiFailure(
            ApiFailureCategory::Unknown,
            $throwable->getMessage(),
        );
    }

    public function fromResponse(ResponseInterface $response, ?string $detail = null): ApiFailure
    {
        $status = $response->getStatusCode();
        $category = match (true) {
            $status >= 500 => ApiFailureCategory::ServerError,
            $status === 404 => ApiFailureCategory::NotFound,
            default => ApiFailureCategory::Unknown,
        };

        return new ApiFailure($category, $detail ?? sprintf('HTTP %d', $status), $status);
    }
}
