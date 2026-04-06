-- Allow NULL strength on entity_relationships (NULL = no explicit strength set)
ALTER TABLE entity_relationships
  MODIFY COLUMN strength TINYINT UNSIGNED NULL DEFAULT NULL;
