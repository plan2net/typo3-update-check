<?php

declare(strict_types=1);

namespace Typo3UpdateCheckWeb\Build;

// Packagist only carries the title, which is too vague to explain an advisory from.
final class AdvisoryDescription
{
    private const BULLETIN_HOSTS = ['typo3.org', 'www.typo3.org', 'news.typo3.com'];

    /** @param \Closure(string):?string $fetch */
    public function __construct(private readonly \Closure $fetch) {}

    /** @param array<string,mixed> $advisory */
    public function for(array $advisory): ?string
    {
        $link = (string) ($advisory['link'] ?? '');
        $host = strtolower((string) parse_url($link, PHP_URL_HOST));

        if (in_array($host, self::BULLETIN_HOSTS, true)) {
            return $this->fromBulletin($link);
        }
        if ($host === 'github.com' && preg_match('#^/advisories/(GHSA(?:-[a-z0-9]{4}){3})$#i', (string) parse_url($link, PHP_URL_PATH), $match) === 1) {
            return $this->fromGithub($match[1]);
        }

        return null;
    }

    private function fromBulletin(string $link): ?string
    {
        $html = ($this->fetch)($link);
        if ($html === null || preg_match('#<h\d[^>]*>\s*Problem Description\s*</h\d>(.*?)(?:<h\d|$)#is', $html, $match) !== 1) {
            return null;
        }
        $text = html_entity_decode(strip_tags(preg_replace('#</p>#i', ' ', $match[1]) ?? ''), ENT_QUOTES | ENT_HTML5);

        return self::normalised($text);
    }

    private function fromGithub(string $ghsaId): ?string
    {
        $json = ($this->fetch)('https://api.github.com/advisories/' . $ghsaId);
        $data = $json === null ? null : json_decode($json, true);
        $description = is_array($data) ? (string) ($data['description'] ?? '') : '';
        if (preg_match('/^#+\s*Problem[^\n]*\n(.*?)(?=^#+\s|\z)/ims', $description, $match) === 1) {
            $description = $match[1];
        }

        return self::normalised($description);
    }

    private static function normalised(string $text): ?string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return $text === '' ? null : $text;
    }
}
