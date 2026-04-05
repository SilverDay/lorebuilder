# LoreBuilder — AI Engine Design
# Claude Integration: Context Assembly, Prompt Rendering & Key Handling
# Version: 1.0 | SilverDay Media

---

## 1. Overview

The AI engine is the most sensitive component of LoreBuilder.
It touches user-provided API keys, assembles potentially large context windows,
and proxies calls to the Anthropic API. Every design decision here prioritises:

1. **Key security** — keys are decrypted in memory for the minimum possible duration
2. **Context quality** — more relevant context = better AI output
3. **Cost control** — token budget management prevents surprise bills
4. **Auditability** — every call is logged (without the key or full response text)
5. **Graceful degradation** — AI unavailability must not break core CRUD features

---

## 2. Request Lifecycle

```
POST /api/v1/worlds/{worldId}/ai/assist
  Body: {
    mode: "entity_assist",
    entity_id: 42,          // optional, mode-dependent
    arc_id: null,           // optional
    timeline_id: null,      // optional
    entity_b_id: null,      // optional (relationship_infer mode)
    user_prompt: "Write a tragic backstory for this character."
  }

1.  Auth::requireSession()
      → 401 if no valid session

2.  Guard::requireWorldAccess($worldId, $userId, minRole: 'author')
      → 403 if not member or insufficient role

3.  RateLimit::check("ai:user:{$userId}", limit: 20, window: 3600)
    RateLimit::check("ai:world:{$worldId}", limit: 100, window: 3600)
      → 429 with retry_after if exceeded

4.  AiController::assist()
      → Validate request body (mode, entity_id etc.)
      → Resolve template: world-specific override or platform default
      → Load API key (decrypt in memory, zero after use)
      → Check world token budget (worlds.ai_token_budget vs ai_tokens_used)

5.  Claude::buildContext($params)
      → Assemble context object (see Section 4)
      → Render system + user prompt from template

6.  Claude::callApi($renderedPrompt, $apiKey)
      → POST to https://api.anthropic.com/v1/messages
      → sodium_memzero($apiKey) immediately after request dispatch
      → Parse response

7.  Persist results
      → INSERT ai_sessions (tokens, model, status — no key, no full text)
      → INSERT lore_notes (content=response, ai_generated=1, ai_session_id)
      → UPDATE worlds SET ai_tokens_used = ai_tokens_used + $totalTokens

8.  Return to client
      → { note_id: 789, content: "...", tokens_used: 412, budget_remaining: 987432 }
      → NEVER include api_key, prompt hash, or internal IDs beyond note_id
```

---

## 3. API Key Resolution

```php
// core/Claude.php — resolveApiKey()

private function resolveApiKey(World $world): string
{
    return match ($world->ai_key_mode) {

        'user' => (function () use ($world) {
            if (empty($world->ai_key_enc)) {
                throw new AiKeyMissingException();
            }
            return Crypto::decryptApiKey($world->ai_key_enc, APP_SECRET);
            // Caller is responsible for sodium_memzero() after use
        })(),

        'platform' => (function () {
            if (!defined('PLATFORM_ANTHROPIC_KEY') || empty(PLATFORM_ANTHROPIC_KEY)) {
                throw new AiKeyMissingException('Platform key not configured');
            }
            return PLATFORM_ANTHROPIC_KEY;
            // Defined in config.php, never in DB
        })(),

        'oauth' => (function () use ($world) {
            // Phase 2 placeholder
            throw new \RuntimeException('OAuth key mode not yet implemented', 501);
        })(),

        default => throw new AiKeyMissingException('Unknown key mode'),
    };
}
```

**Critical pattern:** The caller (`callApi()`) must call `sodium_memzero($key)` after
the HTTP request is dispatched — whether it succeeds or throws.

```php
public function callApi(array $prompt, string &$key): array
{
    try {
        $response = $this->http->post('https://api.anthropic.com/v1/messages', [
            'headers' => [
                'x-api-key'         => $key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ],
            'json' => $prompt,
        ]);
        return $response->toArray();
    } finally {
        sodium_memzero($key);  // Always zero the key, even on exception
    }
}
```

