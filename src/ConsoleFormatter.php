<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck;

use Plan2net\Typo3UpdateCheck\Advisory\Advisory;
use Plan2net\Typo3UpdateCheck\Release\FailureMessageFormatter;
use Plan2net\Typo3UpdateCheck\Release\ReleaseContent;
use Plan2net\Typo3UpdateCheck\Release\ReleaseContentBatch;

final class ConsoleFormatter
{
    public function __construct(
        private readonly FailureMessageFormatter $failureFormatter = new FailureMessageFormatter(),
    ) {
    }

    /**
     * @return string[]
     */
    public function formatBatchReport(ReleaseContentBatch $batch, string $fromVersion, string $toVersion): array
    {
        if (!$batch->hasResults() && !$batch->hasFailures()) {
            return [
                '<error>Failed to fetch release information.</error>',
                '<comment>The TYPO3 API might be temporarily unavailable. Proceeding with update.</comment>',
            ];
        }

        $lines = [];
        foreach ($batch->results as $content) {
            if ($content->getBreakingChanges() || $content->getSecurityUpdates() || $content->advisories !== []) {
                $lines[] = $this->format($content);
            }
        }

        if ($batch->hasFailures()) {
            foreach ($batch->failures as $version => $failure) {
                $lines[] = '<comment>' . $this->failureFormatter->describe($version, $failure) . '</comment>';
            }
            $lines[] = sprintf(
                '<comment>Retry later with: composer typo3:check-updates %s %s</comment>',
                $fromVersion,
                $toVersion,
            );

            if (!$batch->hasResults()) {
                $lines[] = sprintf(
                    '<comment>Proceeding with update (dominant failure: %s).</comment>',
                    $batch->dominantFailureCategory()->value ?? 'unknown',
                );
            }
        }

        $releaseCount = count($batch->results) + count($batch->failures);

        if ($batch->hasImportantChanges()) {
            $lines[] = $this->formatDigest($batch, $fromVersion, $toVersion, $releaseCount);
        } elseif (!$batch->hasFailures()) {
            $lines[] = sprintf(
                '✓ %d release%s (%s → %s), bugfixes only — no breaking changes or security updates',
                $releaseCount,
                $releaseCount === 1 ? '' : 's',
                $fromVersion,
                $toVersion,
            );
        }

        return $lines;
    }

    /**
     * @param string[] $newerSecurityVersions
     *
     * @return string[]
     */
    public function formatSecurityGap(string $targetVersion, array $newerSecurityVersions): array
    {
        if ($newerSecurityVersions === []) {
            return [];
        }

        return [
            sprintf(
                '<comment>⚡ Your target %s is missing security fixes released in %s.</comment>',
                $targetVersion,
                implode(', ', $newerSecurityVersions),
            ),
            '<comment>   Raise your version constraint to install them.</comment>',
        ];
    }

    private function formatDigest(ReleaseContentBatch $batch, string $fromVersion, string $toVersion, int $releaseCount): string
    {
        $securityReleases = 0;
        $breakingReleases = 0;
        $severities = [];

        foreach ($batch->results as $content) {
            if ($content->getSecurityUpdates() || $content->advisories !== []) {
                ++$securityReleases;
                foreach ($content->getSeverityCounts() as $level => $count) {
                    $severities[$level] = ($severities[$level] ?? 0) + $count;
                }
            }
            if ($content->getBreakingChanges()) {
                ++$breakingReleases;
            }
        }

        $segments = [sprintf('%d release%s (%s → %s)', $releaseCount, $releaseCount === 1 ? '' : 's', $fromVersion, $toVersion)];

        if ($securityReleases > 0) {
            $breakdown = $this->severityBreakdown($severities);
            $segments[] = sprintf('⚡ %d with security%s', $securityReleases, $breakdown === '' ? '' : " ({$breakdown})");
        }

        if ($breakingReleases > 0) {
            $segments[] = sprintf('⚠️  %d with breaking changes', $breakingReleases);
        }

        return '<options=bold>' . implode(' · ', $segments) . '</>';
    }

