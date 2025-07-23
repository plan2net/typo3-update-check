<?php

declare(strict_types=1);

namespace Plan2net\Typo3UpdateCheck\Change;

use Plan2net\Typo3UpdateCheck\Release\ReleaseContent;
use Plan2net\Typo3UpdateCheck\Security\SecurityBulletinFetcherInterface;

final class ChangeParser
{
    public function __construct(
        private readonly ChangeFactory $changeFactory,
        private readonly ?SecurityBulletinFetcherInterface $bulletinFetcher = null,
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
        $news = $releaseNotes['news'] ?? null;

        $securitySeverities = [];
        if ($this->bulletinFetcher !== null && $news !== null) {
            $bulletinUrls = $this->extractBulletinUrls($news);
            if (!empty($bulletinUrls)) {
                $securitySeverities = $this->bulletinFetcher->fetchSeverities($bulletinUrls);
            }
        }

        return new ReleaseContent(
            version: $version,
            changes: $changes,
            newsLink: $releaseNotes['news_link'] ?? null,
            news: $news,
            securitySeverities: $securitySeverities,
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

    /**
     * @return string[]
     */
    private function extractBulletinUrls(string $text): array
    {
        preg_match_all(
            '#https://typo3\.org/security/advisory/\S+#',
            $text,
            $matches
        );

        return $matches[0];
    }
}
