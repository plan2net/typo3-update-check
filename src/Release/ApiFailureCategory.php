<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Release;

enum ApiFailureCategory: string
{
    case ConnectionError = 'connection_error';
    case ServerError = 'server_error';
    case NotFound = 'not_found';
    case MalformedResponse = 'malformed_response';
    case Unknown = 'unknown';

    public function severity(): int
    {
        return match ($this) {
            self::ServerError => 5,
            self::ConnectionError => 4,
            self::MalformedResponse => 3,
            self::NotFound => 2,
            self::Unknown => 1,
        };
    }
}
