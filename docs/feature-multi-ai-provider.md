# Feature Document: Multi-AI Provider Support

**Status:** Proposal  
**Date:** 2026-04-06  
**Author:** AI-assisted analysis

---

## 1. Goal

Allow world owners to choose their AI provider — Anthropic (Claude), OpenAI (ChatGPT), or Google (Gemini) — instead of being locked to Claude. Each world independently selects its provider, model, and API key.

---

## 2. Feasibility Assessment

**Verdict: Fully feasible.** The architecture is clean enough that adding providers is a refactoring exercise, not a rewrite. The main work is extracting the Anthropic-specific code into a provider abstraction and normalizing request/response formats.

All three providers offer compatible capabilities:

| Capability | Anthropic (Claude) | OpenAI (ChatGPT) | Google (Gemini) |
|---|---|---|---|
| System prompt | `system` field | `system` role message | `system_instruction` |
| User/assistant messages | `messages[]` | `messages[]` | `contents[]` |
| Streaming | SSE | SSE | SSE |
| Token counting in response | `usage.input_tokens` / `output_tokens` | `usage.prompt_tokens` / `completion_tokens` | `usageMetadata.promptTokenCount` / `candidatesTokenCount` |
| Max context window | 200k (Sonnet 4) | 128k (GPT-4o) | 1M (Gemini 1.5 Pro) |
| Auth header | `x-api-key` | `Authorization: Bearer` | `x-goog-api-key` or OAuth |
| Key prefix pattern | `sk-ant-…` | `sk-…` | `AI…` |

---

## 3. Current Coupling Points

### 3.1 Backend (PHP)

| File | Coupling | Effort |
|---|---|---|
| `core/Claude.php` | API endpoint, headers, request format, response parsing, error mapping, model defaults | High — this is the main refactoring target |
| `api/AiController.php` | Calls `Claude::` methods, hardcoded model fallback | Medium |
| `api/WorldController.php` | Saves/reads AI key assuming single provider | Low |
| `core/Crypto.php` | Method names (`encryptApiKey`) are generic enough; fingerprint format assumes `sk-ant-` prefix | Low |
| `config/config.example.php` | `PLATFORM_ANTHROPIC_KEY` constant | Low |
| `scripts/consistency-check.php` | Direct `Claude::callApi()` call | Low |

### 3.2 Database

| Table.Column | Issue |
|---|---|
| `worlds.ai_model` | Default value `'claude-sonnet-4-20250514'` | 
| `worlds.ai_key_mode` | Generic enough — works for all providers |
| `worlds.ai_key_enc` | Generic — stores encrypted key regardless of provider |
| `worlds.ai_key_fingerprint` | Generic — just first/last chars of key |
| `ai_sessions.model` | Generic — already stores the model string used |
| Missing | No `provider` column on `worlds` |

### 3.3 Frontend

| File | Coupling |
|---|---|
| `AiPanel.vue` | Button says "Ask Claude" |
| `WorldAiSettingsView.vue` | Labels say "Anthropic API Key" |
| `AiResponseCard.vue` | Displays model name (already generic) |

---

## 4. Proposed Architecture

### 4.1 Provider Interface (`core/AiProvider.php`)

```php
interface AiProvider {
    /** Provider identifier: 'anthropic', 'openai', 'google' */
    public static function id(): string;

    /** Human-readable name for UI */
    public static function label(): string;

    /** Available models for this provider */
    public static function models(): array;

    /** Default model when none is configured */
    public static function defaultModel(): string;

    /** Max context tokens for a given model */
    public static function contextBudget(string $model): int;

    /** Send a prompt and return a normalized response */
    public static function call(
        string $systemPrompt,
        array  $messages,       // [{role: 'user'|'assistant', content: string}]
        string $apiKey,
        string $model,
        int    $maxTokens
    ): AiResponse;
}
```

### 4.2 Normalized Response (`core/AiResponse.php`)

```php
class AiResponse {
    public string $text;
    public int    $promptTokens;
    public int    $completionTokens;
    public int    $totalTokens;
    public string $model;
    public string $provider;
}
```

### 4.3 Provider Implementations

```
core/
├── AiProvider.php          # Interface
├── AiResponse.php          # Normalized response DTO
├── providers/
│   ├── AnthropicProvider.php   # Current Claude.php::callApi() logic
│   ├── OpenAiProvider.php      # OpenAI Chat Completions API
│   └── GeminiProvider.php      # Google Generative Language API
├── Claude.php              # Retains context assembly + prompt rendering
│                           # (provider-agnostic); delegates API call to provider
```

### 4.4 Provider Registry

```php
// In Claude.php (renamed to AiEngine.php or kept for backwards compat)
class Claude {
    private static array $providers = [
        'anthropic' => AnthropicProvider::class,
        'openai'    => OpenAiProvider::class,
        'google'    => GeminiProvider::class,
    ];

    public static function getProvider(string $id): AiProvider { ... }
    public static function availableProviders(): array { ... }
}
```

### 4.5 API Format Mapping

**Anthropic Messages API:**
```json
{
  "model": "claude-sonnet-4-20250514",
  "max_tokens": 4096,
  "system": "You are...",
  "messages": [{"role": "user", "content": "..."}]
}
```

**OpenAI Chat Completions API:**
```json
{
  "model": "gpt-4o",
  "max_tokens": 4096,
  "messages": [
    {"role": "system", "content": "You are..."},
    {"role": "user", "content": "..."}
  ]
}
```

