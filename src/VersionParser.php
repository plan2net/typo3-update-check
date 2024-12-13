<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck;

final class VersionParser
{
    private const VERSION_PATTERN = '/^\d+\.\d+\.\d+(?:\.\d+)?$/';
    private const DECORATOR_PATTERN = '/^v|(-dev|-alpha|-beta|-rc\d*)$/i';

    /**
     * Normalizes a version string to its canonical form.
     *
     * Examples:
     *   'v12.4.1-dev' => '12.4.1'
     *   '12.4.0'      => '12.4'
     *   '12.0.0'      => '12'
     *   '12.4'        => null (invalid format)
     *   '12.9999.0'   => null (placeholder version)
     */
    public function normalize(string $version): ?string
    {
        $clean = preg_replace(self::DECORATOR_PATTERN, '', $version);

        if ($clean === null || !$this->isValidVersion($clean)) {
            return null;
        }

        $parts = array_slice(explode('.', $clean), 0, 3);

        while (count($parts) > 1 && end($parts) === '0') {
            array_pop($parts);
        }

        return $parts === ['0'] ? null : implode('.', $parts);
    }

    private function isValidVersion(string $version): bool
    {
        return preg_match(self::VERSION_PATTERN, $version) === 1
            && !str_contains($version, '9999');
    }
}
