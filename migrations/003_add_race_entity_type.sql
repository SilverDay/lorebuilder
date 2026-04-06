-- LoreBuilder — Migration 003
-- Adds 'Race' to the entities.type ENUM
-- Engine: MariaDB 10.11

SET NAMES utf8mb4;

ALTER TABLE entities
  MODIFY COLUMN type ENUM(
    'Character','Location','Event','Faction',
    'Artefact','Creature','Concept','StoryArc','Timeline','Race'
  ) NOT NULL;

INSERT INTO schema_migrations (filename) VALUES ('003_add_race_entity_type.sql');
