<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Tests\Change;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\Typo3UpdateCheck\Change\ChangeFactory;
use Plan2net\Typo3UpdateCheck\Change\ChangeParser;

final class ChangeParserTest extends TestCase
{
    #[Test]
    public function parsesContentWithoutFetchingAnything(): void
    {
        $parser = new ChangeParser(new ChangeFactory());

        $apiResponse = [
            'version' => '12.4.31',
            'release_notes' => [
                'version' => '12.4.31',
                'changes' => '* 2025-05-20 abc123def45 [SECURITY] Fix XSS vulnerability (thanks to John)',
                'news' => 'Security bulletins:
https://typo3.org/security/advisory/typo3-core-sa-2025-001
https://typo3.org/security/advisory/typo3-core-sa-2025-002',
            ],
        ];

        $content = $parser->parse($apiResponse);

        $this->assertSame('12.4.31', $content->version);
        $this->assertCount(1, $content->getSecurityUpdates());
        $this->assertSame([], $content->advisories);
    }
}
