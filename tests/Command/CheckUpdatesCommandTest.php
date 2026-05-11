<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Tests\Command;

use Composer\Console\Application;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\Typo3UpdateCheck\Change\ChangeFactory;
use Plan2net\Typo3UpdateCheck\Change\ChangeParser;
use Plan2net\Typo3UpdateCheck\Command\CheckUpdatesCommand;
use Plan2net\Typo3UpdateCheck\Release\ReleaseProvider;
use Plan2net\Typo3UpdateCheck\Release\RetryPolicy;
use Symfony\Component\Console\Tester\CommandTester;

final class CheckUpdatesCommandTest extends TestCase
{
    #[Test]
    public function rendersPerVersionWarningsAndReturnsZeroWhenSomeSucceed(): void
    {
        // Use 404 (no retry) to avoid response interleaving between concurrent pool requests.
        $command = $this->commandWithMockResponses([
            new Response(200, [], $this->majorList()),
            new Response(404),
            new Response(200, [], $this->releaseContent('14.2.1')),
        ]);
        $tester = new CommandTester($command);

        $exit = $tester->execute(['from' => '14.2.0', 'to' => '14.3.0']);

        $output = $tester->getDisplay();
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('TYPO3 API has no release content for 14.3.0 yet', $output);
        $this->assertStringContainsString('composer typo3:check-updates 14.2.0 14.3.0', $output);
    }

    #[Test]
    public function returnsNonZeroOnTotalFailure(): void
    {
        $command = $this->commandWithMockResponses([
            new Response(200, [], $this->majorList()),
            new Response(503), new Response(503), new Response(503),
            new Response(503), new Response(503), new Response(503),
        ]);
        $tester = new CommandTester($command);

        $exit = $tester->execute(['from' => '14.2.0', 'to' => '14.3.0']);

        $this->assertSame(2, $exit);
        $this->assertStringContainsString('dominant failure: server_error', $tester->getDisplay());
    }

    private function commandWithMockResponses(array $responses): CheckUpdatesCommand
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::retry(RetryPolicy::decider(), static fn (): int => 0));
        $client = new Client(['handler' => $stack]);
        $provider = new ReleaseProvider($client, new ChangeParser(new ChangeFactory()));

        $command = new class ($provider) extends CheckUpdatesCommand {
            public function __construct(private readonly ReleaseProvider $injected)
            {
                parent::__construct();
            }

            protected function createReleaseProvider(): ReleaseProvider
            {
                return $this->injected;
            }
        };

        $application = new Application();
        $application->setAutoExit(false);
        $command->setApplication($application);

        return $command;
    }

    private function majorList(): string
    {
        return json_encode([
            ['version' => '14.3.0', 'date' => '2026-04-21T09:30:20+02:00', 'type' => 'regular'],
            ['version' => '14.2.1', 'date' => '2026-02-20T09:25:10+01:00', 'type' => 'regular'],
            ['version' => '14.2.0', 'date' => '2026-03-31T07:38:51+02:00', 'type' => 'regular'],
        ]);
    }

    private function releaseContent(string $version): string
    {
        return json_encode([
            'version' => $version,
            'release_notes' => ['version' => $version, 'changes' => ''],
        ]);
    }
}
