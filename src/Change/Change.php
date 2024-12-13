<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Change;

abstract class Change
{
    public function __construct(
        public readonly string $title,
    ) {
    }
}
