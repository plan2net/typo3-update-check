<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\Typo3UpdateCheck\Change\BreakingChange;
use Plan2net\Typo3UpdateCheck\Change\RegularChange;
use Plan2net\Typo3UpdateCheck\Change\SecurityUpdate;
use Plan2net\Typo3UpdateCheck\ConsoleFormatter;
use Plan2net\Typo3UpdateCheck\Release\ReleaseContent;

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
}