---

## 4. Context Assembly

### 4.1 Context Object Structure

```php
// Claude::buildContext() returns this structure
[
    'world' => [
        'name'         => 'Wizard's Castle: Shadows of Zot',
        'genre'        => 'dark fantasy',
        'tone'         => 'gritty, mythic, OSR',
        'era_system'   => 'Age of Zot / The Sundering / The Long Dark / Now',
        'warnings'     => null,
    ],
    'entity' => [
        'name'         => 'Zot the Destroyer',
        'type'         => 'Character',
        'status'       => 'published',
        'attributes'   => ['role' => 'Arch-Lich', 'alignment' => 'Chaotic Evil', ...],
        'relationships' => [
            ['type' => 'rules', 'target' => 'The Obsidian Throne', 'notes' => '...'],
            ['type' => 'fears', 'target' => 'The Orb of Zot',      'notes' => '...'],
        ],
        'notes'        => [...],              // token-limited, newest first
        'arcs'         => [...],
        'timeline_pos' => 'Age of Zot, Year 0 — founding moment',
    ],
    'entity_b' => null,       // populated for relationship_infer mode
    'arc'      => null,       // populated for arc_synthesiser mode
    'timeline' => null,       // populated for timeline_narrator mode
]
```

### 4.2 Token Budget & Trimming

Target: stay within 80% of the model's context window (claude-sonnet-4: 200k tokens).
In practice, context is typically 2000-8000 tokens. Budget is enforced per-call.

```
Priority (highest = last to be dropped):
  P1 NEVER DROP  : World config block
  P1 NEVER DROP  : Target entity (name, type, status, attributes)
  P2 drop at 92% : Related entity attribute summaries
  P3 drop at 88% : Timeline position
  P4 drop at 85% : Arc membership + logline
  P5 drop at 80% : First-degree relationship notes (keep rel_type + name, drop notes)
  P6 trim at 60% : Lore notes (trim oldest first, keep newest N fitting budget)
```

Token counting: use a character-based approximation (4 chars ≈ 1 token) for budget
checks before sending. Exact counts come back in the API response and are stored.

### 4.3 Prompt Template Rendering

```php
// core/Claude.php — renderTemplate()

public function renderTemplate(string $template, array $context): string
{
    // Resolve {{variable.path}} using dot notation
    return preg_replace_callback(
        '/\{\{([a-z_.]+)\}\}/',
        function ($matches) use ($context) {
            $value = $this->dotGet($context, $matches[1]);
            if ($value === null) return '';
            if (is_array($value)) return $this->formatArray($value);
            return (string) $value;
        },
        $template
    );
}

// Formats relationship arrays, attribute arrays etc. as readable text blocks
private function formatArray(array $arr): string { ... }

// Dot-notation accessor: dotGet($ctx, 'entity.attributes') → $ctx['entity']['attributes']
private function dotGet(array $arr, string $path): mixed { ... }
```

---

## 5. Response Handling

### 5.1 API Response Parsing

```php
// Expected Anthropic API response structure
// data.content[0].type === 'text'
// data.content[0].text  === AI response

private function parseResponse(array $apiResponse): array
{
    $text = '';
    foreach ($apiResponse['content'] ?? [] as $block) {
        if ($block['type'] === 'text') {
            $text .= $block['text'];
        }
    }

    return [
        'text'              => $text,
        'prompt_tokens'     => $apiResponse['usage']['input_tokens']  ?? 0,
        'completion_tokens' => $apiResponse['usage']['output_tokens'] ?? 0,
        'model'             => $apiResponse['model'] ?? 'unknown',
        'stop_reason'       => $apiResponse['stop_reason'] ?? null,
    ];
}
```

### 5.2 Error Mapping

| Anthropic HTTP Status | LoreBuilder Response |
|---|---|
| 401 Unauthorized | 422 AI_KEY_INVALID — prompt user to update their API key |
| 429 Too Many Requests | 429 RATE_LIMITED — Anthropic-side limit hit, retry_after from their header |
| 400 Bad Request | 500 INTERNAL_ERROR — log full error, return generic message |
| 500/503 | 502 — AI service unavailable; core app unaffected |
| Timeout (>30s) | 504 — timeout; no tokens consumed, no ai_session logged |

