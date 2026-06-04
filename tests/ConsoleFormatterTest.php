<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\Typo3UpdateCheck\Change\BreakingChange;
use Plan2net\Typo3UpdateCheck\Change\RegularChange;
use Plan2net\Typo3UpdateCheck\Change\SecurityUpdate;
use Plan2net\Typo3UpdateCheck\ConsoleFormatter;
use Plan2net\Typo3UpdateCheck\Release\ApiFailure;
use Plan2net\Typo3UpdateCheck\Release\ApiFailureCategory;
use Plan2net\Typo3UpdateCheck\Release\ReleaseContent;
use Plan2net\Typo3UpdateCheck\Release\ReleaseContentBatch;

final class ConsoleFormatterTest extends TestCase
{
    private ConsoleFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new ConsoleFormatter();
    }

    #[Test]
    public function formatsReleaseContentWithBreakingChangesAndSecurity(): void
    {
        $changes = [
            new BreakingChange('[!!!][TASK] Remove deprecated API'),
            new SecurityUpdate('[SECURITY] Fix XSS vulnerability'),
            new RegularChange('[FEATURE] Add new feature'),
        ];

        $content = new ReleaseContent(
            version: '12.4.31',
            changes: $changes,
            newsLink: 'https://typo3.org/article/typo3-1231-released',
            news: 'This is a security release',
        );

        $output = $this->formatter->format($content);

        $this->assertStringContainsString('Changes in version 12.4.31:', $output);
        $this->assertStringContainsString('Breaking changes found:', $output);
        $this->assertStringContainsString('Security updates found:', $output);
        $this->assertStringContainsString('[!!!][TASK] Remove deprecated API', $output);
        $this->assertStringContainsString('[SECURITY] Fix XSS vulnerability', $output);
        $this->assertStringContainsString('https://typo3.org/article/typo3-1231-released', $output);
    }

    #[Test]
    public function formatsMultipleBreakingChangesAndSecurityUpdates(): void
    {
        $changes = [
            new BreakingChange('[!!!][TASK] Remove feature A'),
            new BreakingChange('[!!!][TASK] Remove feature B'),
            new SecurityUpdate('[SECURITY] Fix issue 1'),
            new SecurityUpdate('[SECURITY] Fix issue 2'),
            new SecurityUpdate('[SECURITY] Fix issue 3'),
        ];

        $content = new ReleaseContent(
            version: '13.0.0',
            changes: $changes,
            newsLink: null,
            news: null,
        );

        $output = $this->formatter->format($content);

        $this->assertStringContainsString('Breaking changes found:', $output);
        $this->assertStringContainsString('Security updates found:', $output);
    }

    #[Test]
    public function showsSecurityAdvisoriesWhenPresent(): void
    {
        $content = new ReleaseContent(
            version: '12.4.31',
            changes: [],
            newsLink: null,
            news: 'Security release with advisories:
https://typo3.org/security/advisory/typo3-core-sa-2025-011
https://typo3.org/security/advisory/typo3-core-sa-2025-012',
        );

        $output = $this->formatter->format($content);

        $this->assertStringContainsString('Security advisories:', $output);
        $this->assertStringContainsString('https://typo3.org/security/advisory/typo3-core-sa-2025-011', $output);
        $this->assertStringContainsString('https://typo3.org/security/advisory/typo3-core-sa-2025-012', $output);
    }

    #[Test]
    public function escapesConsoleFormattingTagsInChangeTitles(): void
    {
        $changes = [
            new BreakingChange('[!!!][TASK] Malicious <error>RED TEXT</error> in title'),
            new SecurityUpdate('[SECURITY] Fix <info>vulnerability</info> with <comment>tags</comment>'),
        ];

        $content = new ReleaseContent(
            version: '12.4.31',
            changes: $changes,
            newsLink: 'https://example.com/<script>alert("xss")</script>',
            news: null,
        );

        $output = $this->formatter->format($content);

        $this->assertStringContainsString('&lt;error&gt;RED TEXT&lt;/error&gt;', $output);
        $this->assertStringContainsString('&lt;info&gt;vulnerability&lt;/info&gt;', $output);
        $this->assertStringContainsString('&lt;comment&gt;tags&lt;/comment&gt;', $output);

        $this->assertStringNotContainsString('<error>RED TEXT</error>', $output);
        $this->assertStringNotContainsString('<info>vulnerability</info>', $output);

        $this->assertStringContainsString('&lt;script&gt;alert("xss")&lt;/script&gt;', $output);
    }

    #[Test]
    public function removesNonPrintableCharacters(): void
    {
        $changes = [
            new BreakingChange("[!!!][TASK] Title with \x00 null \x1F and \x7F characters"),
            new SecurityUpdate("[SECURITY] Fix with \x08 backspace \x0C form feed"),
        ];

        $content = new ReleaseContent(
            version: '12.4.31',
            changes: $changes,
            newsLink: "https://example.com/\x00test",
            news: null,
        );

        $output = $this->formatter->format($content);

        $this->assertStringNotContainsString("\x00", $output);
        $this->assertStringNotContainsString("\x1F", $output);
        $this->assertStringNotContainsString("\x7F", $output);
        $this->assertStringNotContainsString("\x08", $output);
        $this->assertStringNotContainsString("\x0C", $output);

        $this->assertStringContainsString('[!!!][TASK] Title with  null  and  characters', $output);
        $this->assertStringContainsString('[SECURITY] Fix with  backspace  form feed', $output);
    }

    #[Test]
    public function displaysSeveritySummaryForSecurityUpdates(): void
    {
        $changes = [
            new SecurityUpdate('[SECURITY] Fix XSS vulnerability'),
            new SecurityUpdate('[SECURITY] Fix information disclosure'),
            new SecurityUpdate('[SECURITY] Fix authentication bypass'),
        ];

        $content = new ReleaseContent(
            version: '12.4.31',
            changes: $changes,
            newsLink: null,
            news: null,
            securitySeverities: ['High' => 2, 'Medium' => 1, 'Low' => 3],
        );

        $output = $this->formatter->format($content);

        $this->assertStringContainsString('Security updates found (2 High, 1 Medium, 3 Low):', $output);
        $this->assertStringContainsString('[SECURITY] Fix XSS vulnerability', $output);
        $this->assertStringContainsString('[SECURITY] Fix information disclosure', $output);
        $this->assertStringContainsString('[SECURITY] Fix authentication bypass', $output);
    }

    #[Test]
    public function batchReportRendersImportantResultAndOmitsQuietOnes(): void
    {
        $important = new ReleaseContent(
            version: '12.4.21',
            changes: [new BreakingChange('[!!!][TASK] Remove API')],
            newsLink: null,
            news: null,
        );
        $quiet = new ReleaseContent(version: '12.4.20', changes: [], newsLink: null, news: null);
        $batch = new ReleaseContentBatch(
            results: ['12.4.20' => $quiet, '12.4.21' => $important],
            failures: [],
        );

        $report = implode("\n", $this->formatter->formatBatchReport($batch, '12.4.19', '12.4.21'));

        $this->assertStringContainsString('Changes in version 12.4.21:', $report);
        $this->assertStringContainsString('Breaking changes found:', $report);
        $this->assertStringNotContainsString('Changes in version 12.4.20:', $report);
    }

    #[Test]
    public function batchReportStatesQuietWhenNoImportantChangesAndNoFailures(): void
    {
        $quiet = new ReleaseContent(version: '12.4.20', changes: [], newsLink: null, news: null);
        $batch = new ReleaseContentBatch(results: ['12.4.20' => $quiet], failures: []);

        $report = implode("\n", $this->formatter->formatBatchReport($batch, '12.4.19', '12.4.20'));

        $this->assertStringContainsString('bugfixes only', $report);
        $this->assertStringContainsString('no breaking changes or security updates', $report);
    }

    #[Test]
    public function batchReportEndsWithDigestSummaryOfImportantChanges(): void
    {
        $breaking = new ReleaseContent(
            version: '12.4.20',
            changes: [new BreakingChange('[!!!][TASK] Remove API')],
            newsLink: null,
            news: null,
        );
        $security = new ReleaseContent(
            version: '12.4.21',
            changes: [new SecurityUpdate('[SECURITY] Fix XSS'), new SecurityUpdate('[SECURITY] Fix RCE')],
            newsLink: null,
            news: null,
            securitySeverities: ['High' => 1, 'Medium' => 2],
        );
        $batch = new ReleaseContentBatch(
            results: ['12.4.20' => $breaking, '12.4.21' => $security],
            failures: [],
        );

        $lines = $this->formatter->formatBatchReport($batch, '12.4.19', '12.4.21');
        $digest = end($lines);

        $this->assertStringContainsString('2 releases (12.4.19 → 12.4.21)', $digest);
        $this->assertStringContainsString('1 with security (1 High, 2 Medium)', $digest);
        $this->assertStringContainsString('1 with breaking changes', $digest);
    }

    #[Test]
    public function formatSecurityGapListsNewerSecurityVersions(): void
    {
        $lines = $this->formatter->formatSecurityGap('12.4.25', ['12.4.31', '12.4.41']);

        $report = implode("\n", $lines);
        $this->assertStringContainsString('target 12.4.25 is missing security fixes', $report);
        $this->assertStringContainsString('12.4.31, 12.4.41', $report);
    }

    #[Test]
    public function formatSecurityGapIsEmptyWhenNothingNewer(): void
    {
        $this->assertSame([], $this->formatter->formatSecurityGap('12.4.41', []));
    }

    #[Test]
    public function batchReportRendersFailuresWithRetrySuggestion(): void
    {
        $batch = new ReleaseContentBatch(
            results: [],
            failures: ['12.4.21' => new ApiFailure(ApiFailureCategory::NotFound, 'HTTP 404', 404)],
        );

        $report = implode("\n", $this->formatter->formatBatchReport($batch, '12.4.20', '12.4.21'));

        $this->assertStringContainsString('composer typo3:check-updates 12.4.20 12.4.21', $report);
        $this->assertStringContainsString('Proceeding with update (dominant failure: not_found)', $report);
    }

    #[Test]
    public function batchReportReportsTotalFetchFailureWhenBatchIsEmpty(): void
    {
        $batch = new ReleaseContentBatch(results: [], failures: []);

        $report = implode("\n", $this->formatter->formatBatchReport($batch, '12.4.19', '12.4.20'));

        $this->assertStringContainsString('Failed to fetch release information.', $report);
    }
}
