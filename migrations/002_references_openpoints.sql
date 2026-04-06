-- LoreBuilder — Migration 002
-- Adds: world_references, open_points tables
-- Engine: MariaDB 10.11

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─── World References ──────────────────────────────────────────────────────────
-- Research sources: URLs, books, articles, films, etc. scoped to a world.
-- Each reference can optionally be linked to one or more entities via ref_entities.

CREATE TABLE world_references (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    world_id        BIGINT UNSIGNED NOT NULL,
    created_by      BIGINT UNSIGNED NOT NULL,
    ref_type        ENUM('url','book','article','film','podcast','other') NOT NULL DEFAULT 'url',
    title           VARCHAR(512)    NOT NULL,
    url             TEXT            NULL,                         -- for web references
    author          VARCHAR(255)    NULL,                         -- author / creator
    description     TEXT            NULL,                         -- why this is relevant
    tags            JSON            NULL,                         -- freeform string array
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at      DATETIME        NULL DEFAULT NULL,
    FOREIGN KEY (world_id)   REFERENCES worlds(id)  ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)   ON DELETE RESTRICT,
    INDEX idx_ref_world (world_id, ref_type, deleted_at),
    FULLTEXT INDEX ft_ref (title, description)
) ENGINE=InnoDB;

-- Optional entity linkage for references (many-to-many)
CREATE TABLE reference_entities (
    reference_id    BIGINT UNSIGNED NOT NULL,
    entity_id       BIGINT UNSIGNED NOT NULL,
    world_id        BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (reference_id, entity_id),
    FOREIGN KEY (reference_id) REFERENCES world_references(id) ON DELETE CASCADE,
    FOREIGN KEY (entity_id)    REFERENCES entities(id)         ON DELETE CASCADE
) ENGINE=InnoDB;

-- ─── Open Points ───────────────────────────────────────────────────────────────
-- Unresolved questions, plot holes, and clarifications needed.
-- Typically created automatically from AI sessions or manually by authors.

CREATE TABLE open_points (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    world_id        BIGINT UNSIGNED NOT NULL,
    created_by      BIGINT UNSIGNED NOT NULL,
    entity_id       BIGINT UNSIGNED NULL DEFAULT NULL,           -- related entity, if any
    ai_session_id   BIGINT UNSIGNED NULL DEFAULT NULL,           -- AI session that raised it
    title           VARCHAR(512)    NOT NULL,                    -- short question / issue title
    description     TEXT            NULL,                        -- full context
    status          ENUM('open','in_progress','resolved','wont_fix') NOT NULL DEFAULT 'open',
    priority        ENUM('low','medium','high','critical')       NOT NULL DEFAULT 'medium',
    resolution      TEXT            NULL,                        -- filled when resolved
    resolved_by     BIGINT UNSIGNED NULL DEFAULT NULL,
    resolved_at     DATETIME        NULL DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at      DATETIME        NULL DEFAULT NULL,
    FOREIGN KEY (world_id)    REFERENCES worlds(id)   ON DELETE CASCADE,
    FOREIGN KEY (created_by)  REFERENCES users(id)    ON DELETE RESTRICT,
    FOREIGN KEY (entity_id)   REFERENCES entities(id) ON DELETE SET NULL,
    FOREIGN KEY (resolved_by) REFERENCES users(id)    ON DELETE SET NULL,
    INDEX idx_op_world  (world_id, status, priority, deleted_at),
    INDEX idx_op_entity (entity_id, status),
    FULLTEXT INDEX ft_op (title, description)
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;

INSERT INTO schema_migrations (filename) VALUES ('002_references_openpoints.sql');
