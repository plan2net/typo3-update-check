<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Tests;

use GuzzleHttp\ClientInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\Typo3UpdateCheck\Cache\CacheInterface;
use Plan2net\Typo3UpdateCheck\Change\ChangeFactory;
use Plan2net\Typo3UpdateCheck\Change\ChangeParser;
use Plan2net\Typo3UpdateCheck\Release\ReleaseContent;
use Plan2net\Typo3UpdateCheck\Release\ReleaseProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

final class ReleaseProviderContentTest extends TestCase
{
    #[Test]
    public function fetchesAndParsesReleaseContent(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $stream = $this->createMock(StreamInterface::class);

        $stream->method('__toString')->willReturn(json_encode([
            'release_notes' => [
                'version' => '12.0.0',
                'news_link' => 'https://typo3.org/article/typo3-v12-release',
                'news' => 'TYPO3 v12.0 is here!',
                'upgrading_instructions' => 'Follow the upgrade guide',
                'changes' => "Here is a list of what was fixed since 12.0.0:\n\n * 2025-05-20 812e327a748 [RELEASE] Release of TYPO3 12.0.0 (thanks to Alice Wonderland)\n * 2025-05-20 d3fe7991704 [!!!][TASK] Remove context sensitive help (thanks to Mad Hatter)\n * 2025-05-20 befb73ea328 [!!!][FEATURE] Add PSR-14 events for flex form parsing (thanks to Cheshire Cat)\n * 2025-05-20 0bb3acf2ef4 [!!!][TASK] Remove global jquery object window.$ (thanks to White Rabbit)\n * 2025-05-19 ff01ed2cfef [SECURITY] Disallow changing system maintainer details (thanks to Queen of Hearts)\n * 2025-05-19 6ef27d7d9e4 [SECURITY] Prevent MFA bypass for backend login (thanks to March Hare)\n * 2025-05-19 4179ae4929a [SECURITY] Enforce file extension and MIME-type consistency (thanks to Dormouse)\n * 2025-05-19 8ca71b977c9 [BUGFIX] Allow zero and blank string as valid type values (thanks to Caterpillar)\n * 2025-05-16 b16c0248470 [TASK] Use DI for LoadTcaService in UpgradeController (thanks to Tweedledum)\n * 2025-05-16 51971fe0061 [BUGFIX] Set up TCA in install tool's ext_tables.php tester (thanks to Tweedledee)\n * 2025-05-16 efeb7b6156f [BUGFIX] Fix returning records to previous stage in workspaces (thanks to Mock Turtle)\n * 2025-05-16 416ebedfc29 [BUGFIX] Avoid \"-\" as first char of filename in mail spooler (thanks to Gryphon)",
            ],
        ]));

        $response->method('getBody')->willReturn($stream);

        $httpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'https://get.typo3.org/api/v1/release/12.0.0/content')
            ->willReturn($response);

        $parser = new ChangeParser(new ChangeFactory());
        $provider = new ReleaseProvider($httpClient, $parser);
        $content = $provider->getReleaseContent('12.0.0');

        $this->assertInstanceOf(ReleaseContent::class, $content);
        $this->assertSame('12.0.0', $content->version);
        $this->assertCount(12, $content->changes);
        $this->assertCount(3, $content->getBreakingChanges());
    }

    #[Test]
    public function usesReleaseContentCacheWhenAvailable(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $cache = $this->createMock(CacheInterface::class);

        $cachedData = [
            'release_notes' => [
                'version' => '12.4.20',
                'news_link' => 'https://typo3.org/article/typo3-v1242-release',
                'news' => 'TYPO3 v12.4.20 is here!',
                'upgrading_instructions' => 'Follow the upgrade guide',
                'changes' => "Here is a list of what was fixed since 12.4.19:\n\n * 2024-09-10 812e327a748 [RELEASE] Release of TYPO3 12.4.20",
            ],
        ];

        $cache->expects($this->once())
            ->method('get')
            ->with('content-12.4.20')
            ->willReturn($cachedData);

        $cache->expects($this->never())
            ->method('set');

        $httpClient->expects($this->never())
            ->method('request');

        $parser = new ChangeParser(new ChangeFactory());
        $provider = new ReleaseProvider($httpClient, $parser, $cache);
        $content = $provider->getReleaseContent('12.4.20');

        $this->assertInstanceOf(ReleaseContent::class, $content);
        $this->assertSame('12.4.20', $content->version);
    }

    #[Test]
    public function fetchesAndCachesReleaseContentWhenCacheMisses(): void
    {
        $httpClient = $this->createMock(ClientInterface::class);
        $response = $this->createMock(ResponseInterface::class);
        $stream = $this->createMock(StreamInterface::class);
        $cache = $this->createMock(CacheInterface::class);

        $apiData = [
            'release_notes' => [
                'version' => '12.4.20',
                'news_link' => 'https://typo3.org/article/typo3-v1242-release',
                'news' => 'TYPO3 v12.4.20 is here!',
                'upgrading_instructions' => 'Follow the upgrade guide',
                'changes' => "Here is a list of what was fixed since 12.4.19:\n\n * 2024-09-10 812e327a748 [RELEASE] Release of TYPO3 12.4.20",
            ],
        ];

        $stream->method('__toString')->willReturn(json_encode($apiData));
        $response->method('getBody')->willReturn($stream);

        $cache->expects($this->once())
            ->method('get')
            ->with('content-12.4.20')
            ->willReturn(null);

        $cache->expects($this->once())
            ->method('set')
            ->with('content-12.4.20', $apiData);

        $httpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'https://get.typo3.org/api/v1/release/12.4.20/content')
            ->willReturn($response);

        $parser = new ChangeParser(new ChangeFactory());
        $provider = new ReleaseProvider($httpClient, $parser, $cache);
        $content = $provider->getReleaseContent('12.4.20');

        $this->assertInstanceOf(ReleaseContent::class, $content);
        $this->assertSame('12.4.20', $content->version);
    }
}
