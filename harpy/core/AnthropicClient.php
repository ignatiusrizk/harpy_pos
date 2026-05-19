<?php
// ══════════════════════════════════════════════════════
// core/AnthropicClient.php — Wrapper minimal untuk Claude API
//
// Usage:
//   $result = AnthropicClient::ask("Berapa hasil 2+2?");
//   echo $result['text'];
//
//   // JSON mode dengan system prompt:
//   $result = AnthropicClient::ask($userPrompt, [
//       'system'      => 'Kamu adalah konsultan bisnis.',
//       'model'       => 'claude-sonnet-4-5-20250929',
//       'max_tokens'  => 1024,
//       'temperature' => 0.5,
//   ]);
// ══════════════════════════════════════════════════════

class AnthropicClient
{
    const DEFAULT_MODEL    = 'claude-sonnet-4-5-20250929';
    const DEFAULT_MAX_TOK  = 1024;
    const DEFAULT_TIMEOUT  = 30;
    const API_URL          = 'https://api.anthropic.com/v1/messages';
    const API_VERSION      = '2023-06-01';

    /**
     * Kirim prompt ke Claude API.
     *
     * @param string $prompt User message
     * @param array $opts {
     *   @var string $system      Optional system prompt
     *   @var string $model       Model ID (default: claude-sonnet-4-5)
     *   @var int    $max_tokens  Max output tokens (default: 1024)
     *   @var float  $temperature 0.0-1.0 (default: 0.7)
     *   @var int    $timeout     Detik (default: 30)
     * }
     *
     * @return array {
     *   @var string $text         Response text
     *   @var int    $tokens_in    Input tokens used
     *   @var int    $tokens_out   Output tokens used
     *   @var float  $cost_usd     Estimasi cost dalam USD
     *   @var string $model        Model yang dipakai
     *   @var string $stop_reason  end_turn|max_tokens|stop_sequence
     * }
     *
     * @throws RuntimeException kalau API error / timeout / no key
     */
    public static function ask(string $prompt, array $opts = []): array
    {
        if (!defined('ANTHROPIC_API_KEY') || empty(ANTHROPIC_API_KEY)) {
            throw new RuntimeException('ANTHROPIC_API_KEY belum di-set di config.');
        }

        $model      = $opts['model']       ?? self::DEFAULT_MODEL;
        $maxTokens  = (int)($opts['max_tokens']  ?? self::DEFAULT_MAX_TOK);
        $temp       = (float)($opts['temperature'] ?? 0.7);
        $timeout    = (int)($opts['timeout']    ?? self::DEFAULT_TIMEOUT);

        $payload = [
            'model'       => $model,
            'max_tokens'  => $maxTokens,
            'temperature' => $temp,
            'messages'    => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ];

        if (!empty($opts['system'])) {
            $payload['system'] = $opts['system'];
        }

        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'x-api-key: ' . ANTHROPIC_API_KEY,
                'anthropic-version: ' . self::API_VERSION,
                'content-type: application/json',
            ],
        ]);

        $raw  = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException("Anthropic API error: $err");
        }
        if ($http !== 200) {
            $errBody = json_decode($raw, true);
            $errMsg  = $errBody['error']['message'] ?? "HTTP $http";
            throw new RuntimeException("Anthropic API error ($http): $errMsg");
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['content'])) {
            throw new RuntimeException('Anthropic API returned invalid format.');
        }

        // Extract text dari content blocks
        $text = '';
        foreach ($data['content'] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            }
        }

        $tokensIn  = (int)($data['usage']['input_tokens']  ?? 0);
        $tokensOut = (int)($data['usage']['output_tokens'] ?? 0);

        // Estimasi cost USD — Sonnet 4.5 pricing
        // input $3/MTok, output $15/MTok
        $costUsd = ($tokensIn / 1_000_000 * 3.0) + ($tokensOut / 1_000_000 * 15.0);

        return [
            'text'        => trim($text),
            'tokens_in'   => $tokensIn,
            'tokens_out'  => $tokensOut,
            'cost_usd'    => round($costUsd, 6),
            'model'       => $data['model'] ?? $model,
            'stop_reason' => $data['stop_reason'] ?? 'unknown',
        ];
    }

    /**
     * Ask dan parse JSON response.
     * Otomatis tambah instruksi "respond with valid JSON only".
     *
     * @return array Parsed JSON (atau throw kalau parse gagal)
     */
    public static function askJson(string $prompt, array $opts = []): array
    {
        $sys = ($opts['system'] ?? '') . "\n\nIMPORTANT: Respond ONLY with valid JSON. No markdown fences, no explanation. Start with { or [.";
        $opts['system'] = trim($sys);

        $result = self::ask($prompt, $opts);
        $text   = $result['text'];

        // Strip markdown fence kalau ada
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text);
        $text = trim($text);

        $parsed = json_decode($text, true);
        if (!is_array($parsed)) {
            throw new RuntimeException('AI response bukan JSON valid: ' . substr($text, 0, 200));
        }

        $result['json'] = $parsed;
        return $result;
    }
}
