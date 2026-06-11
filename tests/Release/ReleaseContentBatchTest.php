<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Tests\Release;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\Typo3UpdateCheck\Advisory\Advisory;
use Plan2net\Typo3UpdateCheck\Change\BreakingChange;
use Plan2net\Typo3UpdateCheck\Change\RegularChange;
use Plan2net\Typo3UpdateCheck\Change\SecurityUpdate;
use Plan2net\Typo3UpdateCheck\Release\ApiFailure;
use Plan2net\Typo3UpdateCheck\Release\ApiFailureCategory;
use Plan2net\Typo3UpdateCheck\Release\ReleaseContent;
use Plan2net\Typo3UpdateCheck\Release\ReleaseContentBatch;

final class ReleaseContentBatchTest extends TestCase
{
    #[Test]
    public function exposesResultsAndFailures(): void
    {
        $content = new ReleaseContent(
            version: '14.2.0',
            changes: [],
            newsLink: null,
            news: null,
        );
        $failure = new ApiFailure(ApiFailureCategory::ServerError, 'HTTP 503', 503);

        $batch = new ReleaseContentBatch(
            results: ['14.2.0' => $content],
            failures: ['14.3.0' => $failure],
        );

        $this->assertSame(['14.2.0' => $content], $batch->results);
        $this->assertSame(['14.3.0' => $failure], $batch->failures);
        $this->assertTrue($batch->hasResults());
        $this->assertTrue($batch->hasFailures());
    }

    #[Test]
    public function emptyBatchReportsNothing(): void
    {
        $batch = new ReleaseContentBatch(results: [], failures: []);

        $this->assertFalse($batch->hasResults());
        $this->assertFalse($batch->hasFailures());
    }

    #[Test]
    public function reportsImportantChangesWhenAResultHasBreakingOrSecurity(): void
    {
        $withBreaking = new ReleaseContent(
            version: '12.4.20',
            changes: [new BreakingChange('[!!!][TASK] Remove API')],
            newsLink: null,
            news: null,
        );
        $batch = new ReleaseContentBatch(results: ['12.4.20' => $withBreaking], failures: []);

        $this->assertTrue($batch->hasImportantChanges());
    }

    #[Test]
    public function reportsNoImportantChangesWhenResultsOnlyContainRegularChanges(): void
    {
        $regularOnly = new ReleaseContent(
            version: '12.4.20',
            changes: [new RegularChange('[FEATURE] Add something')],
            newsLink: null,
            news: null,
        );
        $batch = new ReleaseContentBatch(results: ['12.4.20' => $regularOnly], failures: []);

        $this->assertFalse($batch->hasImportantChanges());
    }

    #[Test]
    public function reportsImportantChangesWhenAResultHasOnlyAdvisories(): void
    {
        $advisoryOnly = new ReleaseContent(
            version: '12.4.31',
            changes: [],
            newsLink: null,
            news: null,
            advisories: [
                new Advisory('typo3/cms-core', 'Cross-Site Scripting in backend', 'CVE-2025-0001', 'high', 'https://example.org/advisory', '>=12,<12.4.31'),
            ],
        );
        $batch = new ReleaseContentBatch(results: ['12.4.31' => $advisoryOnly], failures: []);

        $this->assertTrue($batch->hasImportantChanges());
    }

    #[Test]
    public function reportsImportantChangesAcrossMultipleResults(): void
    {
        $quiet = new ReleaseContent(version: '12.4.20', changes: [], newsLink: null, news: null);
        $security = new ReleaseContent(
            version: '12.4.21',
            changes: [new SecurityUpdate('[SECURITY] Fix XSS')],
            newsLink: null,
            news: null,
        );
        $batch = new ReleaseContentBatch(
            results: ['12.4.20' => $quiet, '12.4.21' => $security],
            failures: [],
        );

        $this->assertTrue($batch->hasImportantChanges());
    }

    #[Test]
    public function dominantFailureCategoryReturnsNullWhenNoFailures(): void
    {
        $batch = new ReleaseContentBatch(results: [], failures: []);

        $this->assertNull($batch->dominantFailureCategory());
    }

    #[Test]
    public function dominantFailureCategoryReturnsMostFrequent(): void
    {
        $batch = new ReleaseContentBatch(
            results: [],
            failures: [
                '14.2.0' => new ApiFailure(ApiFailureCategory::ConnectionError, 'a'),
                '14.2.1' => new ApiFailure(ApiFailureCategory::ConnectionError, 'b'),
                '14.2.2' => new ApiFailure(ApiFailureCategory::ConnectionError, 'c'),
                '14.3.0' => new ApiFailure(ApiFailureCategory::ServerError, 'd', 503),
            ],
        );

        $this->assertSame(ApiFailureCategory::ConnectionError, $batch->dominantFailureCategory());
    }

    #[Test]
    public function dominantFailureCategoryBreaksTieBySeverity(): void
    {
        $batch = new ReleaseContentBatch(
            results: [],
            failures: [
                '14.2.0' => new ApiFailure(ApiFailureCategory::ConnectionError, 'a'),
                '14.2.1' => new ApiFailure(ApiFailureCategory::ConnectionError, 'b'),
                '14.3.0' => new ApiFailure(ApiFailureCategory::ServerError, 'c', 503),
                '14.3.1' => new ApiFailure(ApiFailureCategory::ServerError, 'd', 503),
            ],
        );

        $this->assertSame(ApiFailureCategory::ServerError, $batch->dominantFailureCategory());
    }
}
