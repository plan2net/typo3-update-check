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
                    $batch->dominantFailureCategory()?->value ?? 'unknown',
                );
            }
        }

        if (!$batch->hasImportantChanges() && !$batch->hasFailures()) {
            $lines[] = '✓ No breaking changes or security updates found.';
        }

        return $lines;
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
        if (empty($severities)) {
            return '';
        }

        $order = ['Critical', 'High', 'Medium', 'Low'];
        $parts = [];

        foreach ($order as $level) {
            if (isset($severities[$level]) && $severities[$level] > 0) {
                $parts[] = $severities[$level] . ' ' . $level;
            }
        }

        return ' (' . implode(', ', $parts) . ')';
    }
}
