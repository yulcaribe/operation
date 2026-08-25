<?php
declare(strict_types=1);

final class Authorization
{
    private static array $cache = [];

    public static function can(array $user, string $permission, array $resource = []): bool
    {
        $userId = (int)$user['id'];
        $rows = self::permissionRows($userId, $permission);
        $hasResource = !empty($resource['flight_id']) || !empty($resource['airline_id']);

        foreach ($rows['overrides'] as $row) {
            if ($row['effect'] === 'deny' && self::scopeMatches($userId, $row, $resource, $hasResource)) return false;
        }
        foreach ($rows['overrides'] as $row) {
            if ($row['effect'] === 'allow' && self::scopeMatches($userId, $row, $resource, $hasResource)) return true;
        }
        foreach ($rows['roles'] as $row) {
            if (self::scopeMatches($userId, $row, $resource, $hasResource)) return true;
        }
        return false;
    }

    public static function require(array $user, string $permission, array $resource = []): void
    {
        if (!self::can($user, $permission, $resource)) throw new RuntimeException('Bu işlem için yetkiniz yok.');
    }

    public static function canAny(array $user, array $permissions): bool
    {
        foreach ($permissions as $permission) if (self::can($user, $permission)) return true;
        return false;
    }

    public static function flightContext(int $flightId): array
    {
        $flight = DB::fetch('SELECT id, airline_id FROM flights WHERE id = ? AND deleted_at IS NULL', [$flightId]);
        return ['flight_id' => $flightId, 'airline_id' => (int)($flight['airline_id'] ?? 0)];
    }

    public static function visibleAirlineIds(array $user, string $permission = 'flights.view'): array
    {
        $rows = DB::fetchAll(
            'SELECT DISTINCT urs.airline_id FROM user_role_scopes urs
             JOIN role_permissions rp ON rp.role_id = urs.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE urs.user_id = ? AND urs.scope_type = "airline" AND p.code = ? AND urs.airline_id IS NOT NULL',
            [(int)$user['id'], $permission]
        );
        return array_map('intval', array_column($rows, 'airline_id'));
    }

    public static function isGlobal(array $user, string $permission): bool
    {
        return (bool)DB::fetch(
            'SELECT 1 FROM user_role_scopes urs
             JOIN role_permissions rp ON rp.role_id = urs.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE urs.user_id = ? AND urs.scope_type = "global" AND p.code = ? LIMIT 1',
            [(int)$user['id'], $permission]
        );
    }

    public static function roles(): array
    {
        return DB::fetchAll('SELECT id, code, name, description, is_system FROM roles ORDER BY id');
    }

    public static function permissionGroups(): array
    {
        $groups = [];
        foreach (DB::fetchAll('SELECT id, code, name, permission_group FROM permissions ORDER BY permission_group, name') as $permission) {
            $groups[$permission['permission_group']][] = $permission;
        }
        return $groups;
    }

    public static function rolePermissionIds(int $roleId): array
    {
        return array_map('intval', array_column(DB::fetchAll('SELECT permission_id FROM role_permissions WHERE role_id = ?', [$roleId]), 'permission_id'));
    }

    public static function saveRolePermissions(array $actor, int $roleId, array $permissionIds): void
    {
        self::require($actor, 'roles.manage');
        $role = DB::fetch('SELECT * FROM roles WHERE id = ?', [$roleId]);
        if (!$role || $role['code'] === 'admin') throw new RuntimeException('Admin rolünün tüm yetkileri kilitlidir.');
        $valid = array_map('intval', array_column(DB::fetchAll('SELECT id FROM permissions'), 'id'));
        $permissionIds = array_values(array_unique(array_intersect(array_map('intval', $permissionIds), $valid)));
        $dashboard = DB::fetch('SELECT id FROM permissions WHERE code = "dashboard.view"');
        if ($dashboard) $permissionIds[] = (int)$dashboard['id'];
        $permissionIds = array_values(array_unique($permissionIds));
        DB::begin();
        try {
            DB::execute('DELETE FROM role_permissions WHERE role_id = ?', [$roleId]);
            foreach ($permissionIds as $permissionId) DB::insert('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)', [$roleId, $permissionId]);
            Audit::record((int)$actor['id'], 'role.permissions_updated', 'role', $roleId, ['permission_ids' => $permissionIds]);
            DB::commit();
            self::$cache = [];
        } catch (Throwable $error) {
            DB::rollback();
            throw $error;
        }
    }

    private static function permissionRows(int $userId, string $permission): array
    {
        $key = $userId . ':' . $permission;
        if (isset(self::$cache[$key])) return self::$cache[$key];
        $roles = DB::fetchAll(
            'SELECT urs.scope_type, urs.airline_id
             FROM user_role_scopes urs
             JOIN role_permissions rp ON rp.role_id = urs.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE urs.user_id = ? AND p.code = ?',
            [$userId, $permission]
        );
        $overrides = DB::fetchAll(
            'SELECT upo.effect, upo.scope_type, upo.airline_id
             FROM user_permission_overrides upo
             JOIN permissions p ON p.id = upo.permission_id
             WHERE upo.user_id = ? AND p.code = ? ORDER BY (upo.effect = "deny") DESC',
            [$userId, $permission]
        );
        return self::$cache[$key] = ['roles' => $roles, 'overrides' => $overrides];
    }

    private static function scopeMatches(int $userId, array $row, array $resource, bool $hasResource): bool
    {
        if (!$hasResource) return true;
        if ($row['scope_type'] === 'global') return true;
        if ($row['scope_type'] === 'airline') return (int)($resource['airline_id'] ?? 0) > 0 && (int)$row['airline_id'] === (int)$resource['airline_id'];
        if ($row['scope_type'] === 'assigned') {
            return !empty($resource['flight_id']) && (bool)DB::fetch(
                'SELECT 1 FROM flight_assignments
                 WHERE flight_id = ? AND user_id = ? AND status IN ("active", "completed")
                   AND id = (SELECT MAX(latest.id) FROM flight_assignments latest WHERE latest.flight_id = ? AND latest.status IN ("active", "completed"))
                 LIMIT 1',
                [(int)$resource['flight_id'], $userId, (int)$resource['flight_id']]
            );
        }
        return false;
    }
}

function can(array $user, string $permission, array $resource = []): bool
{
    return Authorization::can($user, $permission, $resource);
}
