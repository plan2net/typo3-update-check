<?php

declare(strict_types=1);

namespace Typo3UpdateCheckWeb\Build\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ValidateScriptTest extends TestCase
{
    #[Test]
    public function acceptsAValidSourceDatasetWithoutACheckedAt(): void
    {
        [$exitCode] = $this->runValidate($this->validDataset());

        $this->assertSame(0, $exitCode);
    }

    #[Test]
    public function requiresACheckedAtOnTheDeployArtifactWhenFlagged(): void
    {
        [$exitCode, $output] = $this->runValidate($this->validDataset(), '--require-checked-at');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('checkedAt', $output);
    }

    #[Test]
    public function acceptsAStampedDeployArtifactWhenFlagged(): void
    {
        [$exitCode] = $this->runValidate(
            ['checkedAt' => '2026-07-07T02:17:00+00:00'] + $this->validDataset(),
            '--require-checked-at',
        );

        $this->assertSame(0, $exitCode);
    }

    #[Test]
    public function rejectsAMalformedCheckedAtEvenWithoutTheFlag(): void
    {
        [$exitCode, $output] = $this->runValidate(['checkedAt' => ''] + $this->validDataset());

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('checkedAt', $output);
    }

    /**
     * @param array<string,mixed> $dataset
     * @return array{0:int,1:string}
     */
    private function runValidate(array $dataset, string ...$flags): array
    {
        $path = tempnam(sys_get_temp_dir(), 'typo3json');
        file_put_contents($path, json_encode($dataset));
        $command = sprintf(
            'php %s %s %s 2>&1',
            escapeshellarg(__DIR__ . '/../validate.php'),
            escapeshellarg($path),
            implode(' ', array_map('escapeshellarg', $flags)),
        );
        exec($command, $outputLines, $exitCode);
        unlink($path);

        return [$exitCode, implode("\n", $outputLines)];
    }

    /** @return array<string,mixed> */
    private function validDataset(): array
    {
        return [
            'generatedAt' => '2026-07-07T00:00:00+00:00',
            'majors' => [
                '13' => [
                    'maintainedUntil' => '2027-12-31T00:00:00+01:00',
                    'eltsUntil' => '2030-12-31T00:00:00+01:00',
                    'latestFree' => '13.4.31',
                    'latestElts' => '13.4.31',
                    'releases' => [
                        ['version' => '13.4.31', 'date' => null, 'type' => 'security', 'elts' => false],
                    ],
                ],
            ],
            'advisories' => [
                [
                    'id' => 'TYPO3-CORE-SA-2026-001',
                    'cve' => 'CVE-2026-0001',
                    'package' => 'typo3/cms-core',
                    'optional' => false,
                    'severity' => 'high',
                    'title' => 'Example',
                    'affectedVersions' => '>=13.0.0,<13.4.31',
                    'link' => 'https://typo3.org/security',
                    'affected' => ['13' => ['from' => '13.4.0', 'fixedIn' => '13.4.31', 'fixedInElts' => false]],
                    'explanation' => null,
                ],
            ],
        ];
    }
}
