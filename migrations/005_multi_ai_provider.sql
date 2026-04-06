-- Migration 005: Add multi-provider support
-- Adds ai_provider column to worlds and provider column to ai_sessions

ALTER TABLE worlds
    ADD COLUMN IF NOT EXISTS ai_provider VARCHAR(32) NOT NULL DEFAULT 'anthropic'
    AFTER ai_model;

ALTER TABLE ai_sessions
    ADD COLUMN IF NOT EXISTS provider VARCHAR(32) NOT NULL DEFAULT 'anthropic'
    AFTER model;

INSERT INTO schema_migrations (filename, applied_at)
VALUES ('005_multi_ai_provider.sql', NOW());
