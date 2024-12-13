<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Release;

use Plan2net\Typo3UpdateCheck\Change\BreakingChange;
use Plan2net\Typo3UpdateCheck\Change\Change;
use Plan2net\Typo3UpdateCheck\Change\SecurityUpdate;

final class ReleaseContent
{
    /**
     * @param Change[] $changes
     */
    public function __construct(
        public readonly string $version,
        public readonly array $changes,
        public readonly ?string $newsLink,
        public readonly ?string $news,
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