---

## 6. AI Panel — Frontend Design (Vue 3)

### 6.1 AiPanel.vue Behaviour

```
State machine:
  idle → loading → success → idle
               ↘ error   → idle

On mount:
  - Check world.ai_key_fingerprint (via Pinia worldStore)
  - If null and mode is 'user': show "Set your API key in world settings"
  - If null and mode is 'platform': show "Platform AI enabled"

On submit:
  - Disable form, show spinner
  - POST to /api/v1/worlds/{wid}/ai/assist
  - On 200: render response as Markdown via Marked.js + DOMPurify
  - On 429: show countdown timer (retry_after seconds) and re-enable after
  - On 422 AI_KEY_INVALID: show "Your API key was rejected. Update it in settings."
  - On error: show user-friendly message, log to console for debug

Response card actions:
  - Accept → PATCH /api/v1/worlds/{wid}/notes/{id} { is_canonical: true }
  - Edit   → Opens note in Markdown editor modal
  - Discard → DELETE /api/v1/worlds/{wid}/notes/{id}
```

### 6.2 API Key Settings Flow (WorldSettings.vue)

```
User enters API key in <input type="password">
  → keydown.enter or button click
  → POST /api/v1/worlds/{wid}/settings/ai-key { key: "sk-ant-api03-…" }
  → Server: validates format (starts with sk-ant-), encrypts, stores
  → Server returns: { saved: true, fingerprint: "sk-ant-api…X4aB" }
  → Frontend: stores fingerprint in Pinia worldStore
  → Frontend: clears input field immediately
  → Never stores the key anywhere client-side

Key format validation (client-side, non-security):
  /^sk-ant-api\d{2}-[A-Za-z0-9_-]{80,}$/

Server-side: same regex + libsodium encrypt + store
```

---

## 7. Consistency Checker

The consistency checker is a special mode that assembles a **full world snapshot**
rather than a single entity context.

```php
// Claude::buildWorldSnapshot($worldId): array
// Returns a token-efficient structured summary of the entire world:
// - All entities (name, type, status, key attributes only)
// - All relationships (from, to, type)
// - All timeline events in order
// - Active story arcs with participant lists

// Budget: world snapshots are capped at 60k tokens
// If world exceeds cap: prioritise published entities, drop archived
// If still over: summarise relationship list to counts per type
```

Consistency check results are NOT auto-saved to lore_notes.
They are returned as a structured JSON of findings:
```json
{
  "findings": [
    {
      "severity": "high",
      "entities": ["Zot the Destroyer", "The Orb of Zot"],
      "description": "Zot is recorded as fearing the Orb (relationship), yet also recorded as its creator (attribute). Unclear if creator can fear own creation or if this is intentional tension.",
      "suggestion": "Clarify: did Zot create the Orb before understanding its power? Add a lore note explaining the paradox."
    }
  ],
  "checked_entities": 47,
  "checked_relationships": 112,
  "model": "claude-sonnet-4-20250514",
  "tokens_used": 8432
}
```

The frontend renders findings as a filterable list with links to affected entities.
The user can click "Create note" on any finding to save it as a draft lore note.

---

## 8. Token Cost Dashboard

Visible to world owners and admins at `/worlds/{slug}/settings/ai`:

```
Monthly budget:        1,000,000 tokens
Used this month:         123,456 tokens  (12.3%)
Remaining:               876,544 tokens
Resets:                  2026-05-01

Top consumers (this month):
  entity_assist        89,231 tokens  (72%)
  consistency_check    21,450 tokens  (17%)
  arc_synthesiser       8,775 tokens   (7%)
  other                 4,000 tokens   (4%)

Recent sessions: [paginated table from ai_sessions]
```

Warning banner at 80%: "You have used 80% of your monthly AI token budget."
Hard stop at 100%: API calls return AI_BUDGET_EXCEEDED; non-AI features unaffected.
Budget resets monthly on the day stored in worlds.ai_budget_resets_at.
