<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Tests\E2E;

use PHPUnit\Framework\Attributes\Test;
use Plan2net\Typo3UpdateCheck\Advisory\AdvisoryStatus;
use Plan2net\Typo3UpdateCheck\Change\ChangeFactory;
use Plan2net\Typo3UpdateCheck\Change\ChangeParser;
use Plan2net\Typo3UpdateCheck\Release\ApiFailureCategory;
use Plan2net\Typo3UpdateCheck\Release\ApiFailureException;
use Plan2net\Typo3UpdateCheck\Release\Release;
use Plan2net\Typo3UpdateCheck\Release\ReleaseContent;
use Plan2net\Typo3UpdateCheck\Release\ReleaseProvider;

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
            self::makeProvider()->getReleasesForMajorVersion(503);
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
        $batch = self::makeProvider()->getReleaseContents(['error-404']);

        $this->assertSame(ApiFailureCategory::NotFound, $batch->failures['error-404']->category);
        $this->assertSame([], $batch->results);
        $this->assertSame([], self::$recordedDelaysMs);
    }

    #[Test]
    public function content503IsServerErrorWithoutAdapterRetry(): void
    {
        $batch = self::makeProvider()->getReleaseContents(['error-503']);

        $this->assertSame(ApiFailureCategory::ServerError, $batch->failures['error-503']->category);
        $this->assertSame(503, $batch->failures['error-503']->statusCode);
        $this->assertSame([], self::$recordedDelaysMs);
    }

    #[Test]
    public function content429ExhaustsAdapterRetriesWithBackoff(): void
    {
        $batch = self::makeProvider()->getReleaseContents(['error-429']);

        $this->assertSame(429, $batch->failures['error-429']->statusCode);
        $this->assertSame([1000, 2000], self::$recordedDelaysMs);
    }

    #[Test]
    public function retryAfterHeaderIsHonoredAndCappedAtFiveSeconds(): void
    {
        $batch = self::makeProvider()->getReleaseContents(['error-429-retry-after']);

        $this->assertSame(429, $batch->failures['error-429-retry-after']->statusCode);
        $this->assertSame([5000, 5000], self::$recordedDelaysMs);
    }

    #[Test]
    public function batchRetryRoundRecovers429AlongsideStableResults(): void
    {
        $batch = self::makeProvider()->getReleaseContents(['14.3.0', 'flaky-429']);

        $this->assertArrayHasKey('14.3.0', $batch->results);
        $this->assertArrayHasKey('flaky-429', $batch->results);
        $this->assertSame([], $batch->failures);
        $this->assertSame([1000], self::$recordedDelaysMs);
    }

    #[Test]
    public function transient5xxRecoversViaComposerTransport(): void
    {
        if (!extension_loaded('curl') || !self::composerSupportsTransientRetries()) {
            $this->markTestSkipped('Transient 5xx retry is delegated to the curl transport of Composer >= 2.3');
        }

        $batch = self::makeProvider()->getReleaseContents(['flaky-503']);

        $this->assertArrayHasKey('flaky-503', $batch->results);
        $this->assertSame([], self::$recordedDelaysMs);
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
    public function partialBatchContainsBothResultsAndFailures(): void
    {
        $batch = self::makeProvider()->getReleaseContents(['14.3.0', 'error-404']);

        $this->assertTrue($batch->hasResults());
        $this->assertTrue($batch->hasFailures());
        $this->assertArrayHasKey('14.3.0', $batch->results);
        $this->assertArrayHasKey('error-404', $batch->failures);
    }

    #[Test]
    public function enrichesReleaseWithAdvisoriesFromPackagistEndpoint(): void
    {
        $batch = self::makeProvider(withAdvisories: true)->getReleaseContents(['14.3.0'], '14.2.0');

        $advisories = $batch->results['14.3.0']->advisories;
        $this->assertCount(1, $advisories);
        $this->assertSame('CVE-2026-0001', $advisories[0]->cve);
        $this->assertSame('high', $advisories[0]->severity);
        $this->assertSame(['high' => 1], $batch->results['14.3.0']->getSeverityCounts());
    }

    #[Test]
    public function packagistFailureLeavesBatchIntactWithoutAdvisories(): void
    {
        $provider = new ReleaseProvider(
            self::makeHttpClient(['Accept: application/json']),
            new ChangeParser(new ChangeFactory()),
            null,
            null,
            self::makeAdvisoryProvider('/packagist-broken'),
            self::stubUrl('/api/v1'),
        );

        $batch = $provider->getReleaseContents(['14.3.0'], '14.2.0');

        $this->assertArrayHasKey('14.3.0', $batch->results);
        $this->assertSame([], $batch->results['14.3.0']->advisories);
        $this->assertSame([], $batch->failures);
        $this->assertSame(AdvisoryStatus::Unavailable, $batch->advisoryStatus);
    }

    #[Test]
    public function mergesReleaseListsAcrossMajors(): void
    {
        $provider = self::makeProvider();

        $releases = $provider->getReleasesForMajorRange(14, 15);

        $versions = array_map(static fn (Release $release): string => $release->version, $releases);
        $this->assertSame(['14.2.0', '14.3.0', '15.0.0', '15.1.0'], $versions);
    }

    #[Test]
    public function failsRangeWhenOneMajorListFails(): void
    {
        $provider = self::makeProvider();

        $this->expectException(ApiFailureException::class);

        $provider->getReleasesForMajorRange(15, 16);
    }

    private static function composerSupportsTransientRetries(): bool
    {
        $composerVersion = \Composer\Composer::VERSION;
        if (preg_match('/^\d+\.\d+/', $composerVersion) !== 1) {
            // Source installs report a branch placeholder; assume a current Composer.
            return true;
        }

        return version_compare($composerVersion, '2.3.0', '>=');
    }
}
