<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Release;

use Plan2net\Typo3UpdateCheck\Advisory\AdvisoryStatus;

final class ReleaseContentBatch
{
    /**
     * @param array<string, ReleaseContent> $results
     * @param array<string, ApiFailure>     $failures
     */
    public function __construct(
        public readonly array $results,
        public readonly array $failures,
        public readonly AdvisoryStatus $advisoryStatus = AdvisoryStatus::NotAttempted,
    ) {
    }

    public function hasResults(): bool
    {
        return $this->results !== [];
    }

    public function hasFailures(): bool
    {
        return $this->failures !== [];
    }

    public function hasImportantChanges(): bool
    {
        foreach ($this->results as $content) {
            if ($content->getBreakingChanges() || $content->getSecurityUpdates() || $content->advisories !== []) {
                return true;
            }
        }

        return false;
    }

    public function dominantFailureCategory(): ?ApiFailureCategory
    {
        if ($this->failures === []) {
            return null;
        }

        $counts = [];
        foreach ($this->failures as $failure) {
            $key = $failure->category->value;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        $maxCount = max($counts);
        $best = null;
        foreach ($counts as $value => $count) {
            if ($count !== $maxCount) {
                continue;
            }

            $category = ApiFailureCategory::from($value);
            if ($best === null || $category->severity() > $best->severity()) {
                $best = $category;
            }
        }

        return $best;
    }
}
