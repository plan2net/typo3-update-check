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
use Plan2net\Typo3UpdateCheck\Plugin;

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

        // Wire up the mocks
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
