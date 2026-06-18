<?php

declare(strict_types=1);

namespace Typo3UpdateCheckWeb\Build;

use Anthropic\Client;

final class Explainer
{
    /** Bump when the writer SYSTEM prompt OR the judge criteria change — regenerates every explanation (§5). */
    public const PROMPT_VERSION = 1;

    private const MODEL = 'claude-opus-4-8';
    private const SYSTEM = <<<TXT
        You explain ONE TYPO3 security advisory so a TYPO3 agency can forward it to a
        non-technical client (a small-business website owner). You are given only the
        advisory's title, severity, affected package, and version range.

        Rules:
        - Ground everything in the given advisory ONLY. Do not invent or guess the attack,
          the affected feature, or the consequences beyond what the title and category say.
          If a detail isn't given, don't claim it — stay general ("a security weakness")
          rather than inventing specifics.
        - Write for someone with no technical or security background. Short, everyday sentences.
        - No jargon. Never use terms like CVE, CVSS, XSS, CSRF, SQL injection, RCE,
          deserialization, "authentication bypass", "sanitisation" — translate them to plain words.
        - Do not state version numbers or upgrade steps; the tool shows those separately.

        Return exactly two fields:
        - plainImpact: 1–2 sentences on what could go wrong for the website, in plain terms.
        - urgency: 1 sentence on how soon to act and what it depends on (e.g. "only matters
          if the site uses contact forms").

        Example — advisory "Cross-Site Scripting in the Form Framework", severity high:
        - plainImpact: "If the site uses forms, an attacker could slip harmful code into a page
          that then runs in a visitor's browser — which can be used to steal information or take
          over their session."
        - urgency: "Act soon if the site uses forms; if it doesn't, the risk is low."
        TXT;

    private const JARGON = ['cve-', 'cvss', 'xss', 'csrf', 'sql injection', ' rce', 'deserial', 'sanitis', 'sanitiz', 'authentication bypass'];

    public function __construct(private readonly Client $client) {}

    public static function fromEnv(): self
    {
        return new self(new Client(apiKey: (string) getenv('ANTHROPIC_API_KEY')));
    }

    private const LANGUAGE = ['en' => 'English', 'de' => 'German'];

    /**
     * @param array<string,mixed> $advisory
     * @param 'en'|'de' $lang
     * @return array{plainImpact:string,urgency:string}|null null on any failure (fail-soft)
     */
    public function explain(array $advisory, string $lang): ?array
    {
        $prompt = sprintf(
            "Advisory: %s\nSeverity: %s\nAffected package: %s\nAffected versions: %s\nReference: %s\n\nWrite both fields in %s.",
            (string) ($advisory['title'] ?? ''),
            (string) ($advisory['severity'] ?? ''),
            (string) ($advisory['package'] ?? ''),
            (string) ($advisory['affectedVersions'] ?? ''),
            (string) ($advisory['link'] ?? ''),
            self::LANGUAGE[$lang] ?? 'English',
        );

        try {
            $message = $this->client->messages->create(
                model: self::MODEL,
                maxTokens: 512,
                system: [['type' => 'text', 'text' => self::SYSTEM]],
                messages: [['role' => 'user', 'content' => $prompt]],
                outputConfig: [
                    'effort' => 'medium', // careful but not embellishing; cheap for a tiny task
                    'format' => [
                        'type' => 'json_schema',
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'plainImpact' => ['type' => 'string'],
                                'urgency' => ['type' => 'string'],
                            ],
                            'required' => ['plainImpact', 'urgency'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
            );

            // Reject refusals and truncated answers — never persist those forever.
            if ($message->stopReason !== 'end_turn') {
                return null;
            }

            foreach ($message->content as $block) {
                if ($block->type === 'text') {
                    $data = json_decode($block->text, true);
                    if (is_array($data) && isset($data['plainImpact'], $data['urgency'])) {
                        return self::validated((string) $data['plainImpact'], (string) $data['urgency']);
                    }
                }
            }
        } catch (\Throwable) {
            return null; // fail-soft: advisory ships without an explanation; retried next run
        }

        return null;
    }

    /**
     * Quality gate before anything is cached. Returns null (→ skip, retry next run) on anything
     * that smells like garbage: empty, suspiciously short, or runaway-long text.
     *
     * @return array{plainImpact:string,urgency:string}|null
     */
    private static function validated(string $plainImpact, string $urgency): ?array
    {
        $plainImpact = trim($plainImpact);
        $urgency = trim($urgency);
        if (mb_strlen($plainImpact) < 15 || mb_strlen($plainImpact) > 600) {
            return null;
        }
        if (mb_strlen($urgency) < 8 || mb_strlen($urgency) > 400) {
            return null;
        }

        // Jargon leaked through despite the prompt? Keep it (verdict is unaffected) but flag it
        // for the human review the committed diff already gets. Flip to `return null` to hard-reject.
        $haystack = mb_strtolower($plainImpact . ' ' . $urgency);
        foreach (self::JARGON as $term) {
            if (str_contains($haystack, $term)) {
                fwrite(STDERR, "warning: jargon \"{$term}\" in explanation; review it\n");
                break;
            }
        }

        return ['plainImpact' => $plainImpact, 'urgency' => $urgency];
    }
}
