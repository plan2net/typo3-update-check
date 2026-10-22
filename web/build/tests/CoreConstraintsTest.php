<?php

declare(strict_types=1);

namespace Typo3UpdateCheckWeb\Build\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Typo3UpdateCheckWeb\Build\CoreConstraints;

final class CoreConstraintsTest extends TestCase
{
    #[Test]
    public function expandsPackagistsMinifiedVersionListIntoTheLibraryRangesOfEachRelease(): void
    {
        $fetched = [];
        $fetch = function (string $url) use (&$fetched): ?string {
            $fetched[] = $url;

            return str_ends_with($url, '/typo3/cms-core.json')
                ? (string) json_encode(['minified' => 'composer/2.0', 'packages' => ['typo3/cms-core' => [
                    ['version' => 'v13.4.37', 'require' => ['php' => '^8.2', 'symfony/expression-language' => '^7.4.21']],
                    ['version' => 'v13.4.36', 'require' => ['php' => '^8.2', 'symfony/expression-language' => '^7.4']],
                    ['version' => 'v13.4.35'],
                    ['version' => 'v13.4.34', 'require' => '__unset'],
                ]]])
                : null;
        };

        $constraints = (new CoreConstraints($fetch))->forPackages(['typo3/cms-core', 'typo3/cms-backend']);

        $this->assertSame([
            '13.4.35' => ['symfony/expression-language' => ['^7.4']],
            '13.4.36' => ['symfony/expression-language' => ['^7.4']],
            '13.4.37' => ['symfony/expression-language' => ['^7.4.21']],
        ], $constraints);
        $this->assertSame([
            'https://repo.packagist.org/p2/typo3/cms-core.json',
            'https://repo.packagist.org/p2/typo3/cms-backend.json',
        ], $fetched);
    }

    #[Test]
    public function collectsTheRangeOfEveryCorePackageThatRequiresTheLibrary(): void
    {
        $fetch = static fn (string $url): string => (string) json_encode(['packages' => ['typo3/' . basename($url, '.json') => [
            ['version' => 'v13.4.37', 'require' => ['symfony/yaml' => str_contains($url, 'core') ? '^7.4.21' : '^7.4']],
        ]]]);

        $constraints = (new CoreConstraints($fetch))->forPackages(['typo3/cms-core', 'typo3/cms-backend']);

        $this->assertSame(['13.4.37' => ['symfony/yaml' => ['^7.4.21', '^7.4']]], $constraints);
    }
}
