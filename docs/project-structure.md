# LoreBuilder — Annotated Project Structure
# Version: 1.0 | SilverDay Media

```
lorebuilder/
│
├── public/                          ← Apache DocumentRoot (ONLY this directory)
│   ├── index.php                    ← Bootstrap: loads core/Router.php; serves SPA on non-API routes
│   ├── .htaccess                    ← mod_rewrite: API → PHP; everything else → index.php (Vue Router)
│   └── assets/                      ← Vite build output (JS, CSS, fonts); cache-busted filenames
│
├── core/                            ← PHP application core (NOT web-accessible)
│   ├── Router.php                   ← Method+path dispatcher; auto-verifies CSRF on POST/PATCH/PUT/DELETE
│   ├── DB.php                       ← PDO wrapper: query(), queryOne(), execute(); query log in dev mode
│   ├── Auth.php                     ← session_start, login/logout, CSRF token, TOTP verify
│   ├── Guard.php                    ← requireWorldAccess(), requireAdmin(), ownOrRole()
│   ├── Crypto.php                   ← libsodium: encryptApiKey(), decryptApiKey(), keyFingerprint()
│   ├── RateLimit.php                ← Token-bucket rate limiter (DB-backed rate_limit_buckets)
│   ├── Claude.php                   ← AI client: resolveApiKey(), buildContext(), callApi(), renderTemplate()
│   ├── Validator.php                ← Input validation + sanitisation; throws ValidationException
│   ├── Mailer.php                   ← Invitation + password reset emails (PHP mail() or SMTP)
│   ├── AuditLogger.php              ← Append-only writes to audit_log; called from controllers
│   ├── Paginator.php                ← Cursor + offset pagination helpers
│   └── Exceptions/
│       ├── AuthException.php        ← 401
│       ├── ForbiddenException.php   ← 403
│       ├── NotFoundException.php    ← 404
│       ├── ValidationException.php  ← 422
│       ├── RateLimitException.php   ← 429
│       ├── AiKeyMissingException.php
│       └── AiKeyInvalidException.php
│
├── api/                             ← REST controllers (instantiated by Router)
│   ├── AuthController.php
│   ├── UserController.php
│   ├── WorldController.php          ← CRUD + member management + settings
│   ├── EntityController.php         ← CRUD + tag management + slug generation
│   ├── RelationshipController.php
│   ├── TagController.php
│   ├── TimelineController.php
│   ├── TimelineEventController.php
│   ├── StoryArcController.php
│   ├── NoteController.php
│   ├── PromptTemplateController.php
│   ├── AiController.php             ← assist(), consistencyCheck(), sessions()
│   ├── SearchController.php
│   ├── GraphController.php          ← graph data endpoint (nodes + edges JSON)
│   ├── ExportController.php
│   └── AdminController.php          ← platform admin only
│
├── frontend/                        ← Vue 3 SPA source (Vite project)
│   ├── index.html
│   ├── vite.config.js               ← proxy: /api → localhost; output to ../public/assets
│   ├── package.json
│   ├── package-lock.json            ← committed
│   └── src/
│       ├── main.js                  ← createApp, use(router), use(pinia)
│       ├── App.vue                  ← Root: router-view, toast provider
│       │
│       ├── router/
│       │   └── index.js             ← All routes; auth guard (redirect to /login if no session)
│       │
│       ├── stores/                  ← Pinia stores
│       │   ├── auth.js              ← currentUser, csrf token, login/logout actions
│       │   ├── world.js             ← activeWorld, members, AI settings (fingerprint only)
│       │   ├── entity.js            ← entity cache; invalidation on mutation
│       │   ├── ui.js                ← sidebar state, toast queue, loading flags
│       │   └── ai.js                ← AI session history, budget display, rate limit state
│       │
│       ├── api/                     ← Typed fetch wrappers (no raw fetch elsewhere)
│       │   ├── client.js            ← base fetch: attaches X-CSRF-Token, handles 401/429/5xx
│       │   ├── auth.js
│       │   ├── worlds.js
│       │   ├── entities.js
│       │   ├── relationships.js
│       │   ├── timelines.js
│       │   ├── arcs.js
│       │   ├── notes.js
│       │   ├── ai.js                ← assist(), fetchSessions(), consistencyCheck()
│       │   ├── search.js
│       │   └── export.js
│       │
│       ├── composables/             ← Shared Composition API logic
│       │   ├── useWorldGuard.js     ← checks role before rendering sensitive UI
│       │   ├── usePagination.js
│       │   ├── useMarkdown.js       ← Marked.js + DOMPurify rendering
│       │   ├── useToast.js
│       │   └── useAiPanel.js        ← AI panel state machine (idle/loading/success/error)
│       │
│       ├── views/                   ← Route-level components (lazy-loaded)
│       │   ├── LoginView.vue
│       │   ├── RegisterView.vue
│       │   ├── DashboardView.vue
│       │   ├── WorldSelectView.vue
│       │   ├── EntitiesView.vue
│       │   ├── EntityDetailView.vue ← Three-panel layout: meta | notes+AI | relationships
│       │   ├── EntityNewView.vue
│       │   ├── GraphView.vue        ← vis-network full graph
│       │   ├── TimelinesView.vue
│       │   ├── TimelineDetailView.vue
│       │   ├── StoryArcsView.vue    ← Kanban board
│       │   ├── StoryArcDetailView.vue
│       │   ├── SearchView.vue
│       │   ├── AiHistoryView.vue
│       │   ├── ConsistencyView.vue  ← checker trigger + findings list
│       │   ├── WorldSettingsView.vue
│       │   └── AdminView.vue
│       │
│       └── components/              ← Reusable UI components
│           ├── layout/
│           │   ├── AppNav.vue       ← World-aware sidebar navigation
│           │   ├── WorldBreadcrumb.vue
│           │   └── PageHeader.vue
│           ├── entity/
│           │   ├── EntityCard.vue
│           │   ├── EntityTypeBadge.vue
│           │   ├── EntityStatusPill.vue
│           │   ├── AttributeTable.vue
│           │   └── RelationshipList.vue
│           ├── ai/
│           │   ├── AiPanel.vue      ← Mode selector, prompt input, state machine
│           │   ├── AiResponseCard.vue  ← Rendered Markdown + Accept/Edit/Discard
│           │   ├── AiPromptEditor.vue  ← Free-prompt mode textarea
│           │   ├── AiBudgetBar.vue  ← Token usage progress bar
│           │   └── AiKeyForm.vue    ← Password input for API key; clears after submit
│           ├── graph/
│           │   └── EntityGraph.vue  ← vis-network wrapper
│           ├── timeline/
│           │   └── WorldTimeline.vue   ← vis-timeline wrapper; drag-to-reorder
│           ├── arc/
│           │   ├── ArcKanban.vue
│           │   └── ArcCard.vue
│           ├── notes/
│           │   ├── NoteList.vue
│           │   ├── NoteCard.vue     ← shows canonical badge and AI badge
│           │   └── NoteEditor.vue   ← Markdown editor modal
│           └── common/
│               ├── MarkdownRenderer.vue   ← Marked + DOMPurify; no v-html without sanitise
│               ├── ConfirmDialog.vue
│               ├── ToastStack.vue
│               ├── SearchInput.vue
│               ├── PaginationBar.vue
│               └── LoadingSpinner.vue
│
├── migrations/
│   ├── 001_initial.sql              ← Full schema (see schema.sql)
│   └── 002_*.sql                    ← Future migrations; never modify existing files
│
├── scripts/                         ← CLI tools (run via: php scripts/migrate.php)
│   ├── migrate.php                  ← Apply pending migrations
│   ├── create-admin.php             ← Bootstrap first platform admin user
│   ├── create-world.php             ← CLI world creation for operator use
│   ├── invalidate-sessions.php      ← Emergency: force re-login for a user
│   ├── invalidate-ai-keys.php       ← Emergency: zero out stored AI keys
│   ├── export.php                   ← CLI export: php scripts/export.php --world=N --format=json
│   ├── consistency-check.php        ← CLI consistency check (outputs to stdout)
│   └── pseudonymise-logs.php        ← GDPR: zero last IP octet for entries > 30 days
│
├── config/
│   ├── config.example.php           ← Template with all required constants; committed
│   └── config.php                   ← Actual secrets; in .gitignore; chmod 640
│       Constants:
│         APP_URL, APP_SECRET (32-byte sodium key), DB_HOST, DB_NAME, DB_USER, DB_PASS,
│         PLATFORM_ANTHROPIC_KEY (optional), MAIL_FROM, LOG_PATH, STORAGE_PATH,
│         SESSION_LIFETIME, RATE_LIMIT_AI_PER_USER, DEBUG (false in production)
│
├── storage/                         ← Runtime data (NOT web-accessible)
│   ├── uploads/                     ← User file attachments; validated MIME, no exec
│   ├── logs/
│   │   ├── app.log                  ← Application errors (no secrets)
│   │   └── audit.log                ← Mirror of audit_log table for log shipping
│   └── backups/                     ← mysqldump outputs (encrypted at rest recommended)
│
├── docs/
│   ├── ai-engine.md                 ← This package
│   ├── api-contract.md              ← This package
│   ├── project-structure.md         ← This file
│   └── design-document.docx         ← Master design document
│
├── hooks/
│   ├── pre-commit                   ← Blocks commit if config.php is staged
│   └── pre-push                     ← Runs npm audit; blocks on high severity
│
├── .claude/
│   └── commands/
│       ├── security-review.md       ← /project:security-review slash command
│       ├── new-endpoint.md          ← /project:new-endpoint scaffold
│       ├── new-migration.md         ← /project:new-migration scaffold
│       └── ai-context-test.md       ← /project:ai-context-test
│
├── CLAUDE.md                        ← Project constitution for Claude Code
├── SECURITY.md                      ← Threat model and controls
├── DECISIONS.md                     ← Architecture decision log (append-only)
├── SECURITY_FINDINGS.md             ← Security issue log (append-only)
├── .gitignore                       ← config.php, storage/, node_modules/, dist/
└── README.md                        ← Setup, install, deployment
```

## Apache VirtualHost Notes

```apache
<VirtualHost *:443>
    ServerName lorebuilder.yourdomain.com
    DocumentRoot /var/www/lorebuilder/public

    <Directory /var/www/lorebuilder/public>
        AllowOverride All
        Options -Indexes
        Require all granted
    </Directory>

    # Deny access to everything above public/
    <Directory /var/www/lorebuilder>
        Require all denied
    </Directory>
    <Directory /var/www/lorebuilder/public>
        Require all granted
    </Directory>

    # Security headers (see SECURITY.md §2.7)
    Header always set X-Frame-Options "DENY"
    Header always set X-Content-Type-Options "nosniff"
    # ... full set in SECURITY.md
</VirtualHost>
```

## public/.htaccess

```apache
RewriteEngine On

# Block access to dot files
RewriteRule (^|/)\.(?!well-known) - [F]

# Route /api/* to index.php
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^api/ index.php [L,QSA]

# Route everything else to index.php (Vue Router)
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [L]
```
