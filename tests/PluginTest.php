<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Tests;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PrePoolCreateEvent;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Repository\RepositoryManager;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\Typo3UpdateCheck\Change\ChangeFactory;
use Plan2net\Typo3UpdateCheck\Change\ChangeParser;
use Plan2net\Typo3UpdateCheck\Plugin;
use Plan2net\Typo3UpdateCheck\Release\ReleaseProvider;
use Plan2net\Typo3UpdateCheck\Release\RetryPolicy;

final class PluginTest extends TestCase
{
    private Plugin $plugin;
    private Composer $composer;
    private IOInterface $io;
    private RepositoryManager $repositoryManager;
    private InstalledRepositoryInterface $localRepository;
    private PrePoolCreateEvent $event;

    protected function setUp(): void
    {
        $this->plugin = new Plugin();
        $this->composer = $this->createMock(Composer::class);
        $this->io = $this->createMock(IOInterface::class);
        $this->repositoryManager = $this->createMock(RepositoryManager::class);
        $this->localRepository = $this->createMock(InstalledRepositoryInterface::class);
        $this->event = $this->createMock(PrePoolCreateEvent::class);

        $this->composer->method('getRepositoryManager')->willReturn($this->repositoryManager);
        $this->repositoryManager->method('getLocalRepository')->willReturn($this->localRepository);

        $this->plugin->activate($this->composer, $this->io);
    }

    #[Test]
    public function subscribesToPrePoolCreateEvent(): void
    {
        $events = Plugin::getSubscribedEvents();

        $this->assertArrayHasKey(PluginEvents::PRE_POOL_CREATE, $events);
        $this->assertSame(['checkForBreakingChanges', 1000], $events[PluginEvents::PRE_POOL_CREATE]);
    }

    #[Test]
    public function ignoresWhenNoTypo3CoreInstalled(): void
    {
        $this->localRepository->method('findPackage')->with('typo3/cms-core', '*')->willReturn(null);

        $this->io->expects($this->never())->method('write');

        $this->plugin->checkForBreakingChanges($this->event);
    }

    #[Test]
    public function ignoresWhenNoTypo3CoreInUpdatePackages(): void
    {
        $this->setupCurrentTypo3('12.4.31');
        $this->event->method('getPackages')->willReturn([]);

        $this->io->expects($this->never())->method('write');

        $this->plugin->checkForBreakingChanges($this->event);
    }

    #[Test]
    public function ignoresDowngrades(): void
    {
        $this->setupCurrentTypo3('12.4.34');
        $this->setupUpdatePackages(['typo3/cms-core' => '12.4.31']);

        $this->io->expects($this->never())->method('write');

        $this->plugin->checkForBreakingChanges($this->event);
    }

    #[Test]
    public function rendersPartialFailureWarningWithRetrySuggestion(): void
    {
        $this->setupCurrentTypo3('14.2.0');
        $this->setupUpdatePackages(['typo3/cms-core' => '14.3.0']);

        $majorList = json_encode([
            ['version' => '14.3.0', 'date' => '2026-04-21T09:30:20+02:00', 'type' => 'regular'],
            ['version' => '14.2.0', 'date' => '2026-03-31T07:38:51+02:00', 'type' => 'regular'],
        ]);
        // Use 404 (no retry) so there is no response interleaving between concurrent pool requests.
        $mock = new MockHandler([
            new Response(200, [], $majorList),
            new Response(404),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::retry(RetryPolicy::decider(), static fn (): int => 0));
        $client = new Client(['handler' => $stack]);
        $provider = new ReleaseProvider($client, new ChangeParser(new ChangeFactory()));
        $this->plugin->setReleaseProvider($provider);

        $messages = [];
        $this->io->method('write')->willReturnCallback(function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $this->plugin->checkForBreakingChanges($this->event);

        $joined = implode("\n", $messages);
        $this->assertStringContainsString('TYPO3 API has no release content for 14.3.0 yet', $joined);
        $this->assertStringContainsString('composer typo3:check-updates 14.2.0 14.3.0', $joined);
    }

    #[Test]
    public function rendersTotalFailureWithDominantCategory(): void
    {
        $this->setupCurrentTypo3('14.2.0');
        $this->setupUpdatePackages(['typo3/cms-core' => '14.3.0']);

        $majorList = json_encode([
            ['version' => '14.3.0', 'date' => '2026-04-21T09:30:20+02:00', 'type' => 'regular'],
            ['version' => '14.2.0', 'date' => '2026-03-31T07:38:51+02:00', 'type' => 'regular'],
        ]);
        $mock = new MockHandler([
            new Response(200, [], $majorList),
            new Response(503),
            new Response(503),
            new Response(503),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::retry(RetryPolicy::decider(), static fn (): int => 0));
        $client = new Client(['handler' => $stack]);
        $provider = new ReleaseProvider($client, new ChangeParser(new ChangeFactory()));
        $this->plugin->setReleaseProvider($provider);

        $messages = [];
        $this->io->method('write')->willReturnCallback(function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $this->plugin->checkForBreakingChanges($this->event);

        $joined = implode("\n", $messages);
        $this->assertStringContainsString('Proceeding with update (dominant failure: server_error)', $joined);
    }

    private function setupCurrentTypo3(string $version): void
    {
        $package = $this->createMock(PackageInterface::class);
        $package->method('getName')->willReturn('typo3/cms-core');
        $package->method('getPrettyVersion')->willReturn($version);

        $this->localRepository->method('findPackage')->with('typo3/cms-core', '*')->willReturn($package);
    }

    /**
     * @param array<string, string> $packages
     */
    private function setupUpdatePackages(array $packages): void
    {
        $packageObjects = [];
        foreach ($packages as $name => $version) {
            $package = $this->createMock(PackageInterface::class);
            $package->method('getName')->willReturn($name);
            $package->method('getPrettyVersion')->willReturn($version);
            $packageObjects[] = $package;
        }

        $this->event->method('getPackages')->willReturn($packageObjects);
    }
}
