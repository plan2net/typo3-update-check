<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\Typo3UpdateCheck\Change\BreakingChange;
use Plan2net\Typo3UpdateCheck\Change\Change;
use Plan2net\Typo3UpdateCheck\Change\ChangeFactory;
use Plan2net\Typo3UpdateCheck\Change\ChangeParser;
use Plan2net\Typo3UpdateCheck\Change\SecurityUpdate;
use Plan2net\Typo3UpdateCheck\Release\ReleaseContent;

final class ReleaseContentTest extends TestCase
{
    private ChangeParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ChangeParser(new ChangeFactory());
    }

    #[Test]
    public function parsesReleaseContentWithBreakingChangesAndSecurityUpdates(): void
    {
        // Real API response structure from TYPO3 12.0.0
        $apiResponse = [
            'release_notes' => [
                'version' => '12.0.0',
                'news_link' => 'https://typo3.org/article/typo3-v12-release',
                'news' => 'TYPO3 v12.0 is here! This release includes important security fixes...',
                'upgrading_instructions' => 'Follow the upgrade guide at https://docs.typo3.org/upgrade',
                'changes' => "Here is a list of what was fixed since 12.0.0:\n\n * 2024-12-10 abc123def45 [RELEASE] Release of TYPO3 12.0.0 (thanks to Alice Wonderland)\n * 2024-12-10 def456ghi78 [!!!][TASK] Remove context sensitive help (thanks to Mad Hatter)\n * 2024-12-10 ghi789jkl01 [!!!][FEATURE] Add PSR-14 events for flex form parsing (thanks to Cheshire Cat)\n * 2024-12-10 jkl012mno34 [!!!][TASK] Remove global jquery object window.$ (thanks to White Rabbit)\n * 2024-12-10 mno345pqr67 [SECURITY] Synchronize admin tools session with backend user session (thanks to Queen of Hearts)\n * 2024-12-10 pqr678stu90 [SECURITY] Avoid HTML injection in password recovery mail (thanks to March Hare)\n * 2024-12-10 stu901vwx23 [FEATURE] Add new dashboard widgets (thanks to Dormouse)",
            ],
        ];

        $content = $this->parser->parse($apiResponse);

        $this->assertInstanceOf(ReleaseContent::class, $content);
        $this->assertSame('12.0.0', $content->version);

        // Check all changes are parsed
        $this->assertCount(7, $content->changes);
        $this->assertContainsOnlyInstancesOf(Change::class, $content->changes);

        // Check breaking changes
        $breakingChanges = $content->getBreakingChanges();
        $this->assertCount(3, $breakingChanges);
        $this->assertInstanceOf(BreakingChange::class, $breakingChanges[0]);
        $this->assertSame('[!!!][TASK] Remove context sensitive help', $breakingChanges[0]->title);

        // Check security updates
        $securityUpdates = $content->getSecurityUpdates();
        $this->assertCount(2, $securityUpdates);
        $this->assertInstanceOf(SecurityUpdate::class, $securityUpdates[0]);
        $this->assertSame('[SECURITY] Synchronize admin tools session with backend user session', $securityUpdates[0]->title);

        // Check other properties
        $this->assertSame('https://typo3.org/article/typo3-v12-release', $content->newsLink);
        $this->assertSame('TYPO3 v12.0 is here! This release includes important security fixes...', $content->news);
    }

    #[Test]
    public function extractsSecurityAdvisoriesFromNews(): void
    {
        // Response with security advisories in news
        $apiResponse = [
            'release_notes' => [
                'version' => '12.4.31',
                'news_link' => 'https://typo3.org/article/typo3-security-release',
                'news' => 'This release fixes security issues. Please see:
                    https://typo3.org/security/advisory/typo3-core-sa-2025-011
                    https://typo3.org/security/advisory/typo3-core-sa-2025-012
                    for more details.',
                'upgrading_instructions' => 'The usual upgrading procedure applies.',
                'changes' => "Here is a list of what was fixed since 12.4.31:\n\n * 2024-12-10 abc123def45 [RELEASE] Release of TYPO3 12.4.31 (thanks to Alice Wonderland)",
            ],
        ];

        $content = $this->parser->parse($apiResponse);

        $advisories = $content->getSecurityAdvisories();
        $this->assertCount(2, $advisories);
        $this->assertSame('https://typo3.org/security/advisory/typo3-core-sa-2025-011', $advisories[0]);
        $this->assertSame('https://typo3.org/security/advisory/typo3-core-sa-2025-012', $advisories[1]);
    }
}
