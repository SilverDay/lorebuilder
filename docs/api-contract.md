# LoreBuilder — REST API Contract
# Version: 1.0 | Base: /api/v1

## Auth Endpoints

POST   /auth/register        { username, email, display_name, password }
POST   /auth/login           { username, password, totp_code? }
POST   /auth/logout
POST   /auth/refresh-csrf    → { csrf_token }
POST   /auth/password-reset  { email }
POST   /auth/password-reset/confirm { token, new_password }
GET    /auth/me              → current user profile

## User Endpoints

GET    /users/me                         → profile
PATCH  /users/me                         { display_name, email }
POST   /users/me/change-password         { current_password, new_password }
POST   /users/me/totp/enable             { totp_code }   ← verify before enable
DELETE /users/me/totp                    { password }
DELETE /users/me                         { password }    ← account deletion

## World Endpoints

GET    /worlds                           → worlds the user is a member of
POST   /worlds                           { name, slug, genre, tone, era_system, description }
GET    /worlds/{wid}                     → world detail + member's role
PATCH  /worlds/{wid}                     { name, genre, tone, era_system, ... }  [owner/admin]
DELETE /worlds/{wid}                     [owner only — soft delete]

GET    /worlds/{wid}/members             [owner/admin]
POST   /worlds/{wid}/members/invite      { email, role }  [owner/admin]
PATCH  /worlds/{wid}/members/{uid}/role  { role }         [owner/admin]
DELETE /worlds/{wid}/members/{uid}       [owner/admin or self-remove]

GET    /worlds/{wid}/invitations         [owner/admin]
DELETE /worlds/{wid}/invitations/{id}    [owner/admin]
POST   /invitations/{token}/accept       ← public (requires valid token + auth)

## AI Key Settings

POST   /worlds/{wid}/settings/ai-key    { key: "sk-ant-…" }  → { saved: true, fingerprint }
DELETE /worlds/{wid}/settings/ai-key    → removes stored key
GET    /worlds/{wid}/settings/ai        → { key_mode, fingerprint, model, budget, tokens_used }
PATCH  /worlds/{wid}/settings/ai        { ai_key_mode, ai_model, ai_token_budget }  [owner]

## Entity Endpoints

GET    /worlds/{wid}/entities            ?type=&status=&tag=&q=&page=&per_page=
POST   /worlds/{wid}/entities            { type, name, slug?, short_summary, status, lore_body, attributes }
GET    /worlds/{wid}/entities/{eid}      → full detail incl. relationships, notes preview, arcs, timeline positions
PATCH  /worlds/{wid}/entities/{eid}      { name?, short_summary?, status?, lore_body?, attributes? }
DELETE /worlds/{wid}/entities/{eid}      → soft delete (status=archived, deleted_at set)

GET    /worlds/{wid}/entities/{eid}/relationships   → all edges in + out
POST   /worlds/{wid}/entities/{eid}/tags            { tag_id }
DELETE /worlds/{wid}/entities/{eid}/tags/{tagId}

## Relationship Endpoints

GET    /worlds/{wid}/relationships                   ?from=&to=&type=
POST   /worlds/{wid}/relationships                   { from_entity_id, to_entity_id, rel_type, is_bidirectional, strength, notes }
PATCH  /worlds/{wid}/relationships/{rid}             { rel_type?, strength?, notes?, is_bidirectional? }
DELETE /worlds/{wid}/relationships/{rid}

## Tag Endpoints

GET    /worlds/{wid}/tags
POST   /worlds/{wid}/tags               { name, color }
PATCH  /worlds/{wid}/tags/{tid}         { name?, color? }
DELETE /worlds/{wid}/tags/{tid}

## Timeline Endpoints

GET    /worlds/{wid}/timelines
POST   /worlds/{wid}/timelines          { name, description, scale_mode, era_labels?, color }
GET    /worlds/{wid}/timelines/{tid}    → timeline + ordered events
PATCH  /worlds/{wid}/timelines/{tid}
DELETE /worlds/{wid}/timelines/{tid}

GET    /worlds/{wid}/timelines/{tid}/events
POST   /worlds/{wid}/timelines/{tid}/events    { entity_id?, label, description, position_order, position_era?, position_value?, position_label?, color? }
PATCH  /worlds/{wid}/timelines/{tid}/events/{eid}
DELETE /worlds/{wid}/timelines/{tid}/events/{eid}
POST   /worlds/{wid}/timelines/{tid}/events/reorder   { order: [id1, id2, id3, …] }

## Story Arc Endpoints

GET    /worlds/{wid}/story-arcs
POST   /worlds/{wid}/story-arcs         { name, logline, theme, status }
GET    /worlds/{wid}/story-arcs/{aid}   → arc + entity participants
PATCH  /worlds/{wid}/story-arcs/{aid}   { name?, logline?, theme?, status? }
DELETE /worlds/{wid}/story-arcs/{aid}

POST   /worlds/{wid}/story-arcs/{aid}/entities          { entity_id, role?, notes? }
PATCH  /worlds/{wid}/story-arcs/{aid}/entities/{eid}    { role?, notes? }
DELETE /worlds/{wid}/story-arcs/{aid}/entities/{eid}

## Lore Note Endpoints

GET    /worlds/{wid}/notes              ?entity_id=&canonical=&ai_generated=&page=
POST   /worlds/{wid}/notes              { entity_id?, content, is_canonical? }
GET    /worlds/{wid}/notes/{nid}
PATCH  /worlds/{wid}/notes/{nid}        { content?, is_canonical? }
DELETE /worlds/{wid}/notes/{nid}

## Prompt Template Endpoints

GET    /worlds/{wid}/prompt-templates                   → world overrides + platform defaults
POST   /worlds/{wid}/prompt-templates                   { mode, name, system_tpl, user_tpl }  [owner/admin]
PATCH  /worlds/{wid}/prompt-templates/{tid}             [owner/admin]
DELETE /worlds/{wid}/prompt-templates/{tid}             [owner/admin]

## AI Endpoints

POST   /worlds/{wid}/ai/assist          { mode, entity_id?, arc_id?, timeline_id?, entity_b_id?, user_prompt }
GET    /worlds/{wid}/ai/sessions        ?page=&per_page=&mode=
GET    /worlds/{wid}/ai/sessions/{sid}  → session metadata (no keys, no full prompt)
POST   /worlds/{wid}/ai/consistency-check   → triggers full world check; returns findings JSON

## Search

GET    /worlds/{wid}/search             ?q=&types=&tags=&status=&page=

## Graph Data

GET    /worlds/{wid}/graph              → { nodes: [...], edges: [...] }
        nodes: { id, name, type, status, slug }
        edges: { id, from, to, rel_type, strength, is_bidirectional }

## Export / Import

GET    /worlds/{wid}/export             ?format=json|markdown   [owner/admin]
POST   /worlds/{wid}/import             multipart/form-data { file }  [owner only]

## Audit Log

GET    /worlds/{wid}/audit              ?page=&action=&user_id=  [owner/admin]

## Platform Admin (is_platform_admin only)

GET    /admin/users
PATCH  /admin/users/{uid}              { is_active, is_platform_admin }
GET    /admin/worlds
GET    /admin/stats                    → aggregate usage stats
