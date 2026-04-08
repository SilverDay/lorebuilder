<?php

/**
 * LoreBuilder — Anthropic (Claude) Provider
 *
 * Implements the AiProvider interface for Anthropic's Messages API.
 * Uses PHP streams (no cURL dependency).
 *
 * SECURITY: API key is NEVER logged, stored, or returned.
 */

declare(strict_types=1);

class AnthropicProvider implements AiProvider
{
    private const API_ENDPOINT    = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION     = '2023-06-01';
    private const CONNECT_TIMEOUT = 10;
    private const READ_TIMEOUT    = 60;

    public static function id(): string
    {
        return 'anthropic';
    }

    public static function label(): string
    {
        return 'Anthropic (Claude)';
    }

    public static function models(): array
    {
        return [
            'claude-sonnet-4-20250514' => 'Claude Sonnet 4',
            'claude-opus-4-20250514'   => 'Claude Opus 4',
            'claude-haiku-4-20250514'  => 'Claude Haiku 4',
        ];
    }

    public static function defaultModel(): string
    {
        return 'claude-sonnet-4-20250514';
    }

    public static function contextBudget(string $model): int
    {
        // All current Claude 4 models support 200k context
        return 150_000;
    }

    public static function call(
        string $systemPrompt,
        string $userMessage,
        string $apiKey,
        string $model,
        int    $maxTokens = 4096
    ): AiResponse {
        if (empty(trim($apiKey))) {
            throw new AiProviderException('API key is missing.');
        }
        if (empty(trim($userMessage))) {
            throw new AiProviderException('User prompt is empty.');
        }

        $payload = json_encode([
            'model'      => $model,
            'max_tokens' => $maxTokens,
            'system'     => $systemPrompt,
            'messages'   => [
                ['role' => 'user', 'content' => $userMessage],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $opts = [
            'http' => [
                'method'          => 'POST',
                'header'          => implode("\r\n", [
                    'Content-Type: application/json',
                    'x-api-key: ' . $apiKey,
                    'anthropic-version: ' . self::API_VERSION,
                    'Content-Length: ' . strlen($payload),
                ]),
                'content'         => $payload,
                'timeout'         => self::READ_TIMEOUT,
                'ignore_errors'   => true,
                'follow_location' => false,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ];

        $streamCtx = stream_context_create($opts);
        $response  = @file_get_contents(self::API_ENDPOINT, false, $streamCtx);

        $statusCode = self::extractStatusCode($http_response_header ?? []);

        if ($response === false) {
            error_log('[AnthropicProvider] Network error calling Anthropic API (no response body)');
            throw new AiProviderException('Failed to reach Anthropic API. Check network connectivity.');
        }

        $body = json_decode($response, associative: true, flags: JSON_THROW_ON_ERROR);

        if ($statusCode !== 200) {
            $errType = $body['error']['type']    ?? 'unknown_error';
            $errMsg  = $body['error']['message'] ?? 'No error message returned.';

            $mapped = match ($errType) {
                'authentication_error'  => 'API key is invalid or revoked.',
                'permission_error'      => 'API key lacks permission for this model.',
                'rate_limit_error'      => 'Anthropic rate limit reached. Try again later.',
                'overloaded_error'      => 'Anthropic API is overloaded. Try again later.',
                'invalid_request_error' => "Invalid API request: {$errMsg}",
                default                 => "Anthropic API error ({$errType}).",
            };

            error_log("[AnthropicProvider] API error {$statusCode} — {$errType}: {$errMsg}");
            throw new AiProviderException($mapped);
        }

        // Extract response text from Anthropic's content blocks
        $text = '';
        foreach ($body['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            }
        }

        $usage = $body['usage'] ?? [];
        $inputTokens  = (int) ($usage['input_tokens']  ?? 0);
        $outputTokens = (int) ($usage['output_tokens'] ?? 0);

        return new AiResponse(
            text: $text,
            promptTokens: $inputTokens,
            completionTokens: $outputTokens,
            totalTokens: $inputTokens + $outputTokens,
            model: $body['model'] ?? $model,
            provider: self::id(),
        );
    }

    private static function extractStatusCode(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/[\d.]+ (\d{3})/', $header, $m)) {
                return (int) $m[1];
            }
        }
        return 0;
    }
}
