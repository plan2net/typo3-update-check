<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Tests\Release;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
    public function classifiesConnectExceptionAsConnectionError(): void
    {
        $exception = new ConnectException('Connection refused', new Request('GET', 'http://x'));

        $failure = $this->classifier->fromThrowable($exception);

        $this->assertSame(ApiFailureCategory::ConnectionError, $failure->category);
        $this->assertNull($failure->statusCode);
    }

    #[Test]
    public function classifies5xxAsServerError(): void
    {
        $exception = new ServerException(
            'Server error',
            new Request('GET', 'http://x'),
            new Response(503),
        );

        $failure = $this->classifier->fromThrowable($exception);

        $this->assertSame(ApiFailureCategory::ServerError, $failure->category);
        $this->assertSame(503, $failure->statusCode);
    }

    #[Test]
    public function classifies404AsNotFound(): void
    {
        $exception = RequestException::create(
            new Request('GET', 'http://x'),
            new Response(404),
        );

        $failure = $this->classifier->fromThrowable($exception);

        $this->assertSame(ApiFailureCategory::NotFound, $failure->category);
        $this->assertSame(404, $failure->statusCode);
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
}
