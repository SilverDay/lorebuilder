<?php
/**
 * LoreBuilder — Authorisation Guard
 *
 * Enforces world-scoped access control. Every controller that reads or writes
 * world data MUST call Guard::requireWorldAccess() before touching the DB.
 *
 * Role hierarchy (ascending privilege):
 *   viewer → reviewer → author → admin → owner
 *
 * Usage:
 *   // Require at least 'author' role — throws on failure, returns membership row
 *   $membership = Guard::requireWorldAccess($worldId, $userId, minRole: 'author');
 *
 *   // Non-throwing lookup (returns null if no access)
 *   $membership = Guard::worldMembership($worldId, $userId);
 *
 *   // Check role sufficiency without DB call
 *   if (Guard::roleAtLeast($membership['role'], 'admin')) { ... }
 *
 *   // Platform admin gate
 *   Guard::requirePlatformAdmin($sessionUser);
 *
 * Throws AuthException (defined in Auth.php) with:
 *   - HTTP 404 + NOT_FOUND   if the world does not exist or is soft-deleted
 *   - HTTP 403 + FORBIDDEN   if the world is suspended
 *   - HTTP 403 + FORBIDDEN   if the user has no membership
 *   - HTTP 403 + FORBIDDEN   if the user's role is below minRole
 *
 * Dependencies: Auth.php (for AuthException), DB.php
 */

declare(strict_types=1);

require_once __DIR__ . '/Auth.php';

class Guard
{
    // Roles in ascending privilege order — position encodes ordinal rank
    private const ROLE_ORDER = ['viewer', 'reviewer', 'author', 'admin', 'owner'];

    // ─── Primary Gate ─────────────────────────────────────────────────────────

    /**
     * Assert that $userId has at least $minRole in $worldId.
     *
     * Also verifies:
     *   - The world exists and is not soft-deleted → 404
     *   - The world is not suspended              → 403
     *   - The user has an active membership       → 403
     *   - The membership role meets $minRole      → 403
     *
     * Platform admins bypass the role check but NOT the world-existence check;
     * they still cannot act on deleted worlds.
     *
     * @param  int    $worldId      Target world ID
     * @param  int    $userId       Authenticated user ID (from Auth::requireSession())
     * @param  string $minRole      Minimum required role: viewer|reviewer|author|admin|owner
     * @param  bool   $isPlatformAdmin  Pass Auth::user()['is_platform_admin'] to bypass role check
     * @return array<string, mixed>  The world_members row for the user
     * @throws AuthException
     */
    public static function requireWorldAccess(
        int $worldId,
        int $userId,
        string $minRole = 'viewer',
        bool $isPlatformAdmin = false
    ): array {
        self::assertValidRole($minRole);

        // 1. Verify world exists and is accessible
        $world = DB::queryOne(
            'SELECT id, status FROM worlds WHERE id = :wid AND deleted_at IS NULL',
            ['wid' => $worldId]
        );

        if ($world === null) {
            throw new AuthException('World not found.', 'NOT_FOUND', 404);
        }

        if ($world['status'] === 'suspended' && !$isPlatformAdmin) {
            throw new AuthException(
                'This world has been suspended.',
                'FORBIDDEN',
                403
            );
        }

        // 2. Platform admins skip the membership check
        if ($isPlatformAdmin) {
            // Synthetic membership row so callers always get a consistent return type
            return [
                'world_id' => $worldId,
                'user_id'  => $userId,
                'role'     => 'owner',          // treat as owner for permission purposes
                '_platform_admin_bypass' => true,
            ];
        }

        // 3. Fetch active membership
        $membership = self::worldMembership($worldId, $userId);

        if ($membership === null) {
            throw new AuthException(
                'You are not a member of this world.',
                'FORBIDDEN',
                403
            );
        }

        // 4. Check role sufficiency
        if (!self::roleAtLeast($membership['role'], $minRole)) {
            throw new AuthException(
                'Your role in this world does not permit this action.',
                'FORBIDDEN',
                403
            );
        }

        return $membership;
    }

    // ─── Non-throwing Membership Lookup ───────────────────────────────────────

