<?php
/**
 * LoreBuilder — Application Bootstrap
 *
 * Single entry point for all requests.
 * Apache routes every request here via .htaccess.
 *
 * Boot order:
 *   1. Config constants
 *   2. Core library files
 *   3. API controller files
 *   4. Security headers
 *   5. Session start
 *   6. Route table
 *   7. Dispatch
 */

declare(strict_types=1);

// ─── 1. Configuration ─────────────────────────────────────────────────────────

$configPath = __DIR__ . '/../config/config.php';
if (!file_exists($configPath)) {
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Server configuration missing.', 'code' => 'INTERNAL_ERROR']);
    exit;
}
require $configPath;

// ─── 2. Core Library ──────────────────────────────────────────────────────────

require __DIR__ . '/../core/DB.php';
require __DIR__ . '/../core/Auth.php';
require __DIR__ . '/../core/Guard.php';
require __DIR__ . '/../core/Crypto.php';
require __DIR__ . '/../core/RateLimit.php';
require __DIR__ . '/../core/Validator.php';
require __DIR__ . '/../core/Router.php';

// ─── 3. Controllers ───────────────────────────────────────────────────────────

require __DIR__ . '/../api/AuthController.php';
require __DIR__ . '/../api/WorldController.php';
require __DIR__ . '/../api/EntityController.php';
require __DIR__ . '/../api/RelationshipController.php';
require __DIR__ . '/../api/NoteController.php';
require __DIR__ . '/../api/TimelineController.php';
require __DIR__ . '/../api/StoryArcController.php';
require __DIR__ . '/../api/AiController.php';
require __DIR__ . '/../api/ExportController.php';
require __DIR__ . '/../api/ReferenceController.php';
require __DIR__ . '/../api/OpenPointsController.php';

// ─── 4. Security Headers ──────────────────────────────────────────────────────

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Permitted-Cross-Domain-Policies: none');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

// Content-Security-Policy — tightened once the SPA asset hashes are known.
// 'unsafe-inline' is temporary for development; remove before production launch.
header(
    "Content-Security-Policy: default-src 'self'; " .
    "script-src 'self'; " .
    "style-src 'self' 'unsafe-inline'; " .
    "img-src 'self' data:; " .
    "connect-src 'self'; " .
    "font-src 'self'; " .
    "frame-ancestors 'none'"
);

