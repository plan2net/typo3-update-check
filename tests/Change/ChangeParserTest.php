<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Tests\Change;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\Typo3UpdateCheck\Change\ChangeFactory;
use Plan2net\Typo3UpdateCheck\Change\ChangeParser;
use Plan2net\Typo3UpdateCheck\Security\SecurityBulletinFetcherInterface;

final class ChangeParserTest extends TestCase
{
    #[Test]
    public function parsesFetchesSecuritySeveritiesWhenBulletinFetcherProvided(): void
    {
        $changeFactory = new ChangeFactory();
        $bulletinFetcher = $this->createMock(SecurityBulletinFetcherInterface::class);

        $bulletinFetcher->expects($this->once())
            ->method('fetchSeverities')
            ->with([
                'https://typo3.org/security/advisory/typo3-core-sa-2025-001',
                'https://typo3.org/security/advisory/typo3-core-sa-2025-002',
            ])
            ->willReturn(['High' => 1, 'Low' => 1]);

        $parser = new ChangeParser($changeFactory, $bulletinFetcher);

        $apiResponse = [
            'version' => '12.4.31',
            'release_notes' => [
                'version' => '12.4.31',
                'changes' => '* 2025-05-20 [SECURITY] Fix XSS vulnerability (thanks to John)',
                'news' => 'Security bulletins:
https://typo3.org/security/advisory/typo3-core-sa-2025-001
https://typo3.org/security/advisory/typo3-core-sa-2025-002',
            ],
        ];

        $content = $parser->parse($apiResponse);

        $this->assertEquals(['High' => 1, 'Low' => 1], $content->securitySeverities);
    }
}
