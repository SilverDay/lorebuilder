<?php
/**
 * LoreBuilder — Google Gemini Provider
 *
 * Implements the AiProvider interface for Google's Generative Language API.
 * Uses PHP streams (no cURL dependency).
 *
 * SECURITY: API key is NEVER logged, stored, or returned.
 */

declare(strict_types=1);

class GeminiProvider implements AiProvider
{
    private const API_BASE       = 'https://generativelanguage.googleapis.com/v1beta/models/';
    private const CONNECT_TIMEOUT = 10;
    private const READ_TIMEOUT    = 60;

    public static function id(): string
    {
        return 'google';
    }

    public static function label(): string
    {
        return 'Google (Gemini)';
    }

    public static function models(): array
    {
        return [
            'gemini-2.5-pro'   => 'Gemini 2.5 Pro',
            'gemini-2.5-flash' => 'Gemini 2.5 Flash',
            'gemini-2.0-flash' => 'Gemini 2.0 Flash',
        ];
    }

    public static function defaultModel(): string
    {
        return 'gemini-2.5-flash';
    }

    public static function contextBudget(string $model): int
    {
        return match ($model) {
            'gemini-2.5-pro'   => 800_000,
            'gemini-2.5-flash' => 800_000,
            'gemini-2.0-flash' => 800_000,
            default            => 200_000,
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

        $endpoint = self::API_BASE . $model . ':generateContent?key=' . $apiKey;

        $payload = json_encode([
            'system_instruction' => [
                'parts' => [['text' => $systemPrompt]],
            ],
            'contents' => [
                [
                    'role'  => 'user',
                    'parts' => [['text' => $userMessage]],
                ],
            ],
            'generationConfig' => [
                'maxOutputTokens' => $maxTokens,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $opts = [
            'http' => [
                'method'          => 'POST',
                'header'          => implode("\r\n", [
                    'Content-Type: application/json',
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
        $response  = @file_get_contents($endpoint, false, $streamCtx);

        $statusCode = self::extractStatusCode($http_response_header ?? []);

        if ($response === false) {
            error_log('[GeminiProvider] Network error calling Gemini API (no response body)');
            throw new AiProviderException('Failed to reach Google Gemini API. Check network connectivity.');
        }

        $body = json_decode($response, associative: true, flags: JSON_THROW_ON_ERROR);

        if ($statusCode !== 200) {
            $errMsg    = $body['error']['message'] ?? 'No error message returned.';
            $errStatus = $body['error']['status']  ?? 'UNKNOWN';

            $mapped = match ($errStatus) {
                'UNAUTHENTICATED'          => 'API key is invalid or revoked.',
                'PERMISSION_DENIED'        => 'API key lacks permission for this model.',
                'RESOURCE_EXHAUSTED'       => 'Google API quota exhausted. Try again later.',
                'INVALID_ARGUMENT'         => "Invalid API request: {$errMsg}",
                'UNAVAILABLE', 'INTERNAL'  => 'Gemini API error. Try again later.',
                default                    => "Gemini API error ({$errStatus}): {$errMsg}",
            };

            error_log("[GeminiProvider] API error {$statusCode} — {$errStatus}: {$errMsg}");
            throw new AiProviderException($mapped);
        }

        // Extract response text from Gemini's candidates
        $text = '';
        $candidates = $body['candidates'] ?? [];
        if (!empty($candidates)) {
            $parts = $candidates[0]['content']['parts'] ?? [];
            foreach ($parts as $part) {
                if (isset($part['text'])) {
                    $text .= $part['text'];
                }
            }
        }

        $usage        = $body['usageMetadata'] ?? [];
        $inputTokens  = (int) ($usage['promptTokenCount']     ?? 0);
        $outputTokens = (int) ($usage['candidatesTokenCount'] ?? 0);

        return new AiResponse(
            text:             $text,
            promptTokens:     $inputTokens,
            completionTokens: $outputTokens,
            totalTokens:      $inputTokens + $outputTokens,
            model:            $model,
            provider:         self::id(),
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
