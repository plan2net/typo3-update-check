<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Tests\Advisory;

use Plan2net\Typo3UpdateCheck\Advisory\Advisory;
use Plan2net\Typo3UpdateCheck\Advisory\AdvisoryProvider;

final class FakeAdvisoryProvider implements AdvisoryProvider
{
    /** @var array<string, list<Advisory>> keyed "previous→version" */
    private array $fixedIn = [];

    /** @var list<array{previousVersion: string, version: string}> */
    public array $calls = [];

    public bool $available = true;

    public function isAvailable(): bool
    {
        return $this->available;
    }

    /**
     * @param list<Advisory> $advisories
     */
    public function fix(string $previousVersion, string $version, array $advisories): void
    {
        $this->fixedIn["{$previousVersion}→{$version}"] = $advisories;
    }

    public function advisoriesFixedIn(string $previousVersion, string $version): array
    {
        $this->calls[] = ['previousVersion' => $previousVersion, 'version' => $version];

        return $this->fixedIn["{$previousVersion}→{$version}"] ?? [];
    }
}
