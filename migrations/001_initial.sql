-- LoreBuilder — Master Database Schema
-- Migration: 001_initial.sql
-- Engine: MariaDB 10.11 | Charset: utf8mb4 | Collation: utf8mb4_unicode_ci

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─── Migration Tracking ────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS schema_migrations (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    filename    VARCHAR(255)    NOT NULL UNIQUE,
    applied_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ─── Platform Users ────────────────────────────────────────────────────────────

CREATE TABLE users (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username                VARCHAR(64)     NOT NULL UNIQUE,
    email                   VARCHAR(254)    NOT NULL UNIQUE,
    display_name            VARCHAR(128)    NOT NULL,
    password_hash           VARCHAR(255)    NOT NULL,                  -- bcrypt cost 12
    totp_secret_enc         TEXT            NULL DEFAULT NULL,         -- libsodium encrypted
    totp_enabled            TINYINT(1)      NOT NULL DEFAULT 0,
    -- OAuth placeholder (Phase 2)
    oauth_anthropic_token   TEXT            NULL DEFAULT NULL,         -- encrypted; NULL until OAuth implemented
    oauth_provider          VARCHAR(32)     NULL DEFAULT NULL,         -- 'anthropic' | NULL
    -- Account state
    is_platform_admin       TINYINT(1)      NOT NULL DEFAULT 0,
    is_active               TINYINT(1)      NOT NULL DEFAULT 1,
    email_verified          TINYINT(1)      NOT NULL DEFAULT 0,
    email_verify_token      VARCHAR(64)     NULL DEFAULT NULL,
    password_reset_token    VARCHAR(64)     NULL DEFAULT NULL,
    password_reset_expires  DATETIME        NULL DEFAULT NULL,
    failed_login_count      SMALLINT        NOT NULL DEFAULT 0,
    locked_until            DATETIME        NULL DEFAULT NULL,
    last_login_at           DATETIME        NULL DEFAULT NULL,
    last_login_ip           VARCHAR(45)     NULL DEFAULT NULL,         -- IPv4/IPv6; pseudonymised after 30d
    created_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at              DATETIME        NULL DEFAULT NULL,
    INDEX idx_users_email   (email),
    INDEX idx_users_active  (is_active, deleted_at)
) ENGINE=InnoDB;

-- ─── Worlds (Tenants) ──────────────────────────────────────────────────────────

CREATE TABLE worlds (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    owner_id            BIGINT UNSIGNED NOT NULL,
    slug                VARCHAR(128)    NOT NULL UNIQUE,               -- URL-safe identifier
    name                VARCHAR(255)    NOT NULL,
    description         TEXT            NULL,
    genre               VARCHAR(128)    NULL,                          -- 'dark fantasy', 'sci-fi', etc.
    tone                VARCHAR(128)    NULL,                          -- 'gritty', 'heroic', 'cosmic horror'
    era_system          VARCHAR(255)    NULL,                          -- e.g. 'Age of Zot / The Sundering / Now'
    content_warnings    TEXT            NULL,                          -- displayed to all members
    -- AI configuration
    ai_key_mode         ENUM('user','platform','oauth') NOT NULL DEFAULT 'user',
    ai_key_enc          TEXT            NULL DEFAULT NULL,             -- libsodium encrypted user key
    ai_key_fingerprint  VARCHAR(32)     NULL DEFAULT NULL,             -- display only, e.g. sk-ant-api…X4aB
    ai_model            VARCHAR(64)     NOT NULL DEFAULT 'claude-sonnet-4-20250514',
    ai_token_budget     INT UNSIGNED    NOT NULL DEFAULT 1000000,      -- monthly limit (tokens)
    ai_tokens_used      INT UNSIGNED    NOT NULL DEFAULT 0,
    ai_budget_resets_at DATE            NULL DEFAULT NULL,
    -- State
    is_public           TINYINT(1)      NOT NULL DEFAULT 0,            -- future: public read-only view
    status              ENUM('active','archived','suspended') NOT NULL DEFAULT 'active',
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at          DATETIME        NULL DEFAULT NULL,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_worlds_owner  (owner_id),
    INDEX idx_worlds_status (status, deleted_at)
) ENGINE=InnoDB;

-- ─── World Membership ──────────────────────────────────────────────────────────

CREATE TABLE world_members (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    world_id    BIGINT UNSIGNED NOT NULL,
    user_id     BIGINT UNSIGNED NOT NULL,
    role        ENUM('owner','admin','author','reviewer','viewer') NOT NULL DEFAULT 'author',
    invited_by  BIGINT UNSIGNED NULL DEFAULT NULL,
    joined_at   DATETIME        NULL DEFAULT NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at  DATETIME        NULL DEFAULT NULL,
    UNIQUE KEY uq_world_member (world_id, user_id),
    FOREIGN KEY (world_id)    REFERENCES worlds(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)     REFERENCES users(id)  ON DELETE CASCADE,
    FOREIGN KEY (invited_by)  REFERENCES users(id)  ON DELETE SET NULL,
    INDEX idx_wm_user    (user_id),
    INDEX idx_wm_world   (world_id, deleted_at)
) ENGINE=InnoDB;

-- ─── Tags ──────────────────────────────────────────────────────────────────────

CREATE TABLE tags (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    world_id    BIGINT UNSIGNED NOT NULL,
    name        VARCHAR(64)     NOT NULL,
    color       VARCHAR(7)      NOT NULL DEFAULT '#4A90A4',            -- hex colour
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tag_world (world_id, name),
    FOREIGN KEY (world_id) REFERENCES worlds(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── Entities ──────────────────────────────────────────────────────────────────

CREATE TABLE entities (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    world_id        BIGINT UNSIGNED NOT NULL,
    created_by      BIGINT UNSIGNED NOT NULL,
    type            ENUM('Character','Location','Event','Faction','Artefact','Creature','Concept','StoryArc','Timeline') NOT NULL,
    name            VARCHAR(255)    NOT NULL,
    slug            VARCHAR(300)    NOT NULL,                          -- world_id-scoped unique slug
    short_summary   VARCHAR(512)    NULL,                              -- one-liner; shown in lists
    status          ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    -- Full-text searchable lore body (Markdown)
    lore_body       LONGTEXT        NULL,
    -- Flexible attribute store for type-specific fields
    -- (also stored normalised in entity_attributes for querying)
    attributes_json JSON            NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at      DATETIME        NULL DEFAULT NULL,
    UNIQUE KEY uq_entity_slug (world_id, slug),
    FOREIGN KEY (world_id)   REFERENCES worlds(id)  ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)   ON DELETE RESTRICT,
    INDEX idx_entity_world  (world_id, type, status, deleted_at),
    INDEX idx_entity_type   (type),
    FULLTEXT INDEX ft_entity (name, short_summary, lore_body)
) ENGINE=InnoDB;

-- ─── Entity Attributes (typed key-value) ───────────────────────────────────────

CREATE TABLE entity_attributes (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    entity_id   BIGINT UNSIGNED NOT NULL,
    world_id    BIGINT UNSIGNED NOT NULL,                              -- denormalised for query scoping
    attr_key    VARCHAR(128)    NOT NULL,
    attr_value  TEXT            NULL,
    data_type   ENUM('string','integer','boolean','date','markdown') NOT NULL DEFAULT 'string',
    sort_order  SMALLINT        NOT NULL DEFAULT 0,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_attr (entity_id, attr_key),
    FOREIGN KEY (entity_id) REFERENCES entities(id) ON DELETE CASCADE,
    FOREIGN KEY (world_id)  REFERENCES worlds(id)   ON DELETE CASCADE,
    INDEX idx_attr_world_key (world_id, attr_key)
) ENGINE=InnoDB;

-- ─── Entity Tags ───────────────────────────────────────────────────────────────

CREATE TABLE entity_tags (
    entity_id   BIGINT UNSIGNED NOT NULL,
    tag_id      BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (entity_id, tag_id),
    FOREIGN KEY (entity_id) REFERENCES entities(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id)    REFERENCES tags(id)     ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── Entity Relationships (directed graph edges) ───────────────────────────────

CREATE TABLE entity_relationships (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    world_id        BIGINT UNSIGNED NOT NULL,
    from_entity_id  BIGINT UNSIGNED NOT NULL,
    to_entity_id    BIGINT UNSIGNED NOT NULL,
    rel_type        VARCHAR(64)     NOT NULL,                          -- 'knows', 'rules', 'caused', etc.
    is_bidirectional TINYINT(1)     NOT NULL DEFAULT 0,                -- if 1, edge renders both ways in graph
    strength        TINYINT UNSIGNED NOT NULL DEFAULT 5,               -- 1-10, used for graph edge weight
    notes           TEXT            NULL,
    created_by      BIGINT UNSIGNED NOT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at      DATETIME        NULL DEFAULT NULL,
    FOREIGN KEY (world_id)        REFERENCES worlds(id)   ON DELETE CASCADE,
    FOREIGN KEY (from_entity_id)  REFERENCES entities(id) ON DELETE CASCADE,
    FOREIGN KEY (to_entity_id)    REFERENCES entities(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by)      REFERENCES users(id)    ON DELETE RESTRICT,
    INDEX idx_rel_from  (from_entity_id, deleted_at),
    INDEX idx_rel_to    (to_entity_id,   deleted_at),
    INDEX idx_rel_world (world_id, rel_type, deleted_at)
) ENGINE=InnoDB;

-- ─── Timelines ─────────────────────────────────────────────────────────────────

CREATE TABLE timelines (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    world_id        BIGINT UNSIGNED NOT NULL,
    created_by      BIGINT UNSIGNED NOT NULL,
    name            VARCHAR(255)    NOT NULL,
    description     TEXT            NULL,
    -- Mode: era uses in-world string labels; numeric uses ordinal integers; date uses real ISO dates
    scale_mode      ENUM('era','numeric','date') NOT NULL DEFAULT 'era',
    era_labels      JSON            NULL,                              -- ordered array of era name strings
    color           VARCHAR(7)      NOT NULL DEFAULT '#4A90A4',
    sort_order      SMALLINT        NOT NULL DEFAULT 0,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at      DATETIME        NULL DEFAULT NULL,
    FOREIGN KEY (world_id)   REFERENCES worlds(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)  ON DELETE RESTRICT,
    INDEX idx_tl_world (world_id, deleted_at)
) ENGINE=InnoDB;

-- ─── Timeline Events ───────────────────────────────────────────────────────────

CREATE TABLE timeline_events (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    timeline_id     BIGINT UNSIGNED NOT NULL,
    world_id        BIGINT UNSIGNED NOT NULL,
    entity_id       BIGINT UNSIGNED NULL DEFAULT NULL,                 -- links to an entity (optional)
    label           VARCHAR(255)    NOT NULL,
    description     TEXT            NULL,
    -- Position: interpretation depends on timeline.scale_mode
    position_order  INT             NOT NULL DEFAULT 0,                -- ordinal for drag-sorting
    position_era    VARCHAR(128)    NULL,                              -- era name (scale_mode=era)
    position_value  DECIMAL(18,4)   NULL,                              -- numeric or UNIX timestamp
    position_label  VARCHAR(64)     NULL,                              -- display label e.g. "Year 412, Age of Zot"
    color           VARCHAR(7)      NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at      DATETIME        NULL DEFAULT NULL,
    FOREIGN KEY (timeline_id) REFERENCES timelines(id) ON DELETE CASCADE,
    FOREIGN KEY (world_id)    REFERENCES worlds(id)    ON DELETE CASCADE,
    FOREIGN KEY (entity_id)   REFERENCES entities(id)  ON DELETE SET NULL,
    INDEX idx_tl_event_order (timeline_id, position_order, deleted_at)
) ENGINE=InnoDB;

-- ─── Story Arcs ────────────────────────────────────────────────────────────────

CREATE TABLE story_arcs (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    world_id        BIGINT UNSIGNED NOT NULL,
    created_by      BIGINT UNSIGNED NOT NULL,
    name            VARCHAR(255)    NOT NULL,
    logline         VARCHAR(512)    NULL,                              -- one-sentence premise
    theme           VARCHAR(255)    NULL,
    status          ENUM('seed','rising_action','climax','resolution','complete','abandoned') NOT NULL DEFAULT 'seed',
    sort_order      SMALLINT        NOT NULL DEFAULT 0,
    ai_synopsis     TEXT            NULL,                              -- Claude-generated, refreshed on demand
    ai_synopsis_at  DATETIME        NULL DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at      DATETIME        NULL DEFAULT NULL,
    FOREIGN KEY (world_id)   REFERENCES worlds(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)  ON DELETE RESTRICT,
    INDEX idx_arc_world (world_id, status, deleted_at)
) ENGINE=InnoDB;

-- ─── Arc Members ───────────────────────────────────────────────────────────────

CREATE TABLE arc_entities (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    arc_id      BIGINT UNSIGNED NOT NULL,
    entity_id   BIGINT UNSIGNED NOT NULL,
    world_id    BIGINT UNSIGNED NOT NULL,
    role        VARCHAR(128)    NULL,                                  -- 'protagonist', 'antagonist', 'setting', etc.
    notes       TEXT            NULL,
    sort_order  SMALLINT        NOT NULL DEFAULT 0,
    UNIQUE KEY uq_arc_entity (arc_id, entity_id),
    FOREIGN KEY (arc_id)    REFERENCES story_arcs(id) ON DELETE CASCADE,
    FOREIGN KEY (entity_id) REFERENCES entities(id)   ON DELETE CASCADE,
    FOREIGN KEY (world_id)  REFERENCES worlds(id)     ON DELETE CASCADE,
    INDEX idx_ae_entity (entity_id)
) ENGINE=InnoDB;

-- ─── Lore Notes ────────────────────────────────────────────────────────────────

CREATE TABLE lore_notes (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    world_id        BIGINT UNSIGNED NOT NULL,
    entity_id       BIGINT UNSIGNED NULL DEFAULT NULL,                 -- NULL = world-level note
    created_by      BIGINT UNSIGNED NOT NULL,
    content         LONGTEXT        NOT NULL,                          -- Markdown
    is_canonical    TINYINT(1)      NOT NULL DEFAULT 0,                -- promoted to canonical lore
    ai_generated    TINYINT(1)      NOT NULL DEFAULT 0,
    ai_session_id   BIGINT UNSIGNED NULL DEFAULT NULL,                 -- links to ai_sessions if AI-generated
    -- Canonical promotion
    promoted_by     BIGINT UNSIGNED NULL DEFAULT NULL,
    promoted_at     DATETIME        NULL DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at      DATETIME        NULL DEFAULT NULL,
    FOREIGN KEY (world_id)    REFERENCES worlds(id)   ON DELETE CASCADE,
    FOREIGN KEY (entity_id)   REFERENCES entities(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by)  REFERENCES users(id)    ON DELETE RESTRICT,
    FOREIGN KEY (promoted_by) REFERENCES users(id)    ON DELETE SET NULL,
    INDEX idx_note_entity (entity_id, is_canonical, deleted_at),
    INDEX idx_note_world  (world_id,  ai_generated,  deleted_at),
    FULLTEXT INDEX ft_note (content)
) ENGINE=InnoDB;

-- ─── AI Sessions (audit log for all Claude calls) ──────────────────────────────

CREATE TABLE ai_sessions (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    world_id            BIGINT UNSIGNED NOT NULL,
    user_id             BIGINT UNSIGNED NOT NULL,
    entity_id           BIGINT UNSIGNED NULL DEFAULT NULL,
    mode                VARCHAR(64)     NOT NULL,                      -- 'entity_assist', 'consistency_check', etc.
    model               VARCHAR(64)     NOT NULL,
    prompt_tokens       INT UNSIGNED    NOT NULL DEFAULT 0,
    completion_tokens   INT UNSIGNED    NOT NULL DEFAULT 0,
    total_tokens        INT UNSIGNED    NOT NULL DEFAULT 0,
    -- Store prompt hash (not full prompt) for dedup detection; full prompt in lore_notes
    prompt_hash         CHAR(64)        NULL,                          -- SHA-256 of assembled prompt
    status              ENUM('success','error','rate_limited') NOT NULL DEFAULT 'success',
    error_message       VARCHAR(512)    NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- NOTE: ai_key, full prompt text, and full response text are NEVER stored here.
    -- Response is stored in lore_notes (ai_generated=1, ai_session_id=this.id).
    FOREIGN KEY (world_id)  REFERENCES worlds(id)   ON DELETE CASCADE,
    FOREIGN KEY (user_id)   REFERENCES users(id)    ON DELETE RESTRICT,
    FOREIGN KEY (entity_id) REFERENCES entities(id) ON DELETE SET NULL,
    INDEX idx_ai_world  (world_id, created_at),
    INDEX idx_ai_user   (user_id,  created_at)
) ENGINE=InnoDB;

-- ─── Rate Limit Buckets ────────────────────────────────────────────────────────

CREATE TABLE rate_limit_buckets (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    bucket_key  VARCHAR(128)    NOT NULL UNIQUE,                       -- e.g. 'ai:user:42', 'login:ip:1.2.3.x'
    tokens      DECIMAL(10,4)   NOT NULL DEFAULT 0,
    last_refill DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rlb_key (bucket_key)
) ENGINE=InnoDB;

-- ─── Audit Log (append-only) ───────────────────────────────────────────────────

CREATE TABLE audit_log (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    world_id        BIGINT UNSIGNED NULL DEFAULT NULL,                 -- NULL for platform-level events
    user_id         BIGINT UNSIGNED NULL DEFAULT NULL,
    action          VARCHAR(64)     NOT NULL,                          -- 'entity.create', 'entity.delete', 'login', etc.
    target_type     VARCHAR(64)     NULL,
    target_id       BIGINT UNSIGNED NULL,
    ip_address      VARCHAR(45)     NULL,
    user_agent      VARCHAR(512)    NULL,
    diff_json       JSON            NULL,                              -- before/after for mutations
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- No FK on user_id/world_id intentionally: log must survive deletions
    INDEX idx_audit_world  (world_id, created_at),
    INDEX idx_audit_user   (user_id,  created_at),
    INDEX idx_audit_action (action,   created_at)
) ENGINE=InnoDB;

-- ─── Prompt Templates ──────────────────────────────────────────────────────────

CREATE TABLE prompt_templates (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    world_id    BIGINT UNSIGNED NULL DEFAULT NULL,                     -- NULL = platform default
    mode        VARCHAR(64)     NOT NULL,
    name        VARCHAR(128)    NOT NULL,
    system_tpl  TEXT            NOT NULL,                              -- system prompt with {{variables}}
    user_tpl    TEXT            NOT NULL,                              -- user turn with {{variables}}
    is_default  TINYINT(1)      NOT NULL DEFAULT 0,
    created_by  BIGINT UNSIGNED NULL DEFAULT NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (world_id)  REFERENCES worlds(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_pt_world_mode (world_id, mode, is_default)
) ENGINE=InnoDB;

-- ─── World Invitations ─────────────────────────────────────────────────────────

CREATE TABLE world_invitations (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    world_id    BIGINT UNSIGNED NOT NULL,
    invited_by  BIGINT UNSIGNED NOT NULL,
    email       VARCHAR(254)    NOT NULL,
    role        ENUM('admin','author','reviewer','viewer') NOT NULL DEFAULT 'author',
    token       VARCHAR(64)     NOT NULL UNIQUE,
    expires_at  DATETIME        NOT NULL,
    accepted_at DATETIME        NULL DEFAULT NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (world_id)   REFERENCES worlds(id) ON DELETE CASCADE,
    FOREIGN KEY (invited_by) REFERENCES users(id)  ON DELETE CASCADE,
    INDEX idx_inv_token (token),
    INDEX idx_inv_world (world_id, expires_at)
) ENGINE=InnoDB;

-- ─── OAuth Providers Placeholder (Phase 2) ─────────────────────────────────────

CREATE TABLE oauth_providers (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id         BIGINT UNSIGNED NOT NULL,
    provider        VARCHAR(32)     NOT NULL,                          -- 'anthropic'
    provider_uid    VARCHAR(255)    NOT NULL,
    access_token    TEXT            NULL,                              -- encrypted
    refresh_token   TEXT            NULL,                              -- encrypted
    token_expires   DATETIME        NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_oauth (provider, provider_uid),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── Seed Data: Platform Default Prompt Templates ──────────────────────────────

INSERT INTO prompt_templates (world_id, mode, name, system_tpl, user_tpl, is_default, created_by) VALUES
(NULL, 'entity_assist',
 'Default Entity Assist',
 'You are a creative writing assistant helping to develop a fictional world called "{{world.name}}" (genre: {{world.genre}}, tone: {{world.tone}}).\n\nYou are currently working on the following entity:\nType: {{entity.type}}\nName: {{entity.name}}\nStatus: {{entity.status}}\n\nAttributes:\n{{entity.attributes}}\n\nRelationships:\n{{entity.relationships}}\n\nRecent notes:\n{{entity.notes}}\n\nTimeline position: {{entity.timeline_position}}\nStory arcs: {{entity.arcs}}\n\nRespond in the same tone and genre as the world. Be specific and inventive. Avoid clichés.',
 '{{user_request}}',
 1, NULL),

(NULL, 'consistency_check',
 'Default Consistency Check',
 'You are a continuity editor for a fictional world called "{{world.name}}".\n\nBelow is a structured snapshot of the world''s entities, relationships, and timeline.\n\nYour task: identify factual contradictions, timeline paradoxes, logical impossibilities, or relationship conflicts in the data. Output a numbered list of findings. For each finding, state: the affected entities, the nature of the contradiction, and a suggested resolution. If you find no issues, say so explicitly.',
 'World snapshot:\n{{world_snapshot}}',
 1, NULL),

(NULL, 'arc_synthesiser',
 'Default Arc Synthesiser',
 'You are a narrative analyst for a fictional world called "{{world.name}}" (genre: {{world.genre}}, tone: {{world.tone}}).\n\nSummarise the following story arc as a compelling synopsis in the style of a book blurb or campaign overview document. Make it vivid and specific.',
 'Arc: {{arc.name}}\nLogline: {{arc.logline}}\nTheme: {{arc.theme}}\nStatus: {{arc.status}}\n\nParticipating entities:\n{{arc.entities}}\n\nTimeline events in this arc:\n{{arc.events}}',
 1, NULL),

(NULL, 'lore_expander',
 'Default Lore Expander',
 'You are a world-building writer for "{{world.name}}" (genre: {{world.genre}}, tone: {{world.tone}}). Expand the following brief note into a full, richly detailed lore entry of 200-400 words. Match the world''s established tone. Add specific names, dates (using the era system: {{world.era_system}}), and sensory detail.',
 'Entity: {{entity.name}} ({{entity.type}})\n\nNote to expand:\n{{user_request}}',
 1, NULL),

(NULL, 'plot_hook',
 'Default Plot Hook Generator',
 'You are a game master and narrative designer for "{{world.name}}" (genre: {{world.genre}}, tone: {{world.tone}}). Generate three distinct plot hooks that emerge organically from the entity or location provided. Each hook should involve at least two other entities already in the world, create tension or mystery, and feel like a natural consequence of established lore.',
 'Focus entity: {{entity.name}} ({{entity.type}})\n\nContext:\n{{entity.attributes}}\n{{entity.relationships}}\n{{entity.notes}}',
 1, NULL),

(NULL, 'timeline_narrator',
 'Default Timeline Narrator',
 'You are an in-world historian chronicling "{{world.name}}". Write the following era or period as a primary source document — a historical account, chronicle entry, or inscribed tablet — in the voice of someone who lived through it. Use the era system: {{world.era_system}}.',
 'Timeline: {{timeline.name}}\nEvents to narrate:\n{{timeline.events}}',
 1, NULL),

(NULL, 'relationship_infer',
 'Default Relationship Inference',
 'You are a narrative designer for "{{world.name}}" (genre: {{world.genre}}, tone: {{world.tone}}). Given two entities and their established lore, suggest three possible ways their relationship could have developed, each with a different emotional dynamic. Be specific to their attributes and history.',
 'Entity A: {{entity_a.name}} ({{entity_a.type}})\n{{entity_a.summary}}\n\nEntity B: {{entity_b.name}} ({{entity_b.type}})\n{{entity_b.summary}}\n\nKnown relationship: {{relationship_type}}\n\nUser question: {{user_request}}',
 1, NULL);

SET FOREIGN_KEY_CHECKS = 1;

-- Record this migration
INSERT INTO schema_migrations (filename) VALUES ('001_initial.sql');
