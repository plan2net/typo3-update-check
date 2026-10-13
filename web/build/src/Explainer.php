<?php

declare(strict_types=1);

namespace Typo3UpdateCheckWeb\Build;

use Anthropic\Client;

final class Explainer
{
    /** Bump when the writer SYSTEM prompt OR the judge criteria change — regenerates every explanation (§5). */
    public const PROMPT_VERSION = 4;

    private const MODEL = 'claude-opus-5-5';
    private const SYSTEM = <<<TXT
        You explain ONE TYPO3 security advisory to the TYPO3 developers and project managers who
        decide whether to schedule an update. You are given the advisory's title, severity,
        affected package, version range, and the official problem description.

        Rules:
        - Ground everything in the official problem description. Say what it says actually
          happens, and under which conditions (e.g. "only forms with a custom upload folder").
          Do not invent or guess the attack, the affected feature, or consequences the
          description does not support. The title alone is too vague to explain from.
        - Use SIMPLE, everyday words in BOTH languages. Short sentences. Say what actually
          happens instead of naming the vulnerability class: "someone can put code into a page
          that then runs for every visitor", not "stored cross-site scripting". No acronyms
          (CVE, CVSS, XSS, CSRF, SSRF, RCE) and no terms of art ("deserialization",
          "authentication bypass", "sanitisation"). The German must be plain German, not
          English security jargon left untranslated.
        - Never copy code identifiers, setting or variable names, HTML element names, or server
          terms (e.g. getIndpEnv, SCRIPT_NAME, CGI/FPM, "tags") from the description. Say what
          they do in everyday words instead.
        - Your reader is a professional deciding whether to schedule this update, not a worried
          site owner. State the facts. No analogies, no reassurance, no filler, no padding.
        - Keep the described consequences proportionate to the severity: a low-severity issue
          gets a nuisance-level description (e.g. altered page content), never data theft or
          account takeover.
        - Do not state version numbers or upgrade steps; the tool shows those separately.

        Return exactly two fields, each AT MOST two short sentences — never three:
        - plainImpact: what an attacker could achieve.
        - urgency: how soon to act and what that depends on, using the
          preconditions the description names (e.g. "only matters if a form stores uploads in
          a custom folder").

        Example — advisory "Cross-Site Scripting in the Form Framework", severity high:
        - plainImpact: "Someone can slip code into a form that then runs in the browser of anyone
          who opens the page. That is enough to take over the session of a logged-in editor."
        - urgency: "Schedule this soon if the site uses forms; without them the exposure is limited."
        TXT;

    private const ABBREVIATIONS = ['z. B.', 'z.B.', 'd. h.', 'd.h.', 'bzw.', 'usw.', 'e.g.', 'i.e.', 'etc.'];

    private const JARGON = ['cve-', 'cvss', 'xss', 'csrf', 'ssrf', 'sql injection', ' rce', 'deserial', 'sanitis', 'sanitiz', 'authentication bypass'];

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
    public function explain(array $advisory, string $description, string $lang, ?string $rejection = null): ?array
    {
        $prompt = sprintf(
            "Advisory: %s\nSeverity: %s\nAffected package: %s\nAffected versions: %s\nReference: %s\n\n" .
            "Official problem description:\n%s\n\nWrite both fields in %s.",
            (string) ($advisory['title'] ?? ''),
            (string) ($advisory['severity'] ?? ''),
            (string) ($advisory['package'] ?? ''),
            (string) ($advisory['affectedVersions'] ?? ''),
            (string) ($advisory['link'] ?? ''),
            $description,
            self::LANGUAGE[$lang] ?? 'English',
        );
        if ($rejection !== null && $rejection !== '') {
            $prompt .= "\n\nA reviewer rejected your previous attempt: {$rejection}\nFix that.";
        }

        try {
            $message = $this->client->messages->create(
                model: self::MODEL,
                maxTokens: 4096, // thinking is always on and counts against this
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
    public static function validated(string $plainImpact, string $urgency): ?array
    {
        $plainImpact = trim($plainImpact);
        $urgency = trim($urgency);
        if (mb_strlen($plainImpact) < 15 || mb_strlen($plainImpact) > 600) {
            return null;
        }
        if (mb_strlen($urgency) < 8 || mb_strlen($urgency) > 400) {
            return null;
        }
        // The judge would reject it anyway.
        if (self::sentenceCount($plainImpact) > 2 || self::sentenceCount($urgency) > 2) {
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

    private static function sentenceCount(string $text): int
    {
        $text = str_ireplace(self::ABBREVIATIONS, '', $text);

        return preg_match_all('/[.!?](?=\s|$)/u', $text);
    }
}
