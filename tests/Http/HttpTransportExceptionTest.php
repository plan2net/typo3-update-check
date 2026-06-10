<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Tests\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\Typo3UpdateCheck\Http\HttpTransportException;

final class HttpTransportExceptionTest extends TestCase
{
    #[Test]
    public function connectionErrorHasNoStatusCode(): void
    {
        $exception = HttpTransportException::forConnectionError('curl error 7');

        $this->assertTrue($exception->connectionError);
        $this->assertNull($exception->statusCode);
        $this->assertSame([], $exception->headers);
        $this->assertSame('curl error 7', $exception->getMessage());
    }

    #[Test]
    public function httpErrorCarriesStatusCodeAndHeaders(): void
    {
        $exception = HttpTransportException::forHttpError('rate limited', 429, ['retry-after' => '60']);

        $this->assertFalse($exception->connectionError);
        $this->assertSame(429, $exception->statusCode);
        $this->assertSame(['retry-after' => '60'], $exception->headers);
    }
}
