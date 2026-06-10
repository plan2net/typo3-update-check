<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Tests\Command;

use Composer\Console\Application;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\Typo3UpdateCheck\Change\ChangeFactory;
use Plan2net\Typo3UpdateCheck\Change\ChangeParser;
use Plan2net\Typo3UpdateCheck\Command\CheckUpdatesCommand;
use Plan2net\Typo3UpdateCheck\Http\HttpTransportException;
use Plan2net\Typo3UpdateCheck\Release\ReleaseProvider;
use Plan2net\Typo3UpdateCheck\Tests\Http\FakeHttpClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Tester\CommandTester;

final class CheckUpdatesCommandTest extends TestCase
{
    #[Test]
    public function rendersPerVersionWarningsAndReturnsZeroWhenSomeSucceed(): void
    {
        $http = $this->fakeHttp('14.2.1');
        $http->queue('https://get.typo3.org/api/v1/release/14.3.0/content', HttpTransportException::forHttpError('not found', 404));
        $command = $this->commandWithFakeHttp($http);
        $tester = new CommandTester($command);

        $exit = $tester->execute(['from' => '14.2.0', 'to' => '14.3.0']);

        $output = $tester->getDisplay();
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('TYPO3 API has no release content for 14.3.0 yet', $output);
        $this->assertStringContainsString('composer typo3:check-updates 14.2.0 14.3.0', $output);
    }

    #[Test]
    public function runsSilentlyWithoutPromptWhenBothVersionsGivenAndValid(): void
    {
        $command = $this->commandWithFakeHttp($this->fakeHttp('14.2.1'));
        $tester = new CommandTester($command);

        $exit = $tester->execute(['from' => '14.2.0', 'to' => '14.2.1']);

        $output = $tester->getDisplay();
        $this->assertSame(0, $exit);
        $this->assertStringNotContainsString('[Y/n]', $output);
        $this->assertStringNotContainsString('(installed)', $output);
    }

    #[Test]
    public function returnsNonZeroOnTotalFailure(): void
    {
        $http = $this->fakeHttp();
        $http->queue('https://get.typo3.org/api/v1/release/14.2.1/content', HttpTransportException::forHttpError('server error', 503));
        $http->queue('https://get.typo3.org/api/v1/release/14.3.0/content', HttpTransportException::forHttpError('server error', 503));
        $command = $this->commandWithFakeHttp($http);
        $tester = new CommandTester($command);

        $exit = $tester->execute(['from' => '14.2.0', 'to' => '14.3.0']);

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('dominant failure: server_error', $tester->getDisplay());
    }

