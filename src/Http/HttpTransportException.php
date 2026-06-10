<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Http;

final class HttpTransportException extends \RuntimeException
{
    /**
     * @param array<string, string> $headers lowercased header names
     */
    private function __construct(
        string $message,
        public readonly ?int $statusCode,
        public readonly array $headers,
        public readonly bool $connectionError,
    ) {
        parent::__construct($message);
    }

    public static function forConnectionError(string $message): self
    {
        return new self($message, null, [], true);
    }

    /**
     * @param array<string, string> $headers lowercased header names
     */
    public static function forHttpError(string $message, int $statusCode, array $headers = []): self
    {
        return new self($message, $statusCode, $headers, false);
    }

    public static function forUnexpectedError(string $message): self
    {
        return new self($message, null, [], false);
    }
}
