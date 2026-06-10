<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Tests\Release;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\Typo3UpdateCheck\Http\HttpTransportException;
use Plan2net\Typo3UpdateCheck\Release\ApiFailureCategory;
use Plan2net\Typo3UpdateCheck\Release\ApiFailureClassifier;

final class ApiFailureClassifierTest extends TestCase
{
    private ApiFailureClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new ApiFailureClassifier();
    }

    #[Test]
    public function classifiesJsonExceptionAsMalformedResponse(): void
    {
        $exception = new \JsonException('Syntax error');

        $failure = $this->classifier->fromThrowable($exception);

        $this->assertSame(ApiFailureCategory::MalformedResponse, $failure->category);
    }

    #[Test]
    public function classifiesUnknownThrowableAsUnknown(): void
    {
        $exception = new \RuntimeException('something else');

        $failure = $this->classifier->fromThrowable($exception);

        $this->assertSame(ApiFailureCategory::Unknown, $failure->category);
    }

    #[Test]
    public function detailIncludesOriginalMessage(): void
    {
        $exception = new \JsonException('Syntax error, malformed JSON');

        $failure = $this->classifier->fromThrowable($exception);

        $this->assertStringContainsString('Syntax error, malformed JSON', $failure->detail);
    }

    #[Test]
    public function classifiesTransportConnectionErrorAsConnectionError(): void
    {
        $failure = $this->classifier->fromThrowable(HttpTransportException::forConnectionError('curl error 7'));

        $this->assertSame(ApiFailureCategory::ConnectionError, $failure->category);
        $this->assertNull($failure->statusCode);
    }

    #[Test]
    public function classifiesTransport5xxAsServerError(): void
    {
        $failure = $this->classifier->fromThrowable(HttpTransportException::forHttpError('boom', 503));

        $this->assertSame(ApiFailureCategory::ServerError, $failure->category);
        $this->assertSame(503, $failure->statusCode);
    }

    #[Test]
    public function classifiesTransport404AsNotFound(): void
    {
        $failure = $this->classifier->fromThrowable(HttpTransportException::forHttpError('gone', 404));

        $this->assertSame(ApiFailureCategory::NotFound, $failure->category);
        $this->assertSame(404, $failure->statusCode);
    }

    #[Test]
    public function classifiesTransport429AsUnknown(): void
    {
        $failure = $this->classifier->fromThrowable(HttpTransportException::forHttpError('rate limited', 429));

        $this->assertSame(ApiFailureCategory::Unknown, $failure->category);
        $this->assertSame(429, $failure->statusCode);
    }
}
