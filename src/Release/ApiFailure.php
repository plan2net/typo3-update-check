<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Release;

final readonly class ApiFailure
{
    public function __construct(
        public ApiFailureCategory $category,
        public string $detail,
        public ?int $statusCode = null,
    ) {
    }
}
