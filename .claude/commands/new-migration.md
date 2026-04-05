# /project:new-migration
# Usage: /project:new-migration [short description]
# Example: /project:new-migration add_entity_cover_image

Create a new numbered SQL migration for LoreBuilder.

## Steps

1. Find the highest existing migration number in migrations/:
   `ls migrations/ | sort | tail -1`

2. Create migrations/NNN_description.sql where NNN = previous + 1, zero-padded to 3 digits.

3. File must:
   - Start with: `-- LoreBuilder Migration NNN: description`
   - Use `CREATE TABLE IF NOT EXISTS` or `ALTER TABLE`
   - NEVER modify a previous migration file
   - End with: `INSERT INTO schema_migrations (filename) VALUES ('NNN_description.sql');`
   - Wrap in SET FOREIGN_KEY_CHECKS = 0; ... SET FOREIGN_KEY_CHECKS = 1; if adding FKs

4. All new world-scoped tables must include:
   - `world_id BIGINT UNSIGNED NOT NULL` with FK to worlds.id ON DELETE CASCADE
   - `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`
   - `updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`
   - `deleted_at DATETIME NULL DEFAULT NULL` (if soft-deletable)

5. Test locally: `php scripts/migrate.php --dry-run`

6. Append to DECISIONS.md: why this schema change is needed.
