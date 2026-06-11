<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Tests\E2E;

use Composer\Config;
use Composer\IO\NullIO;
use Composer\Util\HttpDownloader;
use Composer\Util\Loop;
use PHPUnit\Framework\TestCase;
use Plan2net\Typo3UpdateCheck\Advisory\PackagistAdvisoryProvider;
use Plan2net\Typo3UpdateCheck\Change\ChangeFactory;
use Plan2net\Typo3UpdateCheck\Change\ChangeParser;
use Plan2net\Typo3UpdateCheck\Http\ComposerHttpClient;
use Plan2net\Typo3UpdateCheck\Release\ReleaseProvider;

abstract class BaseE2ETestCase extends TestCase
{
    private static int $port;
    /** @var resource */
    private static $process;
    private static ?HttpDownloader $httpDownloader = null;

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
        self::$httpDownloader = null;
        proc_terminate(self::$process);
        proc_close(self::$process);
    }

    protected function setUp(): void
    {
        self::$recordedDelaysMs = [];
        @file_get_contents(self::stubUrl('/reset'));
    }

    protected static function stubUrl(string $path): string
    {
        return 'http://127.0.0.1:' . self::$port . $path;
    }

    protected static function makeAdvisoryProvider(string $basePath): PackagistAdvisoryProvider
    {
        return new PackagistAdvisoryProvider(
            self::makeHttpClient(['Accept: application/json']),
            null,
            self::stubUrl($basePath),
        );
    }

    protected static function makeProvider(bool $withAdvisories = false): ReleaseProvider
    {
        return new ReleaseProvider(
            self::makeHttpClient(['Accept: application/json']),
            new ChangeParser(new ChangeFactory()),
            null,
            null,
            $withAdvisories ? self::makeAdvisoryProvider('/packagist') : null,
            self::stubUrl('/api/v1'),
        );
    }

    /**
     * Every request sends "Connection: close": idle keep-alive connections
     * left open between tests stall the single-threaded php -S stub on
     * Windows until they time out, failing whichever tests run meanwhile.
     *
     * @param string[] $headers
     */
    protected static function makeHttpClient(array $headers = []): ComposerHttpClient
    {
        return new ComposerHttpClient(
            self::sharedHttpDownloader(),
            [...$headers, 'Connection: close'],
            static function (int $delayMs): void {
                self::$recordedDelaysMs[] = $delayMs;
            },
        );
    }

    private static function sharedHttpDownloader(): HttpDownloader
    {
        if (self::$httpDownloader === null) {
            $config = new Config(false);
            $config->merge(['config' => ['secure-http' => false]]);
            self::$httpDownloader = (new Loop(new HttpDownloader(new NullIO(), $config)))->getHttpDownloader();
        }

        return self::$httpDownloader;
    }
}
