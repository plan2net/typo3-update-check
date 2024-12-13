<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Change;

final class ChangeFactory
{
    public function createFrom(string $type, string $title): Change
    {
        return match (ChangeType::tryFrom($type) ?? ChangeType::REGULAR) {
            ChangeType::BREAKING => new BreakingChange($title),
            ChangeType::SECURITY => new SecurityUpdate($title),
            ChangeType::REGULAR => new RegularChange($title),
        };
    }
}