    #[Test]
    public function rejectsUnknownTargetVersionWhenNonInteractive(): void
    {
        $command = $this->commandWithFakeHttp($this->fakeHttp());
        $tester = new CommandTester($command);

        $exit = $tester->execute(['from' => '14.2.0', 'to' => '14.9.9'], ['interactive' => false]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('14.9.9 is not a released TYPO3 version', $tester->getDisplay());
    }

    #[Test]
    public function offersLatestForUnknownTargetAndProceedsWhenConfirmed(): void
    {
        $command = $this->commandWithFakeHttp($this->fakeHttp('14.2.1', '14.3.0'));
        $tester = new CommandTester($command);
        $tester->setInputs(['yes']);

        $exit = $tester->execute(['from' => '14.2.0', 'to' => '14.9.9']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Use the latest (14.3.0)', $tester->getDisplay());
    }

    #[Test]
    public function returnsErrorWhenLatestOfferIsDeclined(): void
    {
        $command = $this->commandWithFakeHttp($this->fakeHttp());
        $tester = new CommandTester($command);
        $tester->setInputs(['no']);

        $exit = $tester->execute(['from' => '14.2.0', 'to' => '14.9.9']);

        $this->assertSame(1, $exit);
    }

    #[Test]
    public function rejectsCurrentVersionThatDoesNotExist(): void
    {
        $command = $this->commandWithFakeHttp($this->fakeHttp());
        $tester = new CommandTester($command);

        $exit = $tester->execute(['from' => '14.2.5', 'to' => '14.3.0']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('14.2.5 is not a released TYPO3 version', $tester->getDisplay());
    }

    #[Test]
    public function detectsInstalledVersionAndOffersLatestWhenNoArguments(): void
    {
        $command = $this->commandWithFakeHttp($this->fakeHttp('14.2.1', '14.3.0'), installedVersion: '14.2.0');
        $tester = new CommandTester($command);
        $tester->setInputs(['yes']);

        $exit = $tester->execute([]);

        $output = $tester->getDisplay();
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Check 14.2.0 (installed) → 14.3.0 (latest)', $output);
    }

    #[Test]
    public function defaultsTargetToLatestWithoutClaimingInstalledWhenOnlyCurrentGiven(): void
    {
        $command = $this->commandWithFakeHttp($this->fakeHttp('14.2.1', '14.3.0'), installedVersion: '14.0.0');
        $tester = new CommandTester($command);
        $tester->setInputs(['yes']);

        $exit = $tester->execute(['from' => '14.2.0']);

        $output = $tester->getDisplay();
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Check 14.2.0 → 14.3.0 (latest)', $output);
        $this->assertStringNotContainsString('(installed)', $output);
    }

    #[Test]
    public function reportsAlreadyOnLatestWhenInstalledEqualsLatest(): void
    {
        $command = $this->commandWithFakeHttp($this->fakeHttp(), installedVersion: '14.3.0');
        $tester = new CommandTester($command);

        $exit = $tester->execute([]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Already on the latest release (14.3.0)', $tester->getDisplay());
    }

    #[Test]
    public function requiresExplicitVersionsWhenNonInteractiveAndNoArguments(): void
    {
        $command = $this->commandWithFakeHttp(new FakeHttpClient(), installedVersion: '14.2.0');
        $tester = new CommandTester($command);

        $exit = $tester->execute([], ['interactive' => false]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Provide a current and target version', $tester->getDisplay());
    }

    #[Test]
    public function errorsWhenInstalledVersionCannotBeDetected(): void
    {
        $command = $this->commandWithFakeHttp(new FakeHttpClient(), installedVersion: null);
        $tester = new CommandTester($command);

        $exit = $tester->execute([]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Could not detect an installed typo3/cms-core', $tester->getDisplay());
    }

    private function commandWithFakeHttp(FakeHttpClient $http, ?string $installedVersion = null): CheckUpdatesCommand
    {
        $provider = new ReleaseProvider($http, new ChangeParser(new ChangeFactory()));

        $command = new #[AsCommand(name: 'typo3:check-updates')] class ($provider, $installedVersion) extends CheckUpdatesCommand {
            public function __construct(
                private readonly ReleaseProvider $injected,
                private readonly ?string $installedVersion,
            ) {
                parent::__construct();
            }

            protected function createReleaseProvider(): ReleaseProvider
            {
                return $this->injected;
            }

            protected function installedCoreVersion(): ?string
            {
                return $this->installedVersion;
            }
        };

        $application = new Application();
        $application->setAutoExit(false);
        $command->setApplication($application);

        return $command;
    }

    private function fakeHttp(string ...$contentVersions): FakeHttpClient
    {
        $http = new FakeHttpClient();
        $http->queueJson('https://get.typo3.org/api/v1/major/14/release/', $this->majorList());
        foreach ($contentVersions as $version) {
            $http->queueJson(
                'https://get.typo3.org/api/v1/release/' . $version . '/content',
                $this->releaseContent($version),
            );
        }

        return $http;
    }

    private function majorList(): string
    {
        return (string) json_encode([
            ['version' => '14.3.0', 'date' => '2026-04-21T09:30:20+02:00', 'type' => 'regular'],
            ['version' => '14.2.1', 'date' => '2026-02-20T09:25:10+01:00', 'type' => 'regular'],
            ['version' => '14.2.0', 'date' => '2026-03-31T07:38:51+02:00', 'type' => 'regular'],
        ]);
    }

    private function releaseContent(string $version): string
    {
        return (string) json_encode([
            'version' => $version,
            'release_notes' => ['version' => $version, 'changes' => ''],
        ]);
    }
}
