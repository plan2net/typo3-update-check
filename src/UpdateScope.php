<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck;

/**
 * Expects versions already normalized by VersionParser (x.y.z).
 */
final class UpdateScope
{
    public readonly int $fromMajor;
    public readonly int $toMajor;

    public function __construct(
        public readonly string $fromVersion,
        public readonly string $toVersion,
    ) {
        $this->fromMajor = (int) explode('.', $fromVersion)[0];
        $this->toMajor = (int) explode('.', $toVersion)[0];
    }

    public function isMajorBump(): bool
    {
        return $this->toMajor > $this->fromMajor;
    }

    public function majorsCrossed(): int
    {
        return max(0, $this->toMajor - $this->fromMajor);
    }
}
