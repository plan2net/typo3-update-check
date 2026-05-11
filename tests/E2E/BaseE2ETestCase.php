<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Tests\E2E;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use PHPUnit\Framework\TestCase;
use Plan2net\Typo3UpdateCheck\Change\ChangeFactory;
use Plan2net\Typo3UpdateCheck\Change\ChangeParser;
use Plan2net\Typo3UpdateCheck\Release\ReleaseProvider;
use Plan2net\Typo3UpdateCheck\Release\RetryPolicy;
use Psr\Http\Message\ResponseInterface;

abstract class BaseE2ETestCase extends TestCase
{
    private static int $port;
    /** @var resource */
    private static $process;

    /** @var int[] */
    protected static array $recordedDelaysMs = [];

    public static function setUpBeforeClass(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new \RuntimeException("Cannot bind socket: {$errstr}");
        }
        $address = stream_socket_get_name($socket, false);
        self::$port = (int) substr($address, strrpos($address, ':') + 1);
        fclose($socket);

        $descriptors = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        self::$process = proc_open(
            ['php', '-S', '127.0.0.1:' . self::$port, 'stub/server.php'],
            $descriptors,
            $pipes,
            __DIR__,
        );

        $deadline = microtime(true) + 3.0;
        do {
            usleep(50_000);
            $socket = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.1);
        } while ($socket === false && microtime(true) < $deadline);

        if ($socket !== false) {
            fclose($socket);
        }
    }

    public static function tearDownAfterClass(): void
    {
        proc_terminate(self::$process);
        proc_close(self::$process);
    }

    protected function setUp(): void
    {
        self::$recordedDelaysMs = [];
    }

    protected static function makeProvider(bool $withRetry = false): ReleaseProvider
    {
        $stack = HandlerStack::create();

        if ($withRetry) {
            $defaultDelay = RetryPolicy::defaultDelay();
            $recordingDelay = static function (
                int $retries,
                ?ResponseInterface $response = null,
            ) use ($defaultDelay): int {
                self::$recordedDelaysMs[] = $defaultDelay($retries, $response);

                return 0;
            };
            $stack->push(Middleware::retry(RetryPolicy::decider(), $recordingDelay));
        }

        return new ReleaseProvider(
            new Client(['handler' => $stack]),
            new ChangeParser(new ChangeFactory()),
            null,
            null,
            'http://127.0.0.1:' . self::$port . '/api/v1',
        );
    }
}
