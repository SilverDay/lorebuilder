<?php

/**
 * LoreBuilder — Ollama (Local Models) Provider
 *
 * Implements the AiProvider interface using Ollama's OpenAI-compatible API.
 * Ollama serves local LLMs via an HTTP endpoint (default: http://localhost:11434).
 *
 * Key differences from cloud providers:
 *   - No API key required (local service)
 *   - Endpoint URL is configurable per world (ai_endpoint_url column)
 *   - Uses OpenAI-compatible /v1/chat/completions format
 *   - TLS verification is skipped for localhost connections
 *
 * SECURITY: No credentials are transmitted. The endpoint must be on a trusted
 * network (localhost or private subnet). The endpoint URL is validated to
 * prevent SSRF attacks.
 */

declare(strict_types=1);

class OllamaProvider implements AiProvider
{
    private const DEFAULT_ENDPOINT = 'http://localhost:11434';
    private const CONNECT_TIMEOUT  = 10;
    private const READ_TIMEOUT     = 120; // Local models can be slower
    private const MAX_RETRIES      = 3;
    private const RETRY_STATUSES   = [429, 500, 502, 503];

    public static function id(): string
    {
        return 'ollama';
    }

    public static function label(): string
    {
        return 'Ollama (Local)';
    }

    public static function models(): array
    {
        return [
            'llama3.1'       => 'Llama 3.1 8B',
            'llama3.1:70b'   => 'Llama 3.1 70B',
            'llama3.3'       => 'Llama 3.3 70B',
            'mistral'        => 'Mistral 7B',
            'mixtral'        => 'Mixtral 8x7B',
            'gemma2'         => 'Gemma 2 9B',
            'gemma2:27b'     => 'Gemma 2 27B',
            'qwen2.5'        => 'Qwen 2.5 7B',
            'qwen2.5:32b'    => 'Qwen 2.5 32B',
            'deepseek-r1'    => 'DeepSeek R1',
            'command-r'      => 'Command R',
            'phi3'           => 'Phi-3',
        ];
    }

    public static function defaultModel(): string
    {
        return 'llama3.1';
    }

    public static function contextBudget(string $model): int
    {
        return match (true) {
            str_contains($model, 'llama3')    => 100_000,
            str_contains($model, 'mistral')   => 28_000,
            str_contains($model, 'mixtral')   => 28_000,
            str_contains($model, 'gemma')     => 6_000,
            str_contains($model, 'qwen')      => 100_000,
            str_contains($model, 'deepseek')  => 100_000,
            str_contains($model, 'command')   => 100_000,
            str_contains($model, 'phi')       => 100_000,
            default                           => 8_000,
        };
    }

    /**
     * Call Ollama's OpenAI-compatible chat completions endpoint.
     *
     * The $apiKey parameter is accepted for interface compliance but is not
     * sent to Ollama (local models don't require authentication).
     * If a non-empty key is provided, it will be sent as a Bearer token
     * for setups that use an auth proxy in front of Ollama.
     */
    public static function call(
        string $systemPrompt,
        string $userMessage,
        string $apiKey,
        string $model,
        int    $maxTokens = 4096
    ): AiResponse {
        if (empty(trim($userMessage))) {
            throw new AiProviderException('User prompt is empty.');
        }

        // Endpoint is resolved by AiEngine before call; passed via static context
        $endpoint = self::$currentEndpoint ?: self::DEFAULT_ENDPOINT;
        $url      = rtrim($endpoint, '/') . '/v1/chat/completions';

        $payload = json_encode([
            'model'      => $model,
            'max_tokens' => $maxTokens,
            'messages'   => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userMessage],
            ],
            'stream' => false,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $headers = [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload),
        ];

        // Send Bearer token if provided (for proxy-auth setups)
        if (!empty(trim($apiKey))) {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }

        $isLocalhost = self::isLocalEndpoint($endpoint);

        $opts = [
            'http' => [
                'method'          => 'POST',
                'header'          => implode("\r\n", $headers),
                'content'         => $payload,
                'timeout'         => self::READ_TIMEOUT,
                'ignore_errors'   => true,
                'follow_location' => false,
            ],
            'ssl' => [
                // Only verify TLS for non-localhost endpoints
                'verify_peer'      => !$isLocalhost,
                'verify_peer_name' => !$isLocalhost,
            ],
        ];

        $streamCtx = stream_context_create($opts);

        $response   = false;
        $statusCode = 0;

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $response   = @file_get_contents($url, false, $streamCtx);
            $statusCode = self::extractStatusCode($http_response_header ?? []);

