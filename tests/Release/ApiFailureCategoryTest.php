<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Tests\Release;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\Typo3UpdateCheck\Release\ApiFailureCategory;

final class ApiFailureCategoryTest extends TestCase
{
    #[Test]
    public function exposesAllExpectedCases(): void
    {
        $values = array_map(
            static fn (ApiFailureCategory $c): string => $c->value,
            ApiFailureCategory::cases(),
        );

        $this->assertSame(
            ['connection_error', 'server_error', 'not_found', 'malformed_response', 'unknown'],
            $values,
        );
    }

    #[Test]
    public function severityOrdersServerErrorAboveConnectionError(): void
    {
        $this->assertGreaterThan(
            ApiFailureCategory::ConnectionError->severity(),
            ApiFailureCategory::ServerError->severity(),
        );
        $this->assertGreaterThan(
            ApiFailureCategory::MalformedResponse->severity(),
            ApiFailureCategory::ConnectionError->severity(),
        );
        $this->assertGreaterThan(
            ApiFailureCategory::NotFound->severity(),
            ApiFailureCategory::MalformedResponse->severity(),
        );
        $this->assertGreaterThan(
            ApiFailureCategory::Unknown->severity(),
            ApiFailureCategory::NotFound->severity(),
        );
    }
}
