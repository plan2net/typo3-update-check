<?php

declare(strict_types=1);

namespace Typo3UpdateCheckWeb\Build;

/**
 * Packagist serves a fresh TYPO3 advisory with severity null for as long as it takes the upstream
 * database to score it, and the build then falls back to 'unknown' — a badge that tells a reader
 * nothing. TYPO3's own bulletin carries the rating from day one, so read it from there for the
 * handful of records that arrive unrated. Fail-soft throughout: an unreachable or reshaped bulletin
 * leaves the advisory unrated rather than failing the build or inventing a rating.
 */
final class SeverityBackfill
{
    private const BULLETIN_HOSTS = ['typo3.org', 'www.typo3.org', 'news.typo3.com'];

    private const VALID = ['critical', 'high', 'medium', 'low'];

    /** @var array<string,?string> */
    private array $fetched = [];

    /** @param \Closure(string):?string $fetch */
    public function __construct(private readonly \Closure $fetch) {}

    /**
     * @param list<array<string,mixed>> $advisories
     * @return list<array<string,mixed>>
     */
    public function apply(array $advisories): array
    {
        foreach ($advisories as &$advisory) {
            if (($advisory['severity'] ?? '') !== 'unknown') {
                continue;
            }
            $link = (string) ($advisory['link'] ?? '');
            if (!$this->isBulletin($link)) {
                continue;
            }
            $severity = $this->severityFrom($link);
            if ($severity !== null) {
                $advisory['severity'] = $severity;
            }
        }
        unset($advisory);

        return $advisories;
    }

    /**
     * Only official TYPO3 bulletins are parsed. Any other host would be third-party HTML whose
     * "Severity:" means something else, or nothing at all.
     */
    private function isBulletin(string $link): bool
    {
        $host = parse_url($link, PHP_URL_HOST);

        return is_string($host) && in_array(strtolower($host), self::BULLETIN_HOSTS, true);
    }

    private function severityFrom(string $link): ?string
    {
        $html = $this->fetched[$link] ??= ($this->fetch)($link);
        if ($html === null) {
            return null;
        }
        // The rating sits in a list as "<strong>Severity:</strong><span> High</span>", so drop the
        // markup before matching rather than guessing at the surrounding tags. Tags become a SPACE,
        // not nothing: stripping them outright glues the next item on and yields "HighSuggested".
        $text = preg_replace(['#<(script|style)\b[^>]*>.*?</\1>#is', '/<[^>]*>/'], ' ', $html);
        $text = html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5);
        if (preg_match('/Severity:\s*([A-Za-z]+)/i', $text, $match) !== 1) {
            return null;
        }
        $severity = strtolower($match[1]);

        return in_array($severity, self::VALID, true) ? $severity : null;
    }
}
