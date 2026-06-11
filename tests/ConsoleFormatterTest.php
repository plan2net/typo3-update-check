<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\Typo3UpdateCheck\Advisory\Advisory;
use Plan2net\Typo3UpdateCheck\Advisory\AdvisoryStatus;
use Plan2net\Typo3UpdateCheck\Change\BreakingChange;
use Plan2net\Typo3UpdateCheck\Change\RegularChange;
use Plan2net\Typo3UpdateCheck\Change\SecurityUpdate;
use Plan2net\Typo3UpdateCheck\ConsoleFormatter;
use Plan2net\Typo3UpdateCheck\Release\ApiFailure;
use Plan2net\Typo3UpdateCheck\Release\ApiFailureCategory;
use Plan2net\Typo3UpdateCheck\Release\ReleaseContent;
use Plan2net\Typo3UpdateCheck\Release\ReleaseContentBatch;
use Plan2net\Typo3UpdateCheck\UpdateScope;

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
            advisories: [
                new Advisory('typo3/cms-core', 'Advisory A', null, 'high', 'https://example.org/a', '<12.4.31'),
                new Advisory('typo3/cms-core', 'Advisory B', null, 'high', 'https://example.org/b', '<12.4.31'),
                new Advisory('typo3/cms-core', 'Advisory C', null, 'medium', 'https://example.org/c', '<12.4.31'),
                new Advisory('typo3/cms-core', 'Advisory D', null, 'low', 'https://example.org/d', '<12.4.31'),
                new Advisory('typo3/cms-core', 'Advisory E', null, 'low', 'https://example.org/e', '<12.4.31'),
                new Advisory('typo3/cms-core', 'Advisory F', null, 'low', 'https://example.org/f', '<12.4.31'),
            ],
        );

        $output = $this->formatter->format($content);

        $this->assertStringContainsString('Security updates found (6 vulnerabilities: <fg=red>2 high</>, 1 medium, 3 low):', $output);
        $this->assertStringContainsString('Fixed by:', $output);
        $this->assertStringContainsString('[SECURITY] Fix XSS vulnerability', $output);
        $this->assertStringContainsString('[SECURITY] Fix information disclosure', $output);
        $this->assertStringContainsString('[SECURITY] Fix authentication bypass', $output);
    }

    #[Test]
    public function rendersAdvisoryWithCveAndSeverity(): void
    {
        $content = new ReleaseContent(
            version: '12.4.31',
            changes: [new SecurityUpdate('[SECURITY] Fix XSS vulnerability')],
            newsLink: null,
            news: null,
            advisories: [
                new Advisory('typo3/cms-core', 'Cross-Site Scripting in backend', 'CVE-2025-0001', 'high', 'https://typo3.org/security/advisory/typo3-core-sa-2025-001', '>=12,<12.4.31'),
            ],
        );

        $output = $this->formatter->format($content);

        // Advisory rows render directly beneath the heading (no separate header, no blank line)
        $this->assertStringContainsString(
            "<comment>Security updates found (1 vulnerability: <fg=red>1 high</>):</comment>\n"
            . '  - CVE-2025-0001  <fg=red>(high)</>  Cross-Site Scripting in backend — https://typo3.org/security/advisory/typo3-core-sa-2025-001',
            $output,
        );
        $this->assertStringNotContainsString('Security advisories:', $output);
        $this->assertStringContainsString(
            "\n<info>Fixed by:</info>\n  ⚡ [SECURITY] Fix XSS vulnerability\n",
            $output,
        );
    }

    #[Test]
    public function rendersMultipleAdvisoriesWithColumnAlignment(): void
    {
        // CVE-2024-1234 (len 13), CVE-2024-55921 (len 14) → cveWidth=14
        // medium (len 8 with parens → (medium)), high (len 6 with parens → (high)) → severityWidth=8
        // CVE-2024-1234  padded to 14: "CVE-2024-1234 "
        // (medium) right-padded-left to 8: "(medium)"
        // (high)   right-padded-left to 8: "  (high)"
        $content = new ReleaseContent(
            version: '12.4.31',
            changes: [new SecurityUpdate('[SECURITY] Multiple fixes')],
            newsLink: null,
            news: null,
            advisories: [
                new Advisory('typo3/cms-core', 'Title A', 'CVE-2024-1234', 'medium', 'https://example.org/a', '<12.4.31'),
                new Advisory('typo3/cms-core', 'Title B', 'CVE-2024-55921', 'high', 'https://example.org/b', '<12.4.31'),
            ],
        );

        $output = $this->formatter->format($content);

        $this->assertStringContainsString('Security updates found (2 vulnerabilities: <fg=red>1 high</>, 1 medium):', $output);
        $this->assertStringContainsString(
            '  - CVE-2024-1234   (medium)  Title A — https://example.org/a',
            $output,
        );
        // Padding is computed on the raw cell text; the color tags wrap the already padded cell
        $this->assertStringContainsString(
            '  - CVE-2024-55921  <fg=red>  (high)</>  Title B — https://example.org/b',
            $output,
        );
        // High severity sorts before medium
        $this->assertLessThan(
            strpos($output, 'CVE-2024-1234'),
            strpos($output, 'CVE-2024-55921'),
        );
    }

    #[Test]
    public function sortsAdvisoriesBySeverityWithUnknownLastAndStableWithinGroups(): void
    {
        $content = new ReleaseContent(
            version: '12.4.31',
            changes: [],
            newsLink: null,
            news: null,
            advisories: [
                new Advisory('typo3/cms-core', 'Medium one', null, 'medium', 'https://example.org/a', '<12.4.31'),
                new Advisory('typo3/cms-core', 'Low one', null, 'low', 'https://example.org/b', '<12.4.31'),
                new Advisory('typo3/cms-core', 'High one', null, 'high', 'https://example.org/c', '<12.4.31'),
                new Advisory('typo3/cms-core', 'Critical one', null, 'critical', 'https://example.org/d', '<12.4.31'),
                new Advisory('typo3/cms-core', 'Unknown one', null, null, 'https://example.org/e', '<12.4.31'),
                new Advisory('typo3/cms-core', 'Medium two', null, 'medium', 'https://example.org/f', '<12.4.31'),
            ],
        );

        $output = $this->formatter->format($content);

        $positions = array_map(
            static fn (string $title): int => (int) strpos($output, $title),
            ['Critical one', 'High one', 'Medium one', 'Medium two', 'Low one', 'Unknown one'],
        );

        $sortedPositions = $positions;
        sort($sortedPositions);
        $this->assertSame($sortedPositions, $positions);
    }

    #[Test]
    public function highlightsCriticalAndHighSeveritiesButKeepsOthersPlain(): void
    {
        $content = new ReleaseContent(
            version: '12.4.31',
            changes: [],
            newsLink: null,
            news: null,
            advisories: [
                new Advisory('typo3/cms-core', 'Critical title', null, 'critical', 'https://example.org/a', '<12.4.31'),
                new Advisory('typo3/cms-core', 'High title', null, 'high', 'https://example.org/b', '<12.4.31'),
                new Advisory('typo3/cms-core', 'Medium title', null, 'medium', 'https://example.org/c', '<12.4.31'),
                new Advisory('typo3/cms-core', 'Low title', null, 'low', 'https://example.org/d', '<12.4.31'),
            ],
        );

        $output = $this->formatter->format($content);

        // Widest cell is (critical) → width 10
        $this->assertStringContainsString('  - <fg=red;options=bold>(critical)</>  Critical title — https://example.org/a', $output);
        $this->assertStringContainsString('  - <fg=red>    (high)</>  High title — https://example.org/b', $output);
        $this->assertStringContainsString('  -   (medium)  Medium title — https://example.org/c', $output);
        $this->assertStringContainsString('  -      (low)  Low title — https://example.org/d', $output);
        $this->assertStringNotContainsString('<fg=red>  (medium)', $output);
        $this->assertStringNotContainsString('(low)</>', $output);
    }

    #[Test]
    public function escapesConsoleFormattingTagsInAdvisoryRows(): void
    {
        $content = new ReleaseContent(
            version: '12.4.31',
            changes: [],
            newsLink: null,
            news: null,
            advisories: [
                new Advisory('typo3/cms-core', 'Title with <script>alert(1)</script>', 'CVE-2025-0001', 'high', 'https://example.org/<error>a</error>', '<12.4.31'),
            ],
        );

        $output = $this->formatter->format($content);

        $this->assertStringContainsString('Title with &lt;script&gt;alert(1)&lt;/script&gt;', $output);
        $this->assertStringContainsString('https://example.org/&lt;error&gt;a&lt;/error&gt;', $output);
        $this->assertStringNotContainsString('<script>', $output);
        $this->assertStringNotContainsString('<error>a</error>', $output);
    }

    #[Test]
    public function rendersNullSeverityRowWithSpacePaddingWhenOtherRowsHaveSeverity(): void
    {
        // CVE-2025-0001 (len 13), cveWidth=13; severityWidth=8 (from (critical))
        // null severity row gets 8 spaces in severity column
        $content = new ReleaseContent(
            version: '12.4.31',
            changes: [new SecurityUpdate('[SECURITY] Fix')],
            newsLink: null,
            news: null,
            advisories: [
                new Advisory('typo3/cms-core', 'Title A', 'CVE-2025-0001', 'critical', 'https://example.org/a', '<12.4.31'),
                new Advisory('typo3/cms-core', 'Title B', 'CVE-2025-0002', null, 'https://example.org/b', '<12.4.31'),
            ],
        );

        $output = $this->formatter->format($content);

        $this->assertStringContainsString('Security updates found (2 vulnerabilities: <fg=red;options=bold>1 critical</>):', $output);
        // (critical) has length 10 → severityWidth=10
        $this->assertStringContainsString(
            '  - CVE-2025-0001  <fg=red;options=bold>(critical)</>  Title A — https://example.org/a',
            $output,
        );
        // null severity → 10 spaces padded left + 2-space separator on each side
        $this->assertStringContainsString(
            '  - CVE-2025-0002              Title B — https://example.org/b',
            $output,
        );
    }

    #[Test]
    public function omitsCveColumnWhenAllAdvisoriesLackCve(): void
    {
        $content = new ReleaseContent(
            version: '12.4.31',
            changes: [],
            newsLink: null,
            news: null,
            advisories: [
                new Advisory('typo3/cms-core', 'Title A', null, 'high', 'https://example.org/a', '<12.4.31'),
                new Advisory('typo3/cms-core', 'Title B', null, 'medium', 'https://example.org/b', '<12.4.31'),
            ],
        );

        $output = $this->formatter->format($content);

        // No CVE column; severity column present: (high)=6 chars, (medium)=8 chars → severityWidth=8
        // (high) right-aligned to 8 = "  (high)", (medium) right-aligned to 8 = "(medium)"
        $this->assertStringContainsString('Security updates found (2 vulnerabilities: <fg=red>1 high</>, 1 medium):', $output);
        $this->assertStringContainsString('  - <fg=red>  (high)</>  Title A — https://example.org/a', $output);
        $this->assertStringContainsString('  - (medium)  Title B — https://example.org/b', $output);
        $this->assertStringNotContainsString('Fixed by:', $output);
    }

    #[Test]
    public function rendersAdvisoryWithoutCveAndSeverityAsTitleAndLink(): void
    {
        $content = new ReleaseContent(
            version: '12.4.31',
            changes: [],
            newsLink: null,
            news: null,
            advisories: [
                new Advisory('typo3/cms-core', 'Information disclosure in install tool', null, null, 'https://example.org/advisory', '>=12,<12.4.31'),
            ],
        );

        $output = $this->formatter->format($content);

        $this->assertStringContainsString('Security updates found (1 vulnerability):', $output);
        $this->assertStringContainsString(
            '  - Information disclosure in install tool — https://example.org/advisory',
            $output,
        );
        $this->assertStringNotContainsString('Fixed by:', $output);
    }

    #[Test]
    public function omitsSeverityBreakdownWhenAllSeveritiesAreUnknown(): void
    {
        $content = new ReleaseContent(
            version: '12.4.31',
            changes: [],
            newsLink: null,
            news: null,
            advisories: [
                new Advisory('typo3/cms-core', 'Title A', 'CVE-2025-0001', null, 'https://example.org/a', '<12.4.31'),
                new Advisory('typo3/cms-core', 'Title B', 'CVE-2025-0002', null, 'https://example.org/b', '<12.4.31'),
            ],
        );

        $output = $this->formatter->format($content);

        $this->assertStringContainsString('Security updates found (2 vulnerabilities):', $output);
    }

    #[Test]
    public function suppressesBulletinUrlsWhenAdvisoriesArePresent(): void
    {
        $content = new ReleaseContent(
            version: '12.4.31',
            changes: [new SecurityUpdate('[SECURITY] Fix XSS vulnerability')],
            newsLink: null,
            news: 'Security release. https://typo3.org/security/advisory/typo3-core-sa-2025-001',
            advisories: [
                new Advisory('typo3/cms-core', 'Cross-Site Scripting in backend', 'CVE-2025-0001', 'high', 'https://typo3.org/security/advisory/typo3-core-sa-2025-001', '>=12,<12.4.31'),
            ],
        );

        $output = $this->formatter->format($content);

        $this->assertStringContainsString('CVE-2025-0001  <fg=red>(high)</>  Cross-Site Scripting in backend', $output);
        $this->assertStringNotContainsString('  - https://typo3.org/security/advisory', $output);
        $this->assertStringNotContainsString('Security advisories:', $output);
    }

    #[Test]
    public function showsBulletinUrlsAsFallbackWhenNoAdvisories(): void
    {
        $content = new ReleaseContent(
            version: '12.4.31',
            changes: [],
            newsLink: null,
            news: 'Security release. https://typo3.org/security/advisory/typo3-core-sa-2025-001',
        );

        $output = $this->formatter->format($content);

        $this->assertStringContainsString('Security advisories:', $output);
        $this->assertStringContainsString('https://typo3.org/security/advisory/typo3-core-sa-2025-001', $output);
    }

    #[Test]
    public function batchReportIncludesReleaseWithOnlyAdvisories(): void
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

        $report = implode("\n", $this->formatter->formatBatchReport($batch, '12.4.30', '12.4.31'));

        $this->assertStringContainsString('Changes in version 12.4.31:', $report);
        $this->assertStringContainsString('Security updates found (1 vulnerability: <fg=red>1 high</>):', $report);
        $this->assertStringContainsString('CVE-2025-0001  <fg=red>(high)</>  Cross-Site Scripting in backend', $report);
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
            advisories: [
                new Advisory('typo3/cms-core', 'Advisory A', null, 'high', 'https://example.org/a', '<12.4.21'),
                new Advisory('typo3/cms-core', 'Advisory B', null, 'medium', 'https://example.org/b', '<12.4.21'),
                new Advisory('typo3/cms-core', 'Advisory C', null, 'medium', 'https://example.org/c', '<12.4.21'),
            ],
        );
        $batch = new ReleaseContentBatch(
            results: ['12.4.20' => $breaking, '12.4.21' => $security],
            failures: [],
        );

        $lines = $this->formatter->formatBatchReport($batch, '12.4.19', '12.4.21');
        $digest = end($lines);

        $this->assertStringContainsString('2 releases (12.4.19 → 12.4.21)', $digest);
        $this->assertStringContainsString('1 with security (<fg=red>1 high</>, 2 medium)', $digest);
        $this->assertStringContainsString('1 with breaking changes', $digest);
    }

    #[Test]
    public function digestBreakdownWrapsCriticalAndHighInColorTags(): void
    {
        $security = new ReleaseContent(
            version: '12.4.21',
            changes: [],
            newsLink: null,
            news: null,
            advisories: [
                new Advisory('typo3/cms-core', 'Advisory A', null, 'critical', 'https://example.org/a', '<12.4.21'),
                new Advisory('typo3/cms-core', 'Advisory B', null, 'high', 'https://example.org/b', '<12.4.21'),
                new Advisory('typo3/cms-core', 'Advisory C', null, 'high', 'https://example.org/c', '<12.4.21'),
                new Advisory('typo3/cms-core', 'Advisory D', null, 'low', 'https://example.org/d', '<12.4.21'),
            ],
        );
        $batch = new ReleaseContentBatch(results: ['12.4.21' => $security], failures: []);

        $lines = $this->formatter->formatBatchReport($batch, '12.4.20', '12.4.21');
        $digest = end($lines);

        $this->assertStringContainsString('(<fg=red;options=bold>1 critical</>, <fg=red>2 high</>, 1 low)', $digest);
    }

    #[Test]
    public function annotatesSecurityReleaseWithoutAdvisoriesWhenAdvisoryDataIsAvailable(): void
    {
        $security = new ReleaseContent(
            version: '12.4.46',
            changes: [new SecurityUpdate('[SECURITY] Mitigate deserialization flaws')],
            newsLink: null,
            news: null,
        );
        $batch = new ReleaseContentBatch(
            results: ['12.4.46' => $security],
            failures: [],
            advisoryStatus: AdvisoryStatus::Available,
        );

        $report = implode("\n", $this->formatter->formatBatchReport($batch, '12.4.45', '12.4.46'));

        $this->assertStringContainsString(
            'CVE and severity details are not yet published on Packagist for this release.',
            $report,
        );
    }

    #[Test]
    public function doesNotAnnotateSecurityReleaseWhenAdvisoriesWereNotAttempted(): void
    {
        $security = new ReleaseContent(
            version: '12.4.46',
            changes: [new SecurityUpdate('[SECURITY] Mitigate deserialization flaws')],
            newsLink: null,
            news: null,
        );
        $batch = new ReleaseContentBatch(results: ['12.4.46' => $security], failures: []);

        $report = implode("\n", $this->formatter->formatBatchReport($batch, '12.4.45', '12.4.46'));

        $this->assertStringNotContainsString('Packagist', $report);
    }

    #[Test]
    public function digestMarksSecurityReleasesWithoutSeverityRatingsAsUnrated(): void
    {
        $rated = (new ReleaseContent(
            version: '12.4.41',
            changes: [new SecurityUpdate('[SECURITY] Fix access control')],
            newsLink: null,
            news: null,
        ))->withAdvisories(new Advisory(
            packageName: 'typo3/cms-core',
            title: 'Broken Access Control',
            cve: 'CVE-2025-59022',
            severity: 'high',
            link: 'https://example.com/advisory',
            affectedVersions: '<12.4.41',
        ));
        $unrated = new ReleaseContent(
            version: '12.4.46',
            changes: [new SecurityUpdate('[SECURITY] Mitigate deserialization flaws')],
            newsLink: null,
            news: null,
        );
        $batch = new ReleaseContentBatch(
            results: ['12.4.41' => $rated, '12.4.46' => $unrated],
            failures: [],
            advisoryStatus: AdvisoryStatus::Available,
        );

        $lines = $this->formatter->formatBatchReport($batch, '12.4.40', '12.4.46');
        $digest = end($lines);

        $this->assertStringContainsString('2 with security (<fg=red>1 high</> · 1 unrated)', $digest);
    }

    #[Test]
    public function warnsOnceWhenAdvisoryDataIsUnavailable(): void
    {
        $security = new ReleaseContent(
            version: '12.4.46',
            changes: [new SecurityUpdate('[SECURITY] Mitigate deserialization flaws')],
            newsLink: null,
            news: null,
        );
        $batch = new ReleaseContentBatch(
            results: ['12.4.46' => $security],
            failures: [],
            advisoryStatus: AdvisoryStatus::Unavailable,
        );

        $report = implode("\n", $this->formatter->formatBatchReport($batch, '12.4.45', '12.4.46'));

        $this->assertStringContainsString(
            'Security advisory data from Packagist is unavailable — CVE and severity details are not shown.',
            $report,
        );
        $this->assertStringNotContainsString('not yet published on Packagist', $report);
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

    #[Test]
    public function condensedFormatCollapsesBreakingChangesToCount(): void
    {
        $content = new ReleaseContent(
            version: '15.0.0',
            changes: [
                new BreakingChange('[!!!][TASK] Remove feature A'),
                new BreakingChange('[!!!][TASK] Remove feature B'),
                new SecurityUpdate('[SECURITY] Fix XSS vulnerability'),
            ],
            newsLink: 'https://typo3.org/article/typo3-1500-released',
            news: null,
        );

        $output = $this->formatter->format($content, AdvisoryStatus::NotAttempted, condensed: true);

        $this->assertStringContainsString('2 breaking changes', $output);
        $this->assertStringNotContainsString('Remove feature A', $output);
        $this->assertStringContainsString('[SECURITY] Fix XSS vulnerability', $output);
        $this->assertStringContainsString('https://typo3.org/article/typo3-1500-released', $output);
    }

    #[Test]
    public function condensedFormatUsesSingularForOneBreakingChange(): void
    {
        $content = new ReleaseContent(
            version: '15.0.0',
            changes: [new BreakingChange('[!!!][TASK] Remove feature A')],
            newsLink: null,
            news: null,
        );

        $output = $this->formatter->format($content, AdvisoryStatus::NotAttempted, condensed: true);

        $this->assertStringContainsString('1 breaking change', $output);
        $this->assertStringNotContainsString('breaking changes', $output);
    }

    #[Test]
    public function majorBumpHeaderListsChangelogForEveryCrossedMajor(): void
    {
        $lines = $this->formatter->formatMajorBumpHeader(new UpdateScope('12.4.10', '14.0.0'));

        $joined = implode("\n", $lines);
        $this->assertStringContainsString('Major version upgrade: 12 → 14', $joined);
        $this->assertStringContainsString('https://docs.typo3.org/m/typo3/reference-coreapi/main/en-us/Administration/Upgrade/Index.html', $joined);
        $this->assertStringContainsString('https://docs.typo3.org/c/typo3/cms-core/main/en-us/Changelog-13.html', $joined);
        $this->assertStringContainsString('https://docs.typo3.org/c/typo3/cms-core/main/en-us/Changelog-14.html', $joined);
        $this->assertStringNotContainsString('Changelog-12.html', $joined);
        $this->assertStringContainsString('one major version at a time', $joined);
        $this->assertSame('', $lines[array_key_last($lines)]);
    }

    #[Test]
    public function majorBumpHeaderOmitsMultiMajorWarningForSingleMajorBump(): void
    {
        $joined = implode("\n", $this->formatter->formatMajorBumpHeader(new UpdateScope('12.4.10', '13.4.5')));

        $this->assertStringContainsString('Major version upgrade: 12 → 13', $joined);
        $this->assertStringNotContainsString('one major version at a time', $joined);
    }

    #[Test]
    public function majorBumpHeaderIsEmptyWithinMajor(): void
    {
        $this->assertSame([], $this->formatter->formatMajorBumpHeader(new UpdateScope('12.4.10', '12.4.20')));
    }
}
