<?php

declare(strict_types=1);

namespace Typo3UpdateCheckWeb\Build\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Typo3UpdateCheckWeb\Build\DataFile;

final class DataFileTest extends TestCase
{
    #[Test]
    public function writesPrettyUnescapedJsonWithATrailingNewline(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'datafile');

        DataFile::write($path, ['link' => 'https://typo3.org/security', 'title' => 'Größe']);

        $written = (string) file_get_contents($path);
        $this->assertStringEndsWith("\n", $written);
        $this->assertStringContainsString('https://typo3.org/security', $written); // slashes unescaped
        $this->assertStringContainsString('Größe', $written);                      // unicode unescaped
        $this->assertStringContainsString("\n    ", $written);                     // pretty-printed
        unlink($path);
    }

    #[Test]
    public function throwsWhenTheTargetIsNotWritable(): void
    {
        $this->expectException(\RuntimeException::class);

        DataFile::write(sys_get_temp_dir() . '/no-such-directory/typo3.json', ['a' => 1]);
    }
}
