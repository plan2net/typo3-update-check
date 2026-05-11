<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Release;

final class ApiFailure
{
    public function __construct(
        public readonly ApiFailureCategory $category,
        public readonly string $detail,
        public readonly ?int $statusCode = null,
    ) {
    }
}
