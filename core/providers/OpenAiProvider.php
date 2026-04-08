<?php

/**
 * LoreBuilder — OpenAI (ChatGPT) Provider
 *
 * Implements the AiProvider interface for OpenAI's Chat Completions API.
 * Uses PHP streams (no cURL dependency).
 *
 * SECURITY: API key is NEVER logged, stored, or returned.
 */

declare(strict_types=1);

class OpenAiProvider implements AiProvider
{
    private const API_ENDPOINT    = 'https://api.openai.com/v1/chat/completions';
    private const CONNECT_TIMEOUT = 10;
    private const READ_TIMEOUT    = 60;

    public static function id(): string
    {
        return 'openai';
    }

    public static function label(): string
    {
        return 'OpenAI (ChatGPT)';
    }

    public static function models(): array
    {
        return [
            'gpt-4o'        => 'GPT-4o',
            'gpt-4o-mini'   => 'GPT-4o Mini',
            'gpt-4.1'       => 'GPT-4.1',
            'gpt-4.1-mini'  => 'GPT-4.1 Mini',
            'gpt-4.1-nano'  => 'GPT-4.1 Nano',
            'o3-mini'       => 'o3 Mini',
        ];
    }

    public static function defaultModel(): string
    {
        return 'gpt-4o';
    }

    public static function contextBudget(string $model): int
    {
        return match ($model) {
            'gpt-4o', 'gpt-4o-mini'           => 100_000,
            'gpt-4.1', 'gpt-4.1-mini'         => 150_000,
            'gpt-4.1-nano'                     => 100_000,
            'o3-mini'                          => 150_000,
            default                            => 100_000,
        };
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
            'messages'   => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userMessage],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $opts = [
            'http' => [
                'method'          => 'POST',
                'header'          => implode("\r\n", [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey,
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
            error_log('[OpenAiProvider] Network error calling OpenAI API (no response body)');
            throw new AiProviderException('Failed to reach OpenAI API. Check network connectivity.');
        }

        $body = json_decode($response, associative: true, flags: JSON_THROW_ON_ERROR);

        if ($statusCode !== 200) {
            $errType = $body['error']['type']    ?? 'unknown_error';
            $errMsg  = $body['error']['message'] ?? 'No error message returned.';

            $mapped = match ($errType) {
                'invalid_api_key', 'authentication_error' => 'API key is invalid or revoked.',
                'insufficient_quota'                      => 'OpenAI quota exceeded. Check your billing.',
                'rate_limit_exceeded'                     => 'OpenAI rate limit reached. Try again later.',
                'server_error'                            => 'OpenAI API error. Try again later.',
                default                                   => "OpenAI API error ({$errType}): {$errMsg}",
            };

            error_log("[OpenAiProvider] API error {$statusCode} — {$errType}: {$errMsg}");
            throw new AiProviderException($mapped);
        }

        // Extract response text from OpenAI's choices
        $text = $body['choices'][0]['message']['content'] ?? '';

        $usage         = $body['usage'] ?? [];
        $inputTokens   = (int) ($usage['prompt_tokens']     ?? 0);
        $outputTokens  = (int) ($usage['completion_tokens']  ?? 0);

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