    public function format(ReleaseContent $content): string
    {
        $output = "<info>Changes in version {$content->version}:</info>\n";

        $breaking = $content->getBreakingChanges();
        $security = $content->getSecurityUpdates();

        if ($breaking) {
            $output .= "<error>Breaking changes found:</error>\n";
            foreach ($breaking as $change) {
                $output .= '  ⚠️  ' . $this->escape($change->title) . "\n";
            }
        }

        if ($content->advisories !== []) {
            $output .= '<comment>' . $this->formatVulnerabilityHeading($content) . "</comment>\n";
            foreach ($this->describeAdvisories($content->advisories) as $line) {
                $output .= '  - ' . $line . "\n";
            }
            if ($security) {
                $output .= "\n<info>Fixed by:</info>\n";
                foreach ($security as $update) {
                    $output .= '  ⚡ ' . $this->escape($update->title) . "\n";
                }
            }
        } else {
            if ($security) {
                $output .= "<comment>Security updates found:</comment>\n";
                foreach ($security as $update) {
                    $output .= '  ⚡ ' . $this->escape($update->title) . "\n";
                }
            }

            $bulletinLines = [];
            foreach ($content->getSecurityAdvisories() as $bulletinUrl) {
                $bulletinLines[] = '  - ' . $this->escape($bulletinUrl);
            }
            if ($bulletinLines !== []) {
                $output .= "\n<info>Security advisories:</info>\n" . implode("\n", $bulletinLines) . "\n";
            }
        }

        if ($content->newsLink) {
            $output .= "\n<info>Release announcement:</info> " . $this->escape($content->newsLink) . "\n";
        }

        return $output;
    }

    private function escape(string $text): string
    {
        $text = str_replace(['<', '>'], ['&lt;', '&gt;'], $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text) ?? $text;

        return $text;
    }

    private function formatVulnerabilityHeading(ReleaseContent $content): string
    {
        $advisoryCount = count($content->advisories);
        $noun = $advisoryCount === 1 ? 'vulnerability' : 'vulnerabilities';
        $breakdown = $this->severityBreakdown($content->getSeverityCounts());
        $summary = $breakdown === '' ? "{$advisoryCount} {$noun}" : "{$advisoryCount} {$noun}: {$breakdown}";

        return "Security updates found ({$summary}):";
    }

    /**
     * @param array<string, int> $severities
     */
    private function severityBreakdown(array $severities): string
    {
        $order = ['critical', 'high', 'medium', 'low'];
        $parts = [];

        foreach ($order as $level) {
            if (isset($severities[$level]) && $severities[$level] > 0) {
                $segment = $severities[$level] . ' ' . $level;
                $parts[] = match ($level) {
                    'critical' => '<fg=red;options=bold>' . $segment . '</>',
                    'high' => '<fg=red>' . $segment . '</>',
                    default => $segment,
                };
            }
        }

        return implode(', ', $parts);
    }

    /**
     * Returns ready-to-print lines: text parts are escaped, severity cells carry console markup.
     *
     * @param list<Advisory> $advisories
     *
     * @return string[]
     */
    private function describeAdvisories(array $advisories): array
    {
        $advisories = $this->sortBySeverity($advisories);

        $cveWidth = 0;
        $severityWidth = 0;
        foreach ($advisories as $advisory) {
            $cveWidth = max($cveWidth, strlen($advisory->cve ?? ''));
            $severityWidth = max($severityWidth, $advisory->severity !== null ? strlen($advisory->severity) + 2 : 0);
        }

        $lines = [];
        foreach ($advisories as $advisory) {
            $parts = [];
            if ($cveWidth > 0) {
                $parts[] = $this->escape(str_pad($advisory->cve ?? '', $cveWidth));
            }
            if ($severityWidth > 0) {
                $severityCell = $advisory->severity !== null ? "({$advisory->severity})" : '';
                $paddedCell = $this->escape(str_pad($severityCell, $severityWidth, ' ', STR_PAD_LEFT));
                $parts[] = $this->highlightSeverity($paddedCell, $advisory->severity);
            }
            $parts[] = $this->escape($advisory->title . ' — ' . $advisory->link);
            $lines[] = implode('  ', $parts);
        }

        return $lines;
    }

    /**
     * @param list<Advisory> $advisories
     *
     * @return list<Advisory>
     */
    private function sortBySeverity(array $advisories): array
    {
        $rankBySeverity = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        $unknownRank = count($rankBySeverity);

        usort(
            $advisories,
            static fn (Advisory $first, Advisory $second): int => ($rankBySeverity[$first->severity] ?? $unknownRank) <=> ($rankBySeverity[$second->severity] ?? $unknownRank),
        );

        return $advisories;
    }

    private function highlightSeverity(string $paddedCell, ?string $severity): string
    {
        return match ($severity) {
            'critical' => '<fg=red;options=bold>' . $paddedCell . '</>',
            'high' => '<fg=red>' . $paddedCell . '</>',
            default => $paddedCell,
        };
    }
}
