<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Plan2net\Typo3UpdateCheck\VersionParser;

final class VersionParserTest extends TestCase
{
    private VersionParser $parser;

    protected function setUp(): void
    {
        $this->parser = new VersionParser();
    }

    #[Test]
    #[DataProvider('validVersionProvider')]
    public function normalizeVersionWithValidVersions(string $input, string $expected): void
    {
        $result = $this->parser->normalize($input);
        $this->assertSame($expected, $result);
    }

    #[Test]
    #[DataProvider('invalidVersionProvider')]
    public function normalizeVersionWithInvalidVersions(string $input): void
    {
        $result = $this->parser->normalize($input);
        $this->assertNull($result);
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function validVersionProvider(): array
    {
        return [
            // Basic versions
            ['12.4.1', '12.4.1'],
            ['12.4.0', '12.4.0'],
            ['12.0.0', '12.0.0'],

            // With v prefix
            ['v12.4.1', '12.4.1'],
            ['v12.4.0', '12.4.0'],

            // With suffixes
            ['12.4.1-dev', '12.4.1'],
            ['12.4.1-alpha', '12.4.1'],
            ['12.4.1-beta', '12.4.1'],
            ['12.4.1-rc1', '12.4.1'],
            ['12.4.1-RC2', '12.4.1'],

            // With 4 parts
            ['12.4.1.2', '12.4.1'],
            ['12.4.0.0', '12.4.0'],

            // Complex cases
            ['v12.4.0-dev', '12.4.0'],
            ['v12.0.0-beta', '12.0.0'],

            // Edge cases with trailing zeros
            ['1.0.0', '1.0.0'],
            ['1.2.0', '1.2.0'],
            ['10.0.0', '10.0.0'],

            // 1-part and 2-part versions (padded with zeros)
            ['12', '12.0.0'],
            ['12.4', '12.4.0'],
            ['1', '1.0.0'],
            ['v12', '12.0.0'],
            ['v12.4', '12.4.0'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidVersionProvider(): array
    {
        return [
            // Invalid formats
            ['12.4.1.2.3'],
            ['not-a-version'],
            [''],

            // With 9999 (placeholder versions)
            ['12.9999.0'],
            ['9999.0.0'],
            ['12.4.9999'],

            // Invalid characters
            ['12.4.a'],
            ['12.b.1'],
            ['a.b.c'],

            // Zero versions
            ['0'],
            ['0.0'],
            ['0.0.0'],
            ['0.0.0.0'],
        ];
    }
}
