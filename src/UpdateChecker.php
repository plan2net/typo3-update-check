<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck;

use Composer\Package\PackageInterface;

final class UpdateChecker
{
    private const TYPO3_CORE_PACKAGE = 'typo3/cms-core';

    public function __construct(
        private readonly VersionParser $versionParser,
    ) {
    }

    /**
     * @param PackageInterface[] $packages
     */
    public function findTargetVersion(array $packages, string $currentVersion): ?string
    {
        $versions = [];

        foreach ($packages as $package) {
            if ($package->getName() !== self::TYPO3_CORE_PACKAGE) {
                continue;
            }

            $normalized = $this->versionParser->normalize($package->getPrettyVersion());
            if ($normalized !== null && version_compare($normalized, $currentVersion, '>')) {
                $versions[] = $normalized;
            }
        }

        if (empty($versions)) {
            return null;
        }

        usort($versions, fn ($a, $b) => version_compare($b, $a));

        return $versions[0];
    }

    /**
     * @param string[] $versions
     *
     * @return string[]
     */
    public function filterVersionsBetween(array $versions, string $fromVersion, string $toVersion): array
    {
        return array_values(array_filter($versions, function ($version) use ($fromVersion, $toVersion) {
            return version_compare($version, $fromVersion, '>')
                && version_compare($version, $toVersion, '<=');
        }));
    }
}
