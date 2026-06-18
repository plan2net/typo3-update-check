<?php

declare(strict_types=1);

namespace Typo3UpdateCheckWeb\Build;

use Anthropic\Client;

final class ExplanationJudge
{
    private const MODEL = 'claude-opus-4-8';
    private const SYSTEM = <<<TXT
        You are a strict reviewer. You are given a TYPO3 security advisory (title, severity,
        package, version range) and a plain-language explanation written for a non-technical
        client. Decide whether the explanation is safe to publish.

        Reject (ok = false) if ANY of these is true:
        - It states something not supported by the advisory (invented attack, feature, or impact).
        - It contradicts the advisory's severity or category.
        - It uses security jargon (CVE, CVSS, XSS, CSRF, SQL injection, RCE, deserialization, etc.).
        - It gives specific version numbers or upgrade instructions.
        - It is not plain enough for a non-technical reader, or exceeds two short sentences per field.

        Otherwise ok = true. Be conservative: when in doubt, reject. Give a one-line reason.
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
     * @return bool true = safe to publish. False (and not published) on rejection OR any API failure.
     */
    public function approve(array $advisory, string $lang, array $candidate): bool
    {
        $prompt = sprintf(
            "Advisory: %s\nSeverity: %s\nPackage: %s\nAffected versions: %s\nLanguage: %s\n\n" .
            "Explanation under review:\nplainImpact: %s\nurgency: %s",
            (string) ($advisory['title'] ?? ''),
            (string) ($advisory['severity'] ?? ''),
            (string) ($advisory['package'] ?? ''),
            (string) ($advisory['affectedVersions'] ?? ''),
            $lang,
            $candidate['plainImpact'],
            $candidate['urgency'],
        );

        try {
            $message = $this->client->messages->create(
                model: self::MODEL,
                maxTokens: 256,
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
                return false;
            }
            foreach ($message->content as $block) {
                if ($block->type === 'text') {
                    $data = json_decode($block->text, true);
                    if (is_array($data) && isset($data['ok'])) {
                        if ($data['ok'] !== true) {
                            fwrite(STDERR, "judge rejected ({$lang}): " . (string) ($data['reason'] ?? '') . "\n");
                        }
                        return $data['ok'] === true;
                    }
                }
            }
        } catch (\Throwable) {
            return false; // can't verify -> don't publish (conservative)
        }

        return false;
    }
}
