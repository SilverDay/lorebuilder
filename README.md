# LoreBuilder

A self-hosted, multi-tenant world-building platform for authors. Manages fictional worlds as a relational graph — characters, factions, locations, events, artefacts, timelines, and story arcs — with Claude as an integrated AI narrative assistant.

**Stack:** PHP 8.3 · MariaDB 10.11 · Apache 2.4 · Vue 3 + Vite

---

## Features

- **Relational lore graph** — entities connected by typed, directed relationships; visualised with vis-network
- **Interactive timelines** — in-world era systems, numeric, or real-world date modes; drag-to-reorder
- **Story arc Kanban** — Seed → Rising Action → Climax → Resolution; entity participation tracking
- **AI narrative assistant** — every entity can be sent to Claude for backstory, plot hooks, consistency checks, lore expansion, and more; server-side proxy keeps API keys off the browser
- **Multi-user worlds** — role-based access (owner / admin / author / reviewer / viewer) with email invitations
- **Multi-tenant** — multiple independent worlds per instance, each fully isolated
- **Self-hosted** — standard LAMP stack, no Docker required

---

## Requirements

- PHP 8.3+ with extensions: `pdo_mysql`, `sodium`, `json`, `session`
- MariaDB 10.11+ (or MySQL 8+)
- Apache 2.4 with `mod_rewrite` and `mod_headers`
- Node.js 20+ and npm (for frontend build)

---

## Installation

### 1. Clone and configure

```bash
git clone https://github.com/SilverDay/lorebuilder.git
cd lorebuilder
cp config/config.example.php config/config.php
chmod 640 config/config.php
```

Edit `config/config.php` and fill in all values. Generate the encryption key:

```bash
php -r "echo base64_encode(sodium_crypto_secretbox_keygen());"
# Paste output as APP_SECRET
```

### 2. Create the database

```sql
CREATE DATABASE `lorebuilder` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'lorebuilder_user'@'localhost' IDENTIFIED BY 'your_password';
GRANT ALL ON `lorebuilder`.* TO 'lorebuilder_user'@'localhost';
```

### 3. Run migrations

```bash
php scripts/migrate.php
# Check status:
php scripts/migrate.php --status
```

### 4. Create the first user

```bash
php scripts/create-user.php
```

### 5. Build the frontend

```bash
cd frontend
npm install
npm run build
```

### 6. Configure Apache

Point your VirtualHost `DocumentRoot` to the `public/` directory:

```apache
<VirtualHost *:443>
    ServerName lorebuilder.yourdomain.com
    DocumentRoot /path/to/lorebuilder/public

    <Directory /path/to/lorebuilder/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Ensure `mod_rewrite` and `mod_headers` are enabled:

```bash
a2enmod rewrite headers
systemctl reload apache2
```

### 7. Install the pre-commit hook

```bash
cp hooks/pre-commit .git/hooks/pre-commit
chmod +x .git/hooks/pre-commit
```

---

## Storage Directories

The following directories must exist outside the web root and be writable by the web server:

```bash
mkdir -p storage/uploads storage/logs storage/backups
chown -R www-data:www-data storage/
```

Update `STORAGE_PATH` in `config/config.php` to the absolute path.

---

## Configuration Reference

| Constant | Description |
|---|---|
| `APP_URL` | Public URL, no trailing slash |
| `APP_DEBUG` | `false` in production — never expose errors to browser |
| `APP_SECRET` | Base64-encoded 32-byte libsodium key for API key encryption |
| `DB_*` | Database connection settings |
| `PLATFORM_ANTHROPIC_KEY` | Optional operator-provided Anthropic key (Mode B); leave empty to disable |
| `SESSION_LIFETIME` | Idle timeout in seconds (default 8h) |
| `REGISTRATION_OPEN` | `true` = open registration; `false` = invite-only |
| `REQUIRE_EMAIL_VERIFICATION` | Require email confirmation before login |
| `RATE_AI_USER_LIMIT` | AI requests per user per hour (default 20) |
| `LOG_PATH` / `AUDIT_LOG_PATH` | Absolute paths to log files |

---

## Development

```bash
# Run frontend dev server (proxies API calls to your PHP backend)
cd frontend && npm run dev

# Apply a new migration
php scripts/migrate.php

# Check migration status
php scripts/migrate.php --status

# Run consistency check (requires configured world and AI key)
php scripts/consistency-check.php --world=<slug>

# Export a world
php scripts/export.php --world=<slug> --format=json
```

---

## Project Structure

```
public/          Apache DocumentRoot — index.php + compiled assets
core/            Framework: DB, Auth, Guard, Crypto, RateLimit, Validator, Router, Claude
api/             REST controllers (one file per resource group)
frontend/        Vue 3 SPA source (src/) + Vite config
migrations/      Numbered SQL files — never modify existing ones
scripts/         CLI tools — not web-accessible
config/          config.example.php (committed) · config.php (gitignored)
storage/         Runtime files — outside web root
docs/            Architecture and API documentation
tasks/           Implementation plan and decision log
hooks/           Git hooks
```

---

## API

Base path: `/api/v1/`  
Auth: session cookie (`HttpOnly`, `SameSite=Strict`) + `X-CSRF-Token` header on state-changing requests.

See [`docs/api-contract.md`](docs/api-contract.md) for the full endpoint reference.

---

## AI Key Modes

| Mode | How it works |
|---|---|
| **A — User key** | User provides their Anthropic API key in world settings. Encrypted with libsodium before storage; only a fingerprint is ever returned to the browser. |
| **B — Platform key** | Operator sets `PLATFORM_ANTHROPIC_KEY` in `config/config.php`. Per-world token budgets enforced via the database. |
| **C — OAuth** | Schema placeholder reserved for Phase 2 (Anthropic OAuth). Returns `501 Not Implemented`. |

---

## Security

- All API keys encrypted with `sodium_crypto_secretbox` (XSalsa20-Poly1305) using `APP_SECRET`
- Keys decrypted in memory at call time only; zeroed with `sodium_memzero` after use
- Keys never returned in any API response — only fingerprints
- CSRF double-submit token on all state-changing requests
- Role-based world access enforced by `Guard::requireWorldAccess()` on every endpoint
- Token-bucket rate limiting on AI endpoints and login
- Append-only audit log for all mutations
- Soft deletes throughout — hard delete via CLI only

See [`SECURITY.md`](SECURITY.md) for the full threat model.

---

## Roadmap

| Phase | Scope | Status |
|---|---|---|
| 1 — Foundation | Schema, core layer, auth API, entity/world CRUD | In progress |
| 2 — Narrative Structure | Timelines, story arcs, lore notes, search, Vue SPA | Planned |
| 3 — Visualisation | Relationship graph, dashboard, audit log viewer | Planned |
| 4 — Claude Integration | AI assistant, all invocation modes, budget dashboard | Planned |
| 5 — Power Features | Export/import, invitations UI, WCSOZ sync, OAuth | Planned |

See [`tasks/plan.md`](tasks/plan.md) for the detailed task breakdown.

---

*LoreBuilder — SilverDay Media*
