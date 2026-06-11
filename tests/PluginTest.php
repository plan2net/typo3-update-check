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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\Typo3UpdateCheck\Change\ChangeFactory;
use Plan2net\Typo3UpdateCheck\Change\ChangeParser;
use Plan2net\Typo3UpdateCheck\Http\HttpTransportException;
use Plan2net\Typo3UpdateCheck\Plugin;
use Plan2net\Typo3UpdateCheck\Release\ReleaseProvider;
use Plan2net\Typo3UpdateCheck\Tests\Http\FakeHttpClient;

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
    public function skipsCheckWhenDisabledViaEnvironmentVariable(): void
    {
        putenv('TYPO3_UPDATE_CHECK=0');

        try {
            $this->setupCurrentTypo3('12.4.31');
            $this->setupUpdatePackages(['typo3/cms-core' => '12.4.34']);

            $this->io->expects($this->never())->method('write');

            $this->plugin->checkForBreakingChanges($this->event);
        } finally {
            putenv('TYPO3_UPDATE_CHECK');
        }
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
        $http = new FakeHttpClient();
        $http->queueJson('https://get.typo3.org/api/v1/major/14/release/', (string) $majorList);
        $http->queue('https://get.typo3.org/api/v1/release/14.3.0/content', HttpTransportException::forHttpError('not found', 404));
        $provider = new ReleaseProvider($http, new ChangeParser(new ChangeFactory()));
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
        $http = new FakeHttpClient();
        $http->queueJson('https://get.typo3.org/api/v1/major/14/release/', (string) $majorList);
        $http->queue('https://get.typo3.org/api/v1/release/14.3.0/content', HttpTransportException::forHttpError('server error', 503));
        $provider = new ReleaseProvider($http, new ChangeParser(new ChangeFactory()));
        $this->plugin->setReleaseProvider($provider);

        $messages = [];
        $this->io->method('write')->willReturnCallback(function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $this->plugin->checkForBreakingChanges($this->event);

        $joined = implode("\n", $messages);
        $this->assertStringContainsString('Proceeding with update (dominant failure: server_error)', $joined);
    }

    #[Test]
    public function showsBannerAndCondensedReportOnMajorBump(): void
    {
        $this->setupCurrentTypo3('14.3.0');
        $this->setupUpdatePackages(['typo3/cms-core' => '15.0.0']);
        $this->plugin->setReleaseProvider($this->crossMajorProvider());

        $messages = [];
        $this->io->method('write')->willReturnCallback(function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $this->plugin->checkForBreakingChanges($this->event);

        $joined = implode("\n", $messages);
        $this->assertStringContainsString('Major version upgrade: 14 → 15', $joined);
        $this->assertStringContainsString('Changelog-15.html', $joined);
        $this->assertStringContainsString('2 breaking changes', $joined);
        $this->assertStringNotContainsString('Remove old API', $joined);
        $this->assertStringContainsString('[SECURITY] Fix XSS', $joined);
    }

    #[Test]
    public function promptsWithMajorUpgradeQuestionWhenMajorBumpHasNoImportantChanges(): void
    {
        $this->setupCurrentTypo3('14.3.0');
        $this->setupUpdatePackages(['typo3/cms-core' => '15.0.0']);
        $this->plugin->setReleaseProvider($this->crossMajorProvider(changes: ''));

        $this->io->method('isInteractive')->willReturn(true);
        $this->io->expects($this->once())
            ->method('askConfirmation')
            ->with($this->stringContains('major TYPO3 upgrade'))
            ->willReturn(true);

        $this->plugin->checkForBreakingChanges($this->event);
    }

    #[Test]
    public function promptsOnMajorBumpEvenWhenReleaseListFetchFails(): void
    {
        $this->setupCurrentTypo3('14.3.0');
        $this->setupUpdatePackages(['typo3/cms-core' => '15.0.0']);

        $http = new FakeHttpClient();
        $http->queueJson('https://get.typo3.org/api/v1/major/14/release/', (string) json_encode([
            ['version' => '14.3.0', 'date' => '2026-04-21T09:30:20+02:00', 'type' => 'regular'],
        ]));
        $http->queue('https://get.typo3.org/api/v1/major/15/release/', HttpTransportException::forHttpError('server error', 503));
        $this->plugin->setReleaseProvider(new ReleaseProvider($http, new ChangeParser(new ChangeFactory())));

        $this->io->method('isInteractive')->willReturn(true);
        $this->io->expects($this->once())
            ->method('askConfirmation')
            ->with($this->stringContains('major upgrade'))
            ->willReturn(true);

        $this->plugin->checkForBreakingChanges($this->event);
    }

    #[Test]
    public function promptsOnMajorBumpWhenTargetMajorListIsEmpty(): void
    {
        $this->setupCurrentTypo3('14.3.0');
        $this->setupUpdatePackages(['typo3/cms-core' => '15.0.0']);

        $http = new FakeHttpClient();
        $http->queueJson('https://get.typo3.org/api/v1/major/14/release/', (string) json_encode([
            ['version' => '14.3.0', 'date' => '2026-04-21T09:30:20+02:00', 'type' => 'regular'],
        ]));
        $http->queueJson('https://get.typo3.org/api/v1/major/15/release/', '[]');
        $this->plugin->setReleaseProvider(new ReleaseProvider($http, new ChangeParser(new ChangeFactory())));

        $this->io->method('isInteractive')->willReturn(true);
        $this->io->expects($this->once())
            ->method('askConfirmation')
            ->with($this->stringContains('major upgrade'))
            ->willReturn(true);

        $this->plugin->checkForBreakingChanges($this->event);
    }

    #[Test]
    public function proceedsWithoutPromptWhenWithinMajorListFetchFails(): void
    {
        $this->setupCurrentTypo3('14.2.0');
        $this->setupUpdatePackages(['typo3/cms-core' => '14.3.0']);

        $http = new FakeHttpClient();
        $http->queue('https://get.typo3.org/api/v1/major/14/release/', HttpTransportException::forHttpError('server error', 503));
        $this->plugin->setReleaseProvider(new ReleaseProvider($http, new ChangeParser(new ChangeFactory())));

        $this->io->method('isInteractive')->willReturn(true);
        $this->io->expects($this->never())->method('askConfirmation');

        $this->plugin->checkForBreakingChanges($this->event);
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

    private function crossMajorProvider(?string $changes = null): ReleaseProvider
    {
        $changes ??= " * 2026-09-01 abc123 [!!!][TASK] Remove old API (thanks to Alice)\n"
            . " * 2026-09-01 def456 [!!!][TASK] Drop legacy mode (thanks to Bob)\n"
            . ' * 2026-09-01 aaa111 [SECURITY] Fix XSS (thanks to Carol)';

        $http = new FakeHttpClient();
        $http->queueJson('https://get.typo3.org/api/v1/major/14/release/', (string) json_encode([
            ['version' => '14.3.0', 'date' => '2026-04-21T09:30:20+02:00', 'type' => 'regular'],
        ]));
        $http->queueJson('https://get.typo3.org/api/v1/major/15/release/', (string) json_encode([
            ['version' => '15.0.0', 'date' => '2026-09-01T08:00:00+02:00', 'type' => 'regular'],
        ]));
        $http->queueJson('https://get.typo3.org/api/v1/release/15.0.0/content', (string) json_encode([
            'version' => '15.0.0',
            'release_notes' => ['version' => '15.0.0', 'changes' => $changes],
        ]));

        return new ReleaseProvider($http, new ChangeParser(new ChangeFactory()));
    }
}
