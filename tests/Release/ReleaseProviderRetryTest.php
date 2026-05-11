<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Tests\Release;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\Typo3UpdateCheck\Change\ChangeFactory;
use Plan2net\Typo3UpdateCheck\Change\ChangeParser;
use Plan2net\Typo3UpdateCheck\Release\ApiFailureCategory;
use Plan2net\Typo3UpdateCheck\Release\ReleaseProvider;
use Plan2net\Typo3UpdateCheck\Release\RetryPolicy;

final class ReleaseProviderRetryTest extends TestCase
{
    /** @var int[] */
    private array $recordedDelaysMs = [];

    private function clientWithMock(MockHandler $mock): Client
    {
        $stack = HandlerStack::create($mock);

        $defaultDelay = RetryPolicy::defaultDelay();
        $recordingDelay = function (int $retries, ?\Psr\Http\Message\ResponseInterface $response = null) use ($defaultDelay): int {
            $this->recordedDelaysMs[] = $defaultDelay($retries, $response);

            return 0;
        };

        $stack->push(Middleware::retry(RetryPolicy::decider(), $recordingDelay));

        return new Client(['handler' => $stack]);
    }

    #[Test]
    public function retriesUntilSuccess(): void
    {
        $apiData = json_encode([
            'version' => '14.3.0',
            'release_notes' => ['version' => '14.3.0', 'changes' => ''],
        ]);

        $mock = new MockHandler([
            new Response(503),
            new Response(503),
            new Response(200, [], $apiData),
        ]);

        $provider = new ReleaseProvider($this->clientWithMock($mock), new ChangeParser(new ChangeFactory()));
        $batch = $provider->getReleaseContents(['14.3.0']);

        $this->assertArrayHasKey('14.3.0', $batch->results);
        $this->assertSame([], $batch->failures);
        $this->assertSame([1000, 2000], $this->recordedDelaysMs);
    }

    #[Test]
    public function exhaustsRetriesAndReportsServerError(): void
    {
        $mock = new MockHandler([
            new Response(503),
            new Response(503),
            new Response(503),
        ]);

        $provider = new ReleaseProvider($this->clientWithMock($mock), new ChangeParser(new ChangeFactory()));
        $batch = $provider->getReleaseContents(['14.3.0']);

        $this->assertArrayHasKey('14.3.0', $batch->failures);
        $this->assertSame(ApiFailureCategory::ServerError, $batch->failures['14.3.0']->category);
        $this->assertSame(503, $batch->failures['14.3.0']->statusCode);
    }

    #[Test]
    public function doesNotRetryOn404(): void
    {
        $mock = new MockHandler([new Response(404)]);

        $provider = new ReleaseProvider($this->clientWithMock($mock), new ChangeParser(new ChangeFactory()));
        $batch = $provider->getReleaseContents(['14.3.0']);

        $this->assertArrayHasKey('14.3.0', $batch->failures);
        $this->assertSame(ApiFailureCategory::NotFound, $batch->failures['14.3.0']->category);
        $this->assertSame([], $this->recordedDelaysMs);
    }

    #[Test]
    public function honorsRetryAfterCappedAtFiveSeconds(): void
    {
        $apiData = json_encode([
            'version' => '14.3.0',
            'release_notes' => ['version' => '14.3.0', 'changes' => ''],
        ]);

        $mock = new MockHandler([
            new Response(503, ['Retry-After' => '60']),
            new Response(200, [], $apiData),
        ]);

        $provider = new ReleaseProvider($this->clientWithMock($mock), new ChangeParser(new ChangeFactory()));
        $batch = $provider->getReleaseContents(['14.3.0']);

        $this->assertArrayHasKey('14.3.0', $batch->results);
        $this->assertSame([5000], $this->recordedDelaysMs);
    }

    #[Test]
    public function factoryProducedClientHasRetryMiddleware(): void
    {
        $provider = \Plan2net\Typo3UpdateCheck\ReleaseProviderFactory::create();

        $ref = new \ReflectionObject($provider);
        $clientProp = $ref->getProperty('httpClient');
        $clientProp->setAccessible(true);
        $client = $clientProp->getValue($provider);

        $clientRef = new \ReflectionObject($client);
        $configProp = $clientRef->getProperty('config');
        $configProp->setAccessible(true);
        $config = $configProp->getValue($client);

        /** @var \GuzzleHttp\HandlerStack $stack */
        $stack = $config['handler'];

        $this->assertStringContainsString('retry', (string) $stack);
    }
}