**Google Gemini API:**
```json
{
  "system_instruction": {"parts": [{"text": "You are..."}]},
  "contents": [{"role": "user", "parts": [{"text": "..."}]}],
  "generationConfig": {"maxOutputTokens": 4096}
}
```

Each provider implementation translates the normalized input into its native format and normalizes the response back.

---

## 5. Database Migration

```sql
-- Migration: 00X_multi_provider.sql

ALTER TABLE worlds
  ADD COLUMN ai_provider ENUM('anthropic','openai','google')
  NOT NULL DEFAULT 'anthropic'
  AFTER ai_key_mode;

ALTER TABLE worlds
  ALTER COLUMN ai_model SET DEFAULT NULL;

-- ai_sessions already stores the model string; add provider for querying
ALTER TABLE ai_sessions
  ADD COLUMN provider VARCHAR(32) DEFAULT 'anthropic'
  AFTER model;
```

No column renames needed — existing columns are already generic enough.

---

## 6. Config Changes

```php
// config.example.php additions

// Platform-level keys (optional, one per provider)
define('PLATFORM_ANTHROPIC_KEY', '');   // existing
define('PLATFORM_OPENAI_KEY',    '');   // new
define('PLATFORM_GEMINI_KEY',    '');   // new
```

---

## 7. Frontend Changes

| Change | Scope |
|---|---|
| `WorldAiSettingsView.vue` — add provider dropdown (Anthropic / OpenAI / Gemini) | Small |
| `WorldAiSettingsView.vue` — model dropdown populated from provider's model list | Small |
| `WorldAiSettingsView.vue` — key label changes based on provider | Small |
| `AiPanel.vue` — "Ask Claude" → "Ask AI" or dynamic based on provider | Trivial |
| `AiResponseCard.vue` — already generic, no change | None |
| `stores/ai.js` — no change needed | None |

---

## 8. What Stays the Same

These core features are **provider-agnostic** and require zero changes:

- **Context assembly** (`Claude::buildContext()`) — builds system prompt + entity context from DB. This is LoreBuilder logic, not provider logic.
- **Prompt templates** — `{{variable}}` rendering is provider-independent.
- **Token budget management** — the priority-based context trimming works with any model's token limit; just parameterize the budget per model.
- **Key encryption/decryption** — `Crypto::encrypt/decrypt` works identically for any API key string.
- **Rate limiting** — already per-user/per-world, provider-irrelevant.
- **Session logging** — `ai_sessions` already stores model name and token counts generically.
- **Notes integration** — AI responses saved as `lore_notes` regardless of provider.

---

## 9. Implementation Phases

### Phase 1: Refactor (no new providers yet)
1. Create `AiProvider` interface and `AiResponse` DTO
2. Extract `Claude.php::callApi()` into `AnthropicProvider`
3. Rename context assembly into provider-agnostic `AiEngine` (or keep `Claude` class name but with delegation)
4. Add `ai_provider` column to `worlds` (default `'anthropic'`)
5. Update `WorldAiSettingsView.vue` with provider selector
6. **Result:** Same functionality, but architecture is ready for new providers

### Phase 2: OpenAI Support
1. Implement `OpenAiProvider` (Chat Completions API)
2. Add `PLATFORM_OPENAI_KEY` to config
3. Test with GPT-4o and GPT-4o-mini
4. Update model dropdown in settings UI

### Phase 3: Gemini Support
1. Implement `GeminiProvider` (Generative Language API)
2. Add `PLATFORM_GEMINI_KEY` to config
3. Test with Gemini 1.5 Pro and Gemini 1.5 Flash
4. Update model dropdown in settings UI

### Phase 4 (optional): Ollama / Local Models
1. Implement `OllamaProvider` (OpenAI-compatible API on localhost)
2. Configure custom endpoint URL per world
3. No API key required for local models

---

## 10. Effort Estimate

| Phase | Files Changed | New Files | Complexity |
|---|---|---|---|
| Phase 1 (Refactor) | ~6 | 3 (`AiProvider.php`, `AiResponse.php`, `AnthropicProvider.php`) + 1 migration | Medium |
| Phase 2 (OpenAI) | 2 | 1 (`OpenAiProvider.php`) | Low |
| Phase 3 (Gemini) | 2 | 1 (`GeminiProvider.php`) | Low |

Phase 1 is the bulk of the work. Each subsequent provider is ~100-150 lines of PHP (request formatting + response parsing) and a trivial frontend dropdown entry.

---

## 11. Risks & Considerations

| Risk | Mitigation |
|---|---|
| Different providers have different strengths for creative writing | Let users choose; document recommendations |
| Token counting differs slightly between providers | Normalize in each provider; use provider-reported usage, not our own estimates |
| Context window sizes vary (128k vs 200k vs 1M) | `contextBudget()` method per provider/model; budget trimming already handles this |
| Rate limits differ per provider | Keep existing per-user/per-world limits (ours); provider-side 429s already handled |
| API key formats differ | `apiKeyFingerprint()` already shows first/last chars — works for any format |
| Prompt quality may vary by provider | System prompts are already well-structured; may need minor provider-specific tuning in Phase 2/3 |
| OpenAI charges differently (per-token vs per-request tiers) | Token tracking already in place; budget system works regardless |
