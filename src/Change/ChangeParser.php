<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Change;

use Plan2net\Typo3UpdateCheck\Release\ReleaseContent;

final class ChangeParser
{
    public function __construct(
        private readonly ChangeFactory $changeFactory,
    ) {
    }

    /**
     * @param array<string, mixed> $apiResponse
     */
    public function parse(array $apiResponse): ReleaseContent
    {
        $releaseNotes = $apiResponse['release_notes'] ?? [];
        $version = $apiResponse['version'] ?? $releaseNotes['version'] ?? 'unknown';
        $changes = $this->extractChangesFromText($releaseNotes['changes'] ?? '');

        return new ReleaseContent(
            version: $version,
            changes: $changes,
            newsLink: $releaseNotes['news_link'] ?? null,
            news: $releaseNotes['news'] ?? null,
        );
    }

    /**
     * @return Change[]
     */
    private function extractChangesFromText(string $text): array
    {
        if (empty($text)) {
            return [];
        }

        $pattern = '/\*\s+[\w-]+\s+\w+\s+\[([A-Z!]+)\](?:\[([A-Z]+)\])?\s*(.+?)\s*\(/i';
        preg_match_all($pattern, $text, $matches, PREG_SET_ORDER);

        return array_map(
            fn (array $match) => $this->createChangeFromMatch($match),
            $matches
        );
    }

    /**
     * @param array<int, string> $match
     */
    private function createChangeFromMatch(array $match): Change
    {
        $type = $match[1];
        $subType = $match[2] ?? '';
        $rawTitle = $match[3];

        $title = $type === ChangeType::BREAKING->value
            ? "[!!!][$subType] $rawTitle"
            : "[$type] $rawTitle";

        return $this->changeFactory->createFrom($type, $title);
    }
}
