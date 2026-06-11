<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Advisory;

interface AdvisoryProvider
{
    /**
     * Advisories fixed in $version, i.e. affecting $previousVersion but not $version.
     * Never throws; failures yield an empty list.
     *
     * @return list<Advisory>
     */
    public function advisoriesFixedIn(string $previousVersion, string $version): array;
}
