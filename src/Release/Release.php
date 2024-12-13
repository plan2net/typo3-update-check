<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Release;

final class Release
{
    public function __construct(
        public readonly string $version,
        public readonly string $type,
        public readonly \DateTimeImmutable $date,
    ) {
    }
}
