<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Release;

final class ReleaseContentBatch
{
    /**
     * @param array<string, ReleaseContent> $results
     * @param array<string, ApiFailure>     $failures
     */
    public function __construct(
        public readonly array $results,
        public readonly array $failures,
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
        $candidates = array_keys(array_filter(
            $counts,
            static fn (int $count): bool => $count === $maxCount,
        ));

        if (count($candidates) === 1) {
            return ApiFailureCategory::from($candidates[0]);
        }

        $best = ApiFailureCategory::from($candidates[0]);
        foreach ($candidates as $value) {
            $category = ApiFailureCategory::from($value);
            if ($category->severity() > $best->severity()) {
                $best = $category;
            }
        }

        return $best;
    }
}
