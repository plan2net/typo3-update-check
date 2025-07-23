<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck;

final class VersionParser
{
    private const VERSION_PATTERN = '/^\d+(?:\.\d+){0,3}$/';
    private const DECORATOR_PATTERN = '/^v|(-dev|-alpha|-beta|-rc\d*)$/i';

    /**
     * Normalizes a version string to a canonical 3-part format (x.y.z).
     *
     * This strips pre-release decorators (e.g., -dev, -beta) and ensures
     * the returned string always includes three numeric segments.
     *
     * Examples:
     *   'v12.4.1-dev'  => '12.4.1'
     *   '12.4.0'       => '12.4.0'
     *   '12.0'         => '12.0.0'
     *   '12'           => '12.0.0'
     *   '12.4.1.2'     => '12.4.1'   (trims extra parts)
     *   '12.9999.0'    => null       (placeholder version is rejected)
     *   'invalid'      => null
     */
    public function normalize(string $version): ?string
    {
        $clean = preg_replace(self::DECORATOR_PATTERN, '', $version);
        if ($clean === null || !$this->isValidVersion($clean)) {
            return null;
        }

        $parts = explode('.', $clean);
        while (count($parts) < 3) {
            $parts[] = '0';
        }

        return implode('.', array_slice($parts, 0, 3));
    }

    private function isValidVersion(string $version): bool
    {
        return preg_match(self::VERSION_PATTERN, $version) === 1
            && !str_contains($version, '9999')
            && $version !== '0'
            && $version !== '0.0'
            && $version !== '0.0.0'
            && $version !== '0.0.0.0';
    }
}
