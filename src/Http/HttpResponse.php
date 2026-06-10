<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Http;

final class HttpResponse
{
    /**
     * @param array<string, string> $headers lowercased header names
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }
}