            if ($response === false) {
                error_log("[OllamaProvider] Attempt {$attempt}/" . self::MAX_RETRIES . " — network error reaching {$endpoint}");
                if ($attempt < self::MAX_RETRIES) {
                    usleep($attempt * 1_000_000);
                    continue;
                }
                throw new AiProviderException(
                    'Failed to reach Ollama at ' . htmlspecialchars($endpoint, ENT_QUOTES, 'UTF-8') .
                    ' after ' . self::MAX_RETRIES . ' attempts. Is Ollama running?'
                );
            }

            if (in_array($statusCode, self::RETRY_STATUSES, true)) {
                error_log("[OllamaProvider] Attempt {$attempt}/" . self::MAX_RETRIES . " — retryable HTTP {$statusCode}");
                if ($attempt < self::MAX_RETRIES) {
                    usleep($attempt * 1_000_000);
                    continue;
                }
            }

            break;
        }

        $body = json_decode($response, associative: true, flags: JSON_THROW_ON_ERROR);

        if ($statusCode !== 200) {
            $errMsg = $body['error']['message'] ?? $body['error'] ?? 'Unknown error';
            if (is_array($errMsg)) {
                $errMsg = json_encode($errMsg);
            }

            $mapped = match (true) {
                str_contains((string) $errMsg, 'model') && str_contains((string) $errMsg, 'not found')
                    => "Model '{$model}' is not available. Run: ollama pull {$model}",
                $statusCode === 401 || $statusCode === 403
                    => 'Authentication failed for Ollama endpoint.',
                default
                    => 'Ollama error: ' . htmlspecialchars((string) $errMsg, ENT_QUOTES, 'UTF-8'),
            };

            error_log("[OllamaProvider] API error {$statusCode}: {$errMsg}");
            throw new AiProviderException($mapped);
        }

        // Parse OpenAI-compatible response format
        $text = $body['choices'][0]['message']['content'] ?? '';

        $usage        = $body['usage'] ?? [];
        $inputTokens  = (int) ($usage['prompt_tokens']     ?? 0);
        $outputTokens = (int) ($usage['completion_tokens']  ?? 0);

        return new AiResponse(
            text: $text,
            promptTokens: $inputTokens,
            completionTokens: $outputTokens,
            totalTokens: $inputTokens + $outputTokens,
            model: $body['model'] ?? $model,
            provider: self::id(),
        );
    }

    // ─── Endpoint Configuration ───────────────────────────────────────────────

    /** @var string|null Endpoint URL set by AiEngine before calling this provider */
    private static ?string $currentEndpoint = null;

    /**
     * Set the endpoint URL for the next call.
     * Called by AiEngine::callApi() before delegating to this provider.
     */
    public static function setEndpoint(?string $url): void
    {
        self::$currentEndpoint = $url;
    }

    /**
     * Validate an endpoint URL for SSRF prevention.
     *
     * Only allows:
     *   - localhost / 127.0.0.1 / ::1
     *   - Private network ranges (10.x, 172.16-31.x, 192.168.x)
     *   - http:// or https:// schemes only
     *
     * @throws AiProviderException If URL is not allowed
     */
    public static function validateEndpoint(string $url): string
    {
        $parsed = parse_url($url);
        if ($parsed === false || empty($parsed['scheme']) || empty($parsed['host'])) {
            throw new AiProviderException('Invalid endpoint URL.');
        }

        $scheme = strtolower($parsed['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new AiProviderException('Endpoint must use http:// or https://.');
        }

        $host = strtolower($parsed['host']);

        // Resolve hostname to IP for validation
        if ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1') {
            return $url;
        }

        // Check for private network IPs
        $ip = filter_var($host, FILTER_VALIDATE_IP);
        if ($ip === false) {
            // Try DNS resolution
            $ip = gethostbyname($host);
            if ($ip === $host) {
                throw new AiProviderException('Cannot resolve endpoint hostname.');
            }
        }

        if (!self::isPrivateIp($ip)) {
            throw new AiProviderException(
                'Ollama endpoint must be on localhost or a private network. ' .
                'Public endpoints are not allowed for security reasons.'
            );
        }

        return $url;
    }

    // ─── Internals ────────────────────────────────────────────────────────────

    private static function isLocalEndpoint(string $endpoint): bool
    {
        $parsed = parse_url($endpoint);
        $host   = strtolower($parsed['host'] ?? '');
        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    private static function isPrivateIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_RES_RANGE
        ) !== false && filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE
        ) === false;
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
