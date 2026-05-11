<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Tests\Release;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\Typo3UpdateCheck\Release\ApiFailure;
use Plan2net\Typo3UpdateCheck\Release\ApiFailureCategory;

final class ApiFailureTest extends TestCase
{
    #[Test]
    public function holdsCategoryAndDetail(): void
    {
        $failure = new ApiFailure(ApiFailureCategory::ServerError, 'HTTP 503', 503);

        $this->assertSame(ApiFailureCategory::ServerError, $failure->category);
        $this->assertSame('HTTP 503', $failure->detail);
        $this->assertSame(503, $failure->statusCode);
    }

    #[Test]
    public function statusCodeIsOptional(): void
    {
        $failure = new ApiFailure(ApiFailureCategory::ConnectionError, 'cURL: 7');

        $this->assertNull($failure->statusCode);
    }
}