    /**
     * Return the world_members row for a user, or null if no active membership exists.
     * Does not check world status or role sufficiency — use requireWorldAccess() for gates.
     *
     * @return array<string, mixed>|null
     */
    public static function worldMembership(int $worldId, int $userId): ?array
    {
        return DB::queryOne(
            'SELECT id, world_id, user_id, role, joined_at
               FROM world_members
              WHERE world_id  = :wid
                AND user_id   = :uid
                AND deleted_at IS NULL',
            ['wid' => $worldId, 'uid' => $userId]
        );
    }

    // ─── Platform Admin Gate ──────────────────────────────────────────────────

    /**
     * Assert that the session user is a platform admin.
     * Pass Auth::user() as $sessionUser.
     *
     * @param  array<string, mixed> $sessionUser  From Auth::requireSession()
     * @throws AuthException  HTTP 403 if not a platform admin
     */
    public static function requirePlatformAdmin(array $sessionUser): void
    {
        if (empty($sessionUser['is_platform_admin'])) {
            throw new AuthException(
                'Platform administrator access required.',
                'FORBIDDEN',
                403
            );
        }
    }

    // ─── Role Utilities ───────────────────────────────────────────────────────

    /**
     * Return true if $actual role is equal to or higher than $minimum.
     *
     * Examples:
     *   roleAtLeast('admin',    'author')  → true
     *   roleAtLeast('author',   'admin')   → false
     *   roleAtLeast('owner',    'owner')   → true
     *   roleAtLeast('reviewer', 'viewer')  → true
     */
    public static function roleAtLeast(string $actual, string $minimum): bool
    {
        $order   = array_flip(self::ROLE_ORDER);
        $rankAct = $order[$actual]  ?? -1;
        $rankMin = $order[$minimum] ?? PHP_INT_MAX;
        return $rankAct >= $rankMin;
    }

    /**
     * Return true if $actual role is strictly higher than $minimum.
     * Useful for distinguishing "can manage other admins" (owner only) checks.
     */
    public static function roleAbove(string $actual, string $minimum): bool
    {
        $order   = array_flip(self::ROLE_ORDER);
        $rankAct = $order[$actual]  ?? -1;
        $rankMin = $order[$minimum] ?? PHP_INT_MAX;
        return $rankAct > $rankMin;
    }

    /**
     * Return all roles that meet or exceed $minRole, in ascending order.
     * Useful for building SQL IN clauses.
     *
     * @return string[]
     */
    public static function rolesAtLeast(string $minRole): array
    {
        $order  = array_flip(self::ROLE_ORDER);
        $minRank = $order[$minRole] ?? PHP_INT_MAX;
        return array_filter(
            self::ROLE_ORDER,
            fn(string $r): bool => ($order[$r] ?? -1) >= $minRank
        );
    }

    // ─── Ownership Shortcuts ──────────────────────────────────────────────────

    /**
     * Verify that $userId is the owner of an entity row.
     * Throws 403 if not. Use after requireWorldAccess() to gate edit-own-content.
     *
     * @param  int    $entityCreatedBy   created_by column from the entity row
     * @param  int    $userId            Authenticated user ID
     * @param  string $memberRole        User's role in the world (from membership row)
     * @param  string $editAboveRole     Roles above this can edit others' content (default: admin)
     * @throws AuthException
     */
    public static function requireOwnerOrRole(
        int $entityCreatedBy,
        int $userId,
        string $memberRole,
        string $editAboveRole = 'author'
    ): void {
        if ($entityCreatedBy === $userId) {
            return;  // owns the entity
        }

        if (self::roleAbove($memberRole, $editAboveRole)) {
            return;  // admin/owner can edit anyone's content
        }

        throw new AuthException(
            'You can only edit your own content.',
            'FORBIDDEN',
            403
        );
    }

    // ─── Internals ────────────────────────────────────────────────────────────

    private static function assertValidRole(string $role): void
    {
        if (!in_array($role, self::ROLE_ORDER, true)) {
            throw new \InvalidArgumentException(
                "Invalid role '{$role}'. Must be one of: " . implode(', ', self::ROLE_ORDER)
            );
        }
    }
}
