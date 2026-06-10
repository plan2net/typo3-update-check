<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck;

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
            if ($content->getBreakingChanges() || $content->getSecurityUpdates()) {
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
            if ($content->getSecurityUpdates()) {
                ++$securityReleases;
                foreach ($content->securitySeverities as $level => $count) {
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

        if ($security) {
            $severitySummary = $this->formatSeveritySummary($content->securitySeverities);
            $output .= "<comment>Security updates found{$severitySummary}:</comment>\n";
            foreach ($security as $update) {
                $output .= '  ⚡ ' . $this->escape($update->title) . "\n";
            }
        }

        $advisories = $content->getSecurityAdvisories();
        if ($advisories) {
            $output .= "\n<info>Security advisories:</info>\n";
            foreach ($advisories as $advisory) {
                $output .= '  - ' . $this->escape($advisory) . "\n";
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

    /**
     * @param array<string, int> $severities
     */
    private function formatSeveritySummary(array $severities): string
    {
        $breakdown = $this->severityBreakdown($severities);

        return $breakdown === '' ? '' : " ({$breakdown})";
    }

    /**
     * @param array<string, int> $severities
     */
    private function severityBreakdown(array $severities): string
    {
        $order = ['Critical', 'High', 'Medium', 'Low'];
        $parts = [];

        foreach ($order as $level) {
            if (isset($severities[$level]) && $severities[$level] > 0) {
                $parts[] = $severities[$level] . ' ' . $level;
            }
        }

        return implode(', ', $parts);
    }
}
