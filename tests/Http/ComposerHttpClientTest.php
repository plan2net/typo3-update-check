<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Tests\Http;

use Composer\Downloader\TransportException;
use Composer\Util\Http\Response as ComposerResponse;
use Composer\Util\HttpDownloader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\Typo3UpdateCheck\Http\ComposerHttpClient;
use Plan2net\Typo3UpdateCheck\Http\HttpResponse;
use Plan2net\Typo3UpdateCheck\Http\HttpTransportException;

use function React\Promise\reject;
use function React\Promise\resolve;

final class ComposerHttpClientTest extends TestCase
{
    /** @var list<int> */
    private array $recordedDelaysMs = [];

    private function makeClient(HttpDownloader $downloader, array $headers = []): ComposerHttpClient
    {
        return new ComposerHttpClient($downloader, $headers, function (int $delayMs): void {
            $this->recordedDelaysMs[] = $delayMs;
        });
    }

    private function composerResponse(string $url, int $status, string $body): ComposerResponse
    {
        return new ComposerResponse(['url' => $url], $status, ['HTTP/1.1 ' . $status . ' OK', 'Content-Type: application/json'], $body);
    }

    private function transportError(int $statusCode, array $headerLines = []): TransportException
    {
        $exception = new TransportException('HTTP error ' . $statusCode);
        $exception->setStatusCode($statusCode);
        $exception->setHeaders($headerLines);

        return $exception;
    }

    #[Test]
    public function getReturnsTranslatedResponse(): void
    {
        $downloader = $this->createMock(HttpDownloader::class);
        $downloader->method('get')
            ->willReturn($this->composerResponse('http://x', 200, '{"ok":true}'));

        $response = $this->makeClient($downloader)->get('http://x');

        $this->assertSame(200, $response->statusCode);
        $this->assertSame('{"ok":true}', $response->body);
        $this->assertSame('application/json', $response->headers['content-type']);
    }

    #[Test]
    public function getSendsComposerShapedOptions(): void
    {
        $downloader = $this->createMock(HttpDownloader::class);
        $downloader->expects($this->once())
            ->method('get')
            ->with('http://x', $this->callback(function (array $options): bool {
                return $options['retry-auth-failure'] === false
                    && $options['http']['timeout'] === 10
                    && $options['http']['header'] === ['Accept: application/json'];
            }))
            ->willReturn($this->composerResponse('http://x', 200, '{}'));

        $this->makeClient($downloader, ['Accept: application/json'])->get('http://x');
    }

    #[Test]
    public function getTranslatesHttpErrorWithoutRetry(): void
    {
        $downloader = $this->createMock(HttpDownloader::class);
        $downloader->expects($this->once())
            ->method('get')
            ->willThrowException($this->transportError(404));

        try {
            $this->makeClient($downloader)->get('http://x');
            $this->fail('Expected HttpTransportException');
        } catch (HttpTransportException $exception) {
            $this->assertSame(404, $exception->statusCode);
            $this->assertFalse($exception->connectionError);
        }

        $this->assertSame([], $this->recordedDelaysMs);
    }

    #[Test]
    public function getTranslatesConnectionError(): void
    {
        $downloader = $this->createMock(HttpDownloader::class);
        $downloader->method('get')
            ->willThrowException(new TransportException('curl error 7 while downloading'));

        try {
            $this->makeClient($downloader)->get('http://x');
            $this->fail('Expected HttpTransportException');
        } catch (HttpTransportException $exception) {
            $this->assertTrue($exception->connectionError);
            $this->assertNull($exception->statusCode);
        }
    }

    #[Test]
    public function getRetries429ThenSucceeds(): void
    {
        $downloader = $this->createMock(HttpDownloader::class);
        $calls = 0;
        $downloader->method('get')
            ->willReturnCallback(function () use (&$calls) {
                ++$calls;
                if ($calls === 1) {
                    throw $this->transportError(429, ['Retry-After: 2']);
                }

                return $this->composerResponse('http://x', 200, '{}');
            });

        $response = $this->makeClient($downloader)->get('http://x');

        $this->assertSame(200, $response->statusCode);
        $this->assertSame([2000], $this->recordedDelaysMs);
        $this->assertSame(2, $calls);
    }

    #[Test]
    public function getGivesUpAfterTwo429Retries(): void
    {
        $downloader = $this->createMock(HttpDownloader::class);
        $downloader->expects($this->exactly(3))
            ->method('get')
            ->willThrowException($this->transportError(429));

        $this->expectException(HttpTransportException::class);

        try {
            $this->makeClient($downloader)->get('http://x');
        } finally {
            $this->assertSame([1000, 2000], $this->recordedDelaysMs);
        }
    }

