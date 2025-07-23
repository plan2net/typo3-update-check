<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck;

use Plan2net\Typo3UpdateCheck\Release\ReleaseContent;

final class ConsoleFormatter
{
    public function format(ReleaseContent $content): string
    {
        $output = "\n<info>Changes in version {$content->version}:</info>\n";

        $breaking = $content->getBreakingChanges();
        $security = $content->getSecurityUpdates();

        if ($breaking) {
            $output .= "<error>Breaking changes found:</error>\n";
            foreach ($breaking as $change) {
                $output .= '  <error>⚠️</error> ' . $this->escape($change->title) . "\n";
            }
        }

        if ($security) {
            $output .= "<comment>Security updates found:</comment>\n";
            foreach ($security as $update) {
                $output .= '  <comment>⚡</comment> ' . $this->escape($update->title) . "\n";
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
}
