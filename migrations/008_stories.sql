-- LoreBuilder — Story Board Tables
-- Migration: 008_stories.sql
-- Adds stories, story_entities, story_notes tables + prompt template seeds

SET NAMES utf8mb4;

-- ─── Stories ───────────────────────────────────────────────────────────────────

CREATE TABLE stories (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    world_id        BIGINT UNSIGNED NOT NULL,
    created_by      BIGINT UNSIGNED NOT NULL,
    arc_id          BIGINT UNSIGNED NULL DEFAULT NULL,

    title           VARCHAR(255)    NOT NULL,
    slug            VARCHAR(300)    NOT NULL,
    content         LONGTEXT        NOT NULL DEFAULT '',
    synopsis        VARCHAR(2000)   NULL DEFAULT NULL,
    status          ENUM('draft','in_progress','review','complete','archived')
                    NOT NULL DEFAULT 'draft',
    word_count      INT UNSIGNED    NOT NULL DEFAULT 0,
    sort_order      SMALLINT        NOT NULL DEFAULT 0,

    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
    deleted_at      DATETIME        NULL DEFAULT NULL,

    UNIQUE KEY uq_story_slug (world_id, slug),
    FOREIGN KEY (world_id)   REFERENCES worlds(id)      ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)       ON DELETE RESTRICT,
    FOREIGN KEY (arc_id)     REFERENCES story_arcs(id)  ON DELETE SET NULL,
    INDEX idx_story_world  (world_id, status, deleted_at),
    INDEX idx_story_arc    (arc_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── Story ↔ Entity Junction ───────────────────────────────────────────────────

CREATE TABLE story_entities (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    story_id        BIGINT UNSIGNED NOT NULL,
    entity_id       BIGINT UNSIGNED NOT NULL,
    world_id        BIGINT UNSIGNED NOT NULL,

    role            VARCHAR(128)    NULL DEFAULT NULL,
    sort_order      SMALLINT        NOT NULL DEFAULT 0,

    UNIQUE KEY uq_story_entity (story_id, entity_id),
    FOREIGN KEY (story_id)  REFERENCES stories(id)   ON DELETE CASCADE,
    FOREIGN KEY (entity_id) REFERENCES entities(id)  ON DELETE CASCADE,
    FOREIGN KEY (world_id)  REFERENCES worlds(id)    ON DELETE CASCADE,
    INDEX idx_se_entity (entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── Story ↔ Note Junction ────────────────────────────────────────────────────

CREATE TABLE story_notes (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    story_id        BIGINT UNSIGNED NOT NULL,
    note_id         BIGINT UNSIGNED NOT NULL,
    world_id        BIGINT UNSIGNED NOT NULL,

    UNIQUE KEY uq_story_note (story_id, note_id),
    FOREIGN KEY (story_id) REFERENCES stories(id)     ON DELETE CASCADE,
    FOREIGN KEY (note_id)  REFERENCES lore_notes(id)  ON DELETE CASCADE,
    FOREIGN KEY (world_id) REFERENCES worlds(id)      ON DELETE CASCADE,
    INDEX idx_sn_note (note_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ─── Add story_id to ai_sessions for story AI history ──────────────────────────

ALTER TABLE ai_sessions
    ADD COLUMN story_id BIGINT UNSIGNED NULL DEFAULT NULL AFTER entity_id,
    ADD FOREIGN KEY (story_id) REFERENCES stories(id) ON DELETE SET NULL,
    ADD INDEX idx_ai_story (story_id);

-- ─── Prompt Template Seeds for Story Modes ─────────────────────────────────────

INSERT INTO prompt_templates (world_id, mode, name, system_tpl, user_tpl, is_default)
VALUES
(NULL, 'story_assist', 'Story Writing Assistant',
 'You are a writing assistant for the world "{{world.name}}" ({{world.genre}}, {{world.tone}}). Help the author continue, refine, or expand their story while respecting established lore.\n\nLinked entities:\n{{story.entities}}\n\nRelevant notes:\n{{story.notes}}\n\nStory arc: {{story.arc}}\n\nStory so far (around current position):\n{{story.context_window}}',
 '{{user_prompt}}', 1),

(NULL, 'story_consistency', 'Story Consistency Checker',
 'You are a continuity editor for "{{world.name}}". Check the following story text for contradictions with established lore. Only flag genuine inconsistencies, not creative liberties.\n\nEstablished lore:\n{{story.entity_details}}\n{{story.notes}}',
 'Check this text for lore consistency:\n\n{{story.content}}', 1),

(NULL, 'entity_scan', 'Entity Scanner',
 'You are analysing a story set in "{{world.name}}". Identify characters, locations, factions, artefacts, or concepts mentioned in the text that might warrant their own entity entry.\n\nExisting entities in this world:\n{{world.entity_names}}',
 'Scan this text and list any named characters, places, factions, or concepts that are not in the existing entity list:\n\n{{story.content}}', 1);
