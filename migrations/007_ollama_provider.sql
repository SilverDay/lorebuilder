-- Migration 007: Add custom AI endpoint URL for Ollama / local model support
-- Allows worlds to specify a custom API endpoint (e.g. http://localhost:11434)

ALTER TABLE worlds
    ADD COLUMN IF NOT EXISTS ai_endpoint_url VARCHAR(512) DEFAULT NULL
    AFTER ai_provider;

INSERT INTO schema_migrations (filename, applied_at)
VALUES ('007_ollama_provider.sql', NOW());
