<?php

declare(strict_types=1);

namespace Typo3UpdateCheckWeb\Build;

use Anthropic\Client;

final class ExplanationJudge
{
    private const MODEL = 'claude-opus-5-5';
    private const SYSTEM = <<<TXT
        You are a strict reviewer. You are given a TYPO3 security advisory (title, severity,
        package, version range, official problem description) and a plain-language explanation
        of it. Its readers are the
        TYPO3 developers and project managers deciding whether to schedule an update, and it
        must reach them in simple, everyday words in its own language. Decide whether the
        explanation is safe to publish.

        The explanation's JOB is to translate the advisory's vulnerability class into everyday
        words. Describing the standard, well-known consequence of that class in plain, hedged
        language ("could") is CORRECT — it is the required translation, not invention. Example
        of a VALID explanation for a cross-site-scripting advisory: "Someone could slip code
        into a page that then runs in a visitor's browser, which is enough to steal information
        or take over their session." Plain phrases like "harmful code", "take over a visitor's
        logged-in session" or "pretend to be them" are everyday language, not jargon.

        Reject (ok = false) ONLY if one of these is true:
        - It claims a consequence beyond the standard scope of the advisory's vulnerability
          class, or out of proportion to its severity (e.g. data theft or full takeover for a
          low-severity issue).
        - It contradicts the advisory's severity or category, or names an affected feature the
          advisory does not mention.
        - It says something the official problem description does not support, or leaves out
          what actually happens or a precondition the description names (e.g. "only forms with
          a custom upload folder"), so a reader would misjudge whether their site is affected.
        - It uses technical terms of art or acronyms (CVE, CVSS, XSS, CSRF, SQL injection,
          RCE, deserialization, ...) instead of plain words, or — in a German explanation —
          leaves English security jargon untranslated.
        - It gives specific version numbers or upgrade instructions.
        - It talks down to the reader (analogies, reassurance, hand-holding) or pads with
          filler, or exceeds two short sentences per field.

        Otherwise ok = true. Judge against these criteria only — no stylistic preferences.
        Give a one-line reason.
        TXT;

    public function __construct(private readonly Client $client) {}

    public static function fromEnv(): self
    {
        return new self(new Client(apiKey: (string) getenv('ANTHROPIC_API_KEY')));
    }

    /**
     * @param array<string,mixed> $advisory
     * @param 'en'|'de' $lang
     * @param array{plainImpact:string,urgency:string} $candidate
     * @return string|null null = approved, '' = API failure
     */
    public function rejection(array $advisory, string $description, string $lang, array $candidate): ?string
    {
        $prompt = sprintf(
            "Advisory: %s\nSeverity: %s\nPackage: %s\nAffected versions: %s\n" .
            "Official problem description:\n%s\n\nLanguage: %s\n\n" .
            "Explanation under review:\nplainImpact: %s\nurgency: %s",
            (string) ($advisory['title'] ?? ''),
            (string) ($advisory['severity'] ?? ''),
            (string) ($advisory['package'] ?? ''),
            (string) ($advisory['affectedVersions'] ?? ''),
            $description,
            $lang,
            $candidate['plainImpact'],
            $candidate['urgency'],
        );

        try {
            $message = $this->client->messages->create(
                model: self::MODEL,
                maxTokens: 4096, // thinking is always on and counts against this
                system: [['type' => 'text', 'text' => self::SYSTEM]],
                messages: [['role' => 'user', 'content' => $prompt]],
                outputConfig: [
                    'effort' => 'high', // scrutiny is the reasoning step worth spending on
                    'format' => [
                        'type' => 'json_schema',
                        'schema' => [
                            'type' => 'object',
                            'properties' => [
                                'ok' => ['type' => 'boolean'],
                                'reason' => ['type' => 'string'],
                            ],
                            'required' => ['ok', 'reason'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
            );
            if ($message->stopReason !== 'end_turn') {
                return '';
            }
            foreach ($message->content as $block) {
                if ($block->type === 'text') {
                    $data = json_decode($block->text, true);
                    if (is_array($data) && isset($data['ok'])) {
                        if ($data['ok'] === true) {
                            return null;
                        }
                        $reason = (string) ($data['reason'] ?? '');
                        fwrite(STDERR, "judge rejected ({$lang}): {$reason}\n");

                        return $reason;
                    }
                }
            }
        } catch (\Throwable) {
            return ''; // can't verify -> don't publish (conservative)
        }

        return '';
    }
}
