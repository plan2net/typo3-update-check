<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Release;

use Plan2net\Typo3UpdateCheck\Advisory\Advisory;
use Plan2net\Typo3UpdateCheck\Change\BreakingChange;
use Plan2net\Typo3UpdateCheck\Change\Change;
use Plan2net\Typo3UpdateCheck\Change\SecurityUpdate;

final class ReleaseContent
{
    /**
     * @param Change[] $changes
     * @param list<Advisory> $advisories
     */
    public function __construct(
        public readonly string $version,
        public readonly array $changes,
        public readonly ?string $newsLink,
        public readonly ?string $news,
        public readonly array $advisories = [],
    ) {
    }

    /**
     * @return BreakingChange[]
     */
    public function getBreakingChanges(): array
    {
        return array_values(array_filter(
            $this->changes,
            fn (Change $change) => $change instanceof BreakingChange
        ));
    }

    /**
     * @return SecurityUpdate[]
     */
    public function getSecurityUpdates(): array
    {
        return array_values(array_filter(
            $this->changes,
            fn (Change $change) => $change instanceof SecurityUpdate
        ));
    }

    /**
     * @return array<string, int> lowercase severity => count, ordered critical, high, medium, low
     */
    public function getSeverityCounts(): array
    {
        $counts = [];
        foreach (['critical', 'high', 'medium', 'low'] as $severity) {
            $countForSeverity = count(array_filter(
                $this->advisories,
                static fn (Advisory $advisory): bool => $advisory->severity === $severity,
            ));
            if ($countForSeverity > 0) {
                $counts[$severity] = $countForSeverity;
            }
        }

        return $counts;
    }

    public function withAdvisories(Advisory ...$advisories): self
    {
        return new self(
            version: $this->version,
            changes: $this->changes,
            newsLink: $this->newsLink,
            news: $this->news,
            advisories: array_values($advisories),
        );
    }

    /**
     * @return string[]
     */
    public function getSecurityAdvisories(): array
    {
        if ($this->news === null) {
            return [];
        }

        preg_match_all(
            '#https://typo3\.org/security/advisory/\S+#',
            $this->news,
            $matches
        );

        /** @phpstan-ignore-next-line */
        return $matches[0] ?? [];
    }
}
