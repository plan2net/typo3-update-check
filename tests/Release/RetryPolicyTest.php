<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Tests\Release;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\Typo3UpdateCheck\Release\RetryPolicy;

final class RetryPolicyTest extends TestCase
{
    #[Test]
    public function deciderRetriesOnConnectException(): void
    {
        $decider = RetryPolicy::decider();
        $request = new Request('GET', 'http://x');

        $this->assertTrue(
            $decider(0, $request, null, new ConnectException('refused', $request)),
        );
    }

    #[Test]
    public function deciderRetriesOn5xx(): void
    {
        $decider = RetryPolicy::decider();

        $this->assertTrue($decider(0, new Request('GET', 'http://x'), new Response(500)));
        $this->assertTrue($decider(0, new Request('GET', 'http://x'), new Response(503)));
    }

    #[Test]
    public function deciderRetriesOn429(): void
    {
        $decider = RetryPolicy::decider();

        $this->assertTrue($decider(0, new Request('GET', 'http://x'), new Response(429)));
    }

    #[Test]
    public function deciderDoesNotRetryOn4xxOtherThan429(): void
    {
        $decider = RetryPolicy::decider();

        $this->assertFalse($decider(0, new Request('GET', 'http://x'), new Response(400)));
        $this->assertFalse($decider(0, new Request('GET', 'http://x'), new Response(404)));
    }

    #[Test]
    public function deciderStopsAfterMaxRetries(): void
    {
        $decider = RetryPolicy::decider();

        $this->assertFalse($decider(2, new Request('GET', 'http://x'), new Response(503)));
    }

    #[Test]
    public function defaultDelayIsExponentialInMilliseconds(): void
    {
        $delay = RetryPolicy::defaultDelay();

        $this->assertSame(1000, $delay(1, null));
        $this->assertSame(2000, $delay(2, null));
    }

    #[Test]
    public function defaultDelayHonorsRetryAfterSecondsHeader(): void
    {
        $delay = RetryPolicy::defaultDelay();

        $this->assertSame(2000, $delay(1, new Response(503, ['Retry-After' => '2'])));
    }

    #[Test]
    public function defaultDelayCapsRetryAfterAtFiveSeconds(): void
    {
        $delay = RetryPolicy::defaultDelay();

        $this->assertSame(5000, $delay(1, new Response(503, ['Retry-After' => '60'])));
    }
}