if (!defined('APP_DEBUG') || !APP_DEBUG) {
    // HSTS only in production (requires valid TLS)
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// All API responses are JSON; SPA fallback will override Content-Type
header('Content-Type: application/json; charset=utf-8');

// ─── 5. Session ───────────────────────────────────────────────────────────────

Auth::startSession();

// ─── 6. Routes ────────────────────────────────────────────────────────────────

// ── Authentication ────────────────────────────────────────────────────────────

Router::get( '/api/v1/auth/csrf',                 [AuthController::class, 'csrf'],            auth: false, csrf: false);
Router::post('/api/v1/auth/register',             [AuthController::class, 'register'],        auth: false, csrf: false);
Router::post('/api/v1/auth/login',                [AuthController::class, 'login'],           auth: false, csrf: false);
Router::post('/api/v1/auth/logout',               [AuthController::class, 'logout'],          auth: true,  csrf: true);
Router::get( '/api/v1/auth/me',                   [AuthController::class, 'me'],              auth: true,  csrf: false);
Router::post('/api/v1/auth/password/reset-request',[AuthController::class,'passwordResetRequest'], auth: false, csrf: false);
Router::post('/api/v1/auth/password/reset',       [AuthController::class, 'passwordReset'],   auth: false, csrf: false);
Router::post('/api/v1/auth/password/change',      [AuthController::class, 'passwordChange'],  auth: true,  csrf: true);
Router::post('/api/v1/auth/totp/verify',          [AuthController::class, 'totpVerify'],      auth: true,  csrf: false);
Router::post('/api/v1/auth/totp/setup',           [AuthController::class, 'totpSetup'],       auth: true,  csrf: true);
Router::post('/api/v1/auth/totp/confirm',         [AuthController::class, 'totpConfirm'],     auth: true,  csrf: true);
Router::delete('/api/v1/auth/totp',               [AuthController::class, 'totpDisable'],     auth: true,  csrf: true);

// ── Worlds ────────────────────────────────────────────────────────────────────

Router::get(   '/api/v1/worlds',                  [WorldController::class, 'index']);
Router::post(  '/api/v1/worlds',                  [WorldController::class, 'create']);
Router::get(   '/api/v1/worlds/:wid',             [WorldController::class, 'show']);
Router::patch( '/api/v1/worlds/:wid',             [WorldController::class, 'update']);
Router::delete('/api/v1/worlds/:wid',             [WorldController::class, 'destroy']);

// World members
Router::get(   '/api/v1/worlds/:wid/members',           [WorldController::class, 'members']);
Router::patch( '/api/v1/worlds/:wid/members/:uid',      [WorldController::class, 'updateMember']);
Router::delete('/api/v1/worlds/:wid/members/:uid',      [WorldController::class, 'removeMember']);

// World invitations
Router::post(  '/api/v1/worlds/:wid/invitations',       [WorldController::class, 'invite']);
Router::get(   '/api/v1/invitations/:token',            [WorldController::class, 'showInvitation'], auth: false, csrf: false);
Router::post(  '/api/v1/invitations/:token/accept',     [WorldController::class, 'acceptInvitation']);

// World AI settings
Router::get(   '/api/v1/worlds/:wid/settings/ai',       [WorldController::class, 'aiSettings']);
Router::put(   '/api/v1/worlds/:wid/settings/ai/key',   [WorldController::class, 'saveAiKey']);
Router::delete('/api/v1/worlds/:wid/settings/ai/key',   [WorldController::class, 'deleteAiKey']);

// ── Entities ──────────────────────────────────────────────────────────────────

Router::get(   '/api/v1/worlds/:wid/entities',          [EntityController::class, 'index']);
Router::post(  '/api/v1/worlds/:wid/entities',          [EntityController::class, 'create']);
Router::get(   '/api/v1/worlds/:wid/entities/:id',      [EntityController::class, 'show']);
Router::patch( '/api/v1/worlds/:wid/entities/:id',      [EntityController::class, 'update']);
Router::delete('/api/v1/worlds/:wid/entities/:id',      [EntityController::class, 'destroy']);

// Entity attributes
Router::get(   '/api/v1/worlds/:wid/entities/:id/attributes', [EntityController::class, 'attributes']);
Router::put(   '/api/v1/worlds/:wid/entities/:id/attributes', [EntityController::class, 'replaceAttributes']);

// Entity tags
Router::get(   '/api/v1/worlds/:wid/entities/:id/tags', [EntityController::class, 'entityTags']);
Router::put(   '/api/v1/worlds/:wid/entities/:id/tags', [EntityController::class, 'replaceEntityTags']);

// Tags (world-level)
Router::get(   '/api/v1/worlds/:wid/tags',              [EntityController::class, 'tagIndex']);
Router::post(  '/api/v1/worlds/:wid/tags',              [EntityController::class, 'tagCreate']);
Router::patch( '/api/v1/worlds/:wid/tags/:tid',         [EntityController::class, 'tagUpdate']);
Router::delete('/api/v1/worlds/:wid/tags/:tid',         [EntityController::class, 'tagDestroy']);

// Search
Router::get(   '/api/v1/worlds/:wid/search',            [EntityController::class, 'search']);

// ── Relationships ─────────────────────────────────────────────────────────────

Router::get(   '/api/v1/worlds/:wid/relationships',     [RelationshipController::class, 'index']);
Router::post(  '/api/v1/worlds/:wid/relationships',     [RelationshipController::class, 'create']);
Router::patch( '/api/v1/worlds/:wid/relationships/:id', [RelationshipController::class, 'update']);
Router::delete('/api/v1/worlds/:wid/relationships/:id', [RelationshipController::class, 'destroy']);

// ── Lore Notes ────────────────────────────────────────────────────────────────

Router::get(   '/api/v1/worlds/:wid/notes',                      [NoteController::class, 'worldNotes']);
Router::get(   '/api/v1/worlds/:wid/entities/:id/notes',         [NoteController::class, 'entityNotes']);
Router::post(  '/api/v1/worlds/:wid/entities/:id/notes',         [NoteController::class, 'create']);
Router::patch( '/api/v1/worlds/:wid/notes/:nid',                 [NoteController::class, 'update']);
Router::delete('/api/v1/worlds/:wid/notes/:nid',                 [NoteController::class, 'destroy']);
Router::post(  '/api/v1/worlds/:wid/notes/:nid/promote',         [NoteController::class, 'promote']);

// ── Timelines ─────────────────────────────────────────────────────────────────

// Note: reorder route must be registered before :eid to avoid the literal "reorder" matching :eid
Router::put(   '/api/v1/worlds/:wid/timelines/:tid/events/reorder', [TimelineController::class, 'reorderEvents']);

Router::get(   '/api/v1/worlds/:wid/timelines',                     [TimelineController::class, 'index']);
Router::post(  '/api/v1/worlds/:wid/timelines',                     [TimelineController::class, 'create']);
Router::get(   '/api/v1/worlds/:wid/timelines/:tid',                [TimelineController::class, 'show']);
Router::patch( '/api/v1/worlds/:wid/timelines/:tid',                [TimelineController::class, 'update']);
Router::delete('/api/v1/worlds/:wid/timelines/:tid',                [TimelineController::class, 'destroy']);
Router::get(   '/api/v1/worlds/:wid/timelines/:tid/events',         [TimelineController::class, 'events']);
Router::post(  '/api/v1/worlds/:wid/timelines/:tid/events',         [TimelineController::class, 'createEvent']);
Router::patch( '/api/v1/worlds/:wid/timelines/:tid/events/:eid',    [TimelineController::class, 'updateEvent']);
Router::delete('/api/v1/worlds/:wid/timelines/:tid/events/:eid',    [TimelineController::class, 'destroyEvent']);

// ── Story Arcs ────────────────────────────────────────────────────────────────

Router::get(   '/api/v1/worlds/:wid/story-arcs',                    [StoryArcController::class, 'index']);
Router::post(  '/api/v1/worlds/:wid/story-arcs',                    [StoryArcController::class, 'create']);
Router::get(   '/api/v1/worlds/:wid/story-arcs/:aid',               [StoryArcController::class, 'show']);
Router::patch( '/api/v1/worlds/:wid/story-arcs/:aid',               [StoryArcController::class, 'update']);
Router::delete('/api/v1/worlds/:wid/story-arcs/:aid',               [StoryArcController::class, 'destroy']);
Router::put(   '/api/v1/worlds/:wid/story-arcs/:aid/entities',      [StoryArcController::class, 'replaceEntities']);

// ── AI ────────────────────────────────────────────────────────────────────────

Router::post(  '/api/v1/worlds/:wid/ai/assist',          [AiController::class, 'assist']);
Router::post(  '/api/v1/worlds/:wid/ai/consistency-check',[AiController::class, 'consistencyCheck']);
Router::get(   '/api/v1/worlds/:wid/ai/sessions',        [AiController::class, 'sessions']);
Router::get(   '/api/v1/worlds/:wid/settings/ai/budget', [AiController::class, 'budget']);

// Prompt templates
Router::get(   '/api/v1/worlds/:wid/prompt-templates',       [AiController::class, 'templateIndex']);
Router::post(  '/api/v1/worlds/:wid/prompt-templates',       [AiController::class, 'templateCreate']);
Router::patch( '/api/v1/worlds/:wid/prompt-templates/:id',   [AiController::class, 'templateUpdate']);
Router::delete('/api/v1/worlds/:wid/prompt-templates/:id',   [AiController::class, 'templateDestroy']);

// Anthropic OAuth placeholder (Phase 2)
Router::get('/api/v1/auth/oauth/anthropic', function (array $p): void {
    http_response_code(501);
    echo json_encode(['error' => 'Anthropic OAuth is not yet implemented.', 'code' => 'NOT_IMPLEMENTED']);
}, auth: false, csrf: false);

// ── Export / Import ───────────────────────────────────────────────────────────

Router::get( '/api/v1/worlds/:wid/export',  [ExportController::class, 'export']);
Router::post('/api/v1/worlds/:wid/import',  [ExportController::class, 'import']);

// ── References ────────────────────────────────────────────────────────────────

Router::get(   '/api/v1/worlds/:wid/references',                    [ReferenceController::class, 'index']);
Router::post(  '/api/v1/worlds/:wid/references',                    [ReferenceController::class, 'create']);
Router::get(   '/api/v1/worlds/:wid/references/:rid',               [ReferenceController::class, 'show']);
Router::patch( '/api/v1/worlds/:wid/references/:rid',               [ReferenceController::class, 'update']);
Router::delete('/api/v1/worlds/:wid/references/:rid',               [ReferenceController::class, 'destroy']);
Router::put(   '/api/v1/worlds/:wid/references/:rid/entities',      [ReferenceController::class, 'linkEntities']);

// ── Open Points ───────────────────────────────────────────────────────────────

Router::get(   '/api/v1/worlds/:wid/open-points',                   [OpenPointsController::class, 'index']);
Router::post(  '/api/v1/worlds/:wid/open-points',                   [OpenPointsController::class, 'create']);
Router::get(   '/api/v1/worlds/:wid/open-points/:opid',             [OpenPointsController::class, 'show']);
Router::patch( '/api/v1/worlds/:wid/open-points/:opid',             [OpenPointsController::class, 'update']);
Router::delete('/api/v1/worlds/:wid/open-points/:opid',             [OpenPointsController::class, 'destroy']);

// ── Relationship Graph ────────────────────────────────────────────────────────

Router::get('/api/v1/worlds/:wid/graph', [EntityController::class, 'graph']);

// ── Audit Log + Stats ─────────────────────────────────────────────────────────

Router::get('/api/v1/worlds/:wid/audit-log', [WorldController::class, 'auditLog']);
Router::get('/api/v1/worlds/:wid/stats',     [WorldController::class, 'stats']);

// ─── 7. Dispatch ──────────────────────────────────────────────────────────────

Router::dispatch();
