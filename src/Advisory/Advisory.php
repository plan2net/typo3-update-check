<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Advisory;

final class Advisory
{
    public function __construct(
        public readonly string $packageName,
        public readonly string $title,
        public readonly ?string $cve,
        public readonly ?string $severity,
        public readonly string $link,
        public readonly string $affectedVersions,
    ) {
    }
}
