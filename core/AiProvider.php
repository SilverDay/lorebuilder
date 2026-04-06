<?php
/**
 * LoreBuilder — AI Provider Interface
 *
 * All AI providers (Anthropic, OpenAI, Google, etc.) implement this interface
 * to ensure a consistent contract for the AiEngine.
 *
 * Each provider translates between LoreBuilder's normalized request/response
 * format and the provider's native API format.
 */

declare(strict_types=1);

class AiResponse
{
    public function __construct(
        public readonly string $text,
        public readonly int    $promptTokens,
        public readonly int    $completionTokens,
        public readonly int    $totalTokens,
        public readonly string $model,
        public readonly string $provider,
    ) {}
}

class AiProviderException extends \RuntimeException {}

interface AiProvider
{
    /** Provider identifier: 'anthropic', 'openai', 'google', etc. */
    public static function id(): string;

    /** Human-readable name for UI display. */
    public static function label(): string;

    /** Available models for this provider: ['model-id' => 'Display Name', ...] */
    public static function models(): array;

    /** Default model when none is configured. */
    public static function defaultModel(): string;

    /** Max context tokens for a given model. */
    public static function contextBudget(string $model): int;

    /**
     * Send a prompt and return a normalized AiResponse.
     *
     * SECURITY: $apiKey must NEVER be logged, stored, or returned.
     *
     * @param string $systemPrompt  Assembled system prompt
     * @param string $userMessage   User's request
     * @param string $apiKey        Plaintext API key (decrypted by caller)
     * @param string $model         Provider-specific model identifier
     * @param int    $maxTokens     Max completion tokens
     * @return AiResponse
     * @throws AiProviderException  On network error, auth failure, or API error
     */
    public static function call(
        string $systemPrompt,
        string $userMessage,
        string $apiKey,
        string $model,
        int    $maxTokens = 4096
    ): AiResponse;
}
