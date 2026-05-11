<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Tests\E2E;

use PHPUnit\Framework\Attributes\Test;
use Plan2net\Typo3UpdateCheck\Release\ApiFailureCategory;
use Plan2net\Typo3UpdateCheck\Release\ApiFailureException;
use Plan2net\Typo3UpdateCheck\Release\ReleaseContent;

final class ReleaseProviderE2ETest extends BaseE2ETestCase
{
    #[Test]
    public function majorListFetchReturnsReleases(): void
    {
        $releases = self::makeProvider()->getReleasesForMajorVersion(14);

        $this->assertGreaterThanOrEqual(2, count($releases));
        $this->assertSame('14.3.0', $releases[0]->version);
    }

    #[Test]
    public function majorList503ThrowsApiFailureExceptionWithServerError(): void
    {
        $this->expectException(ApiFailureException::class);
        try {
            self::makeProvider(withRetry: true)->getReleasesForMajorVersion(503);
        } catch (ApiFailureException $exception) {
            $this->assertSame(ApiFailureCategory::ServerError, $exception->failure->category);
            $this->assertSame(503, $exception->failure->statusCode);
            throw $exception;
        }
    }

    #[Test]
    public function contentFetchReturnsReleaseContentInBatch(): void
    {
        $batch = self::makeProvider()->getReleaseContents(['14.3.0']);

        $this->assertInstanceOf(ReleaseContent::class, $batch->results['14.3.0']);
        $this->assertSame([], $batch->failures);
    }

    #[Test]
    public function content404IsNotFoundFailureWithNoRetry(): void
    {
        $batch = self::makeProvider(withRetry: true)->getReleaseContents(['error-404']);

        $this->assertSame(ApiFailureCategory::NotFound, $batch->failures['error-404']->category);
        $this->assertSame([], $batch->results);
        $this->assertSame([], self::$recordedDelaysMs);
    }

    #[Test]
    public function content503ExhaustsRetriesAndReportsServerError(): void
    {
        $batch = self::makeProvider(withRetry: true)->getReleaseContents(['error-503']);

        $this->assertSame(ApiFailureCategory::ServerError, $batch->failures['error-503']->category);
        $this->assertSame(503, $batch->failures['error-503']->statusCode);
        $this->assertSame([1000, 2000], self::$recordedDelaysMs);
    }

    #[Test]
    public function malformedResponseIsClassifiedAsMalformedResponse(): void
    {
        $batch = self::makeProvider()->getReleaseContents(['error-malformed']);

        $this->assertSame(
            ApiFailureCategory::MalformedResponse,
            $batch->failures['error-malformed']->category,
        );
        $this->assertSame([], $batch->results);
    }

    #[Test]
    public function retryAfterHeaderIsHonoredAndCappedAtFiveSeconds(): void
    {
        $batch = self::makeProvider(withRetry: true)->getReleaseContents(['error-retry-after']);

        $this->assertSame(
            ApiFailureCategory::ServerError,
            $batch->failures['error-retry-after']->category,
        );
        $this->assertSame([5000, 5000], self::$recordedDelaysMs);
    }

    #[Test]
    public function partialBatchContainsBothResultsAndFailures(): void
    {
        $batch = self::makeProvider()->getReleaseContents(['14.3.0', 'error-404']);

        $this->assertTrue($batch->hasResults());
        $this->assertTrue($batch->hasFailures());
        $this->assertArrayHasKey('14.3.0', $batch->results);
        $this->assertArrayHasKey('error-404', $batch->failures);
    }
}
