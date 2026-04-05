# /project:ai-context-test
# Usage: /project:ai-context-test [entity_id] [mode]
# Tests that Claude::buildContext() assembles correct context without making a real API call.

Simulate an AI context assembly for the given entity and mode. Do NOT call the Anthropic API.

## Steps

1. Load entity from DB (use test data or fixtures if DB unavailable).

2. Call Claude::buildContext() with a mock world + entity, capturing the output array.

3. Verify the context object contains:
   - world.name, world.genre, world.tone, world.era_system ✓
   - entity.name, entity.type, entity.status, entity.attributes ✓
   - entity.relationships (array, may be empty) ✓
   - entity.notes (array, token-limited) ✓
   - entity.arcs, entity.timeline_pos ✓

4. Render the system prompt template using Claude::renderTemplate():
   - All {{variables}} must be resolved (no unresolved placeholders in output)
   - Estimated token count must be under 160,000 (80% of 200k)

5. Verify the API key is NOT present anywhere in the rendered prompt or context object.

6. Output:
   ```
   Context assembled: OK
   Template rendered: OK
   Token estimate: ~NNNN tokens
   API key leak check: PASS
   Unresolved variables: none
   ```

If any check fails, identify the failing Claude.php method and the fix needed.