    #[Test]
    public function getManyReturnsMixedOutcomesKeyedLikeInput(): void
    {
        $downloader = $this->createMock(HttpDownloader::class);
        $downloader->method('add')
            ->willReturnCallback(function (string $url) {
                return $url === 'http://ok'
                    ? resolve($this->composerResponse($url, 200, '{"a":1}'))
                    : reject($this->transportError(404));
            });

        $outcomes = $this->makeClient($downloader)->getMany(['good' => 'http://ok', 'bad' => 'http://missing']);

        $this->assertInstanceOf(HttpResponse::class, $outcomes['good']);
        $this->assertInstanceOf(HttpTransportException::class, $outcomes['bad']);
        $this->assertSame(404, $outcomes['bad']->statusCode);
        $this->assertSame([], $this->recordedDelaysMs);
    }

    #[Test]
    public function getManyRetriesOnly429Failures(): void
    {
        $downloader = $this->createMock(HttpDownloader::class);
        $attemptsPerUrl = [];
        $downloader->method('add')
            ->willReturnCallback(function (string $url) use (&$attemptsPerUrl) {
                $attemptsPerUrl[$url] = ($attemptsPerUrl[$url] ?? 0) + 1;
                if ($url === 'http://flaky' && $attemptsPerUrl[$url] === 1) {
                    return reject($this->transportError(429));
                }
                if ($url === 'http://broken') {
                    return reject($this->transportError(503));
                }

                return resolve($this->composerResponse($url, 200, '{}'));
            });

        $outcomes = $this->makeClient($downloader)->getMany([
            'flaky' => 'http://flaky',
            'broken' => 'http://broken',
            'fine' => 'http://fine',
        ]);

        $this->assertInstanceOf(HttpResponse::class, $outcomes['flaky']);
        $this->assertInstanceOf(HttpTransportException::class, $outcomes['broken']);
        $this->assertInstanceOf(HttpResponse::class, $outcomes['fine']);
        $this->assertSame(2, $attemptsPerUrl['http://flaky']);
        $this->assertSame(1, $attemptsPerUrl['http://broken']);
        $this->assertSame([1000], $this->recordedDelaysMs);
    }

    #[Test]
    public function getManyTranslatesSynchronousAddFailures(): void
    {
        $downloader = $this->createMock(HttpDownloader::class);
        $downloader->method('add')
            ->willReturnCallback(function (string $url) {
                if ($url === 'http://sync-fail') {
                    throw new \LogicException('you must use the HttpDownloader instance which is part of a Composer\Loop instance');
                }

                return resolve($this->composerResponse($url, 200, '{}'));
            });

        $outcomes = $this->makeClient($downloader)->getMany([
            'bad' => 'http://sync-fail',
            'good' => 'http://ok',
        ]);

        $this->assertInstanceOf(HttpTransportException::class, $outcomes['bad']);
        $this->assertFalse($outcomes['bad']->connectionError);
        $this->assertInstanceOf(HttpResponse::class, $outcomes['good']);
    }

    #[Test]
    public function getManyTranslatesWaitFailureForUnresolvedUrls(): void
    {
        $downloader = $this->createMock(HttpDownloader::class);
        $downloader->method('add')->willReturn(new \React\Promise\Promise(static function (): void {
            // never settles synchronously — outcome depends on wait()
        }));
        $downloader->method('wait')->willThrowException(new \RuntimeException('event loop died'));

        $outcomes = $this->makeClient($downloader)->getMany(['only' => 'http://x']);

        $this->assertInstanceOf(HttpTransportException::class, $outcomes['only']);
        $this->assertSame('event loop died', $outcomes['only']->getMessage());
    }

    #[Test]
    public function getManyGuaranteesAnOutcomePerKeyEvenIfAPromiseNeverSettles(): void
    {
        $downloader = $this->createMock(HttpDownloader::class);
        $downloader->method('add')->willReturn(new \React\Promise\Promise(static function (): void {
            // never settles synchronously
        }));
        // wait() succeeds but the promise never settled — outcome must still exist
        $outcomes = $this->makeClient($downloader)->getMany(['stuck' => 'http://x']);

        $this->assertInstanceOf(HttpTransportException::class, $outcomes['stuck']);
    }

    #[Test]
    public function getManyWithNoUrlsReturnsEmptyArrayWithoutWaiting(): void
    {
        $downloader = $this->createMock(HttpDownloader::class);
        $downloader->expects($this->never())->method('add');
        $downloader->expects($this->never())->method('wait');

        $this->assertSame([], $this->makeClient($downloader)->getMany([]));
    }

    #[Test]
    public function getManyHonorsRetryAfterHeaderInRetryRound(): void
    {
        $downloader = $this->createMock(HttpDownloader::class);
        $attempts = 0;
        $downloader->method('add')
            ->willReturnCallback(function () use (&$attempts) {
                ++$attempts;
                if ($attempts === 1) {
                    return reject($this->transportError(429, ['Retry-After: 3']));
                }

                return resolve($this->composerResponse('http://x', 200, '{}'));
            });

        $outcomes = $this->makeClient($downloader)->getMany(['key' => 'http://x']);

        $this->assertInstanceOf(HttpResponse::class, $outcomes['key']);
        $this->assertSame([3000], $this->recordedDelaysMs);
    }
}
