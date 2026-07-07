<?php

declare(strict_types=1);

namespace Typo3UpdateCheckWeb\Build;

/**
 * Stamps the DEPLOYED dataset with the checkedAt heartbeat the frontend uses to fail closed
 * when the pipeline stalls. generatedAt only moves on a real data change, so it cannot serve
 * as the heartbeat; checkedAt moves on every verified run.
 */
final class FreshnessStamp
{
    /**
     * @param array<string,mixed> $dataset
     * @return array<string,mixed>
     */
    public static function stamp(array $dataset, \DateTimeImmutable $now): array
    {
        if (!isset($dataset['majors'], $dataset['advisories'])) {
            throw new \RuntimeException('Dataset is missing majors/advisories — refusing to stamp a malformed file.');
        }
        $dataset['checkedAt'] = $now->setTimezone(new \DateTimeZone('UTC'))->format('c');

        return $dataset;
    }
}
