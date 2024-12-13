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
                $output .= "  <error>⚠️</error> {$change->title}\n";
            }
        }

        if ($security) {
            $output .= "<comment>Security updates found:</comment>\n";
            foreach ($security as $update) {
                $output .= "  <comment>⚡</comment> {$update->title}\n";
            }
        }

        $advisories = $content->getSecurityAdvisories();
        if ($advisories) {
            $output .= "\n<info>Security advisories:</info>\n";
            foreach ($advisories as $advisory) {
                $output .= "  - $advisory\n";
            }
        }

        if ($content->newsLink) {
            $output .= "\n<info>Release announcement:</info> {$content->newsLink}\n";
        }

        return $output;
    }
}
