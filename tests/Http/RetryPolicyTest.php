<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Tests\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\Typo3UpdateCheck\Http\HttpTransportException;
use Plan2net\Typo3UpdateCheck\Http\RetryPolicy;

final class RetryPolicyTest extends TestCase
{
    #[Test]
    public function retriesOn429Only(): void
    {
        $this->assertTrue(RetryPolicy::shouldRetry(HttpTransportException::forHttpError('rate limited', 429)));
        $this->assertFalse(RetryPolicy::shouldRetry(HttpTransportException::forHttpError('server error', 503)));
        $this->assertFalse(RetryPolicy::shouldRetry(HttpTransportException::forHttpError('not found', 404)));
        $this->assertFalse(RetryPolicy::shouldRetry(HttpTransportException::forConnectionError('refused')));
    }

    #[Test]
    public function delayIsExponentialPerAttempt(): void
    {
        $this->assertSame(1000, RetryPolicy::delayMs(1, []));
        $this->assertSame(2000, RetryPolicy::delayMs(2, []));
    }

    #[Test]
    public function delayHonorsNumericRetryAfterHeader(): void
    {
        $this->assertSame(2000, RetryPolicy::delayMs(1, ['retry-after' => '2']));
    }

    #[Test]
    public function delayCapsRetryAfterAtFiveSeconds(): void
    {
        $this->assertSame(5000, RetryPolicy::delayMs(1, ['retry-after' => '60']));
    }

    #[Test]
    public function delayHandlesHttpDateRetryAfter(): void
    {
        $date = gmdate('D, d M Y H:i:s \G\M\T', time() + 2);

        $delayMs = RetryPolicy::delayMs(1, ['retry-after' => $date]);

        $this->assertGreaterThanOrEqual(1000, $delayMs);
        $this->assertLessThanOrEqual(2000, $delayMs);
    }
}
