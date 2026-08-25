<?php
declare(strict_types=1);

final class UserService
{
    public static function all(): array
    {
        return DB::fetchAll(
            'SELECT u.id, u.username, u.email, u.first_name, u.last_name, u.status, u.last_login_at,
                    (SELECT GROUP_CONCAT(DISTINCT r.name ORDER BY r.name SEPARATOR ", ")
                     FROM user_role_scopes urs JOIN roles r ON r.id = urs.role_id WHERE urs.user_id = u.id) AS roles,
                    (SELECT GROUP_CONCAT(DISTINCT a.icao_code ORDER BY a.icao_code SEPARATOR ", ")
                     FROM user_role_scopes urs JOIN airlines a ON a.id = urs.airline_id WHERE urs.user_id = u.id) AS icao_scopes
             FROM users u
             WHERE u.status != "deleted" ORDER BY u.first_name, u.last_name'
        );
    }

    public static function find(int $userId): ?array
    {
        return DB::fetch('SELECT * FROM users WHERE id = ? AND status != "deleted"', [$userId]);
    }

    public static function access(int $userId): array
    {
        $scopes = DB::fetchAll(
            'SELECT r.code, urs.scope_type, urs.airline_id FROM user_role_scopes urs JOIN roles r ON r.id = urs.role_id WHERE urs.user_id = ?',
            [$userId]
        );
        $overrides = DB::fetchAll(
            'SELECT permission_id, effect, scope_type, airline_id FROM user_permission_overrides WHERE user_id = ?',
            [$userId]
        );
        return [
            'roles' => array_values(array_unique(array_column($scopes, 'code'))),
            'airline_ids' => array_values(array_unique(array_map('intval', array_filter(array_column($scopes, 'airline_id'))))),
            'overrides' => $overrides,
        ];
    }

    public static function save(array $actor, array $data): int
    {
        $userId = (int)($data['user_id'] ?? 0);
        Authorization::require($actor, $userId > 0 ? 'users.update' : 'users.create');
        $target = $userId > 0 ? self::find($userId) : null;
        if ($userId > 0 && !$target) throw new RuntimeException('Kullanıcı bulunamadı.');

        $targetIsAdmin = $userId > 0 && self::isAdmin($userId);
        $username = strtolower(trim((string)($data['username'] ?? '')));
        $email = strtolower(trim((string)($data['email'] ?? '')));
        $firstName = trim((string)($data['first_name'] ?? ''));
        $lastName = trim((string)($data['last_name'] ?? ''));
        $status = (string)($data['status'] ?? 'active');
        $password = (string)($data['password'] ?? '');
        if ($username === '' || $firstName === '' || $lastName === '') throw new RuntimeException('Kullanıcı adı, ad ve soyad zorunludur.');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('E-posta adresi geçersiz.');
        if (!in_array($status, ['active', 'inactive'], true)) throw new RuntimeException('Hesap durumu geçersiz.');
        if (!$target && strlen($password) < 12) throw new RuntimeException('Geçici şifre en az 12 karakter olmalıdır.');
        if ($targetIsAdmin) $status = 'active';

        $duplicate = DB::fetch(
            'SELECT id FROM users WHERE (username = ? OR (? != "" AND email = ?)) AND id != ? AND status != "deleted" LIMIT 1',
            [$username, $email, $email, $userId]
        );
        if ($duplicate) throw new RuntimeException('Kullanıcı adı veya e-posta zaten kullanılıyor.');

        DB::begin();
        try {
            if ($target) {
                $params = [$username, $email === '' ? null : $email, $firstName, $lastName, $status];
                $passwordSql = '';
                if ($password !== '') {
                    if (strlen($password) < 12) throw new RuntimeException('Yeni şifre en az 12 karakter olmalıdır.');
                    $passwordSql = ', password = ?, must_change_password = 1';
                    $params[] = password_hash($password, PASSWORD_DEFAULT);
                }
                $params[] = $userId;
                DB::execute('UPDATE users SET username = ?, email = ?, first_name = ?, last_name = ?, status = ?' . $passwordSql . ' WHERE id = ?', $params);
            } else {
                $userId = DB::insert(
                    'INSERT INTO users (username, email, password, first_name, last_name, status, must_change_password, created_by) VALUES (?, ?, ?, ?, ?, ?, 1, ?)',
                    [$username, $email === '' ? null : $email, password_hash($password, PASSWORD_DEFAULT), $firstName, $lastName, $status, (int)$actor['id']]
                );
            }

            if (!$targetIsAdmin) self::saveAccess($actor, $userId, $data);
            Audit::record((int)$actor['id'], $target ? 'user.updated' : 'user.created', 'user', $userId, [
                'username' => $username, 'status' => $status, 'roles' => (array)($data['roles'] ?? []), 'airline_ids' => (array)($data['airline_ids'] ?? []),
            ]);
            DB::commit();
            return $userId;
        } catch (Throwable $error) {
            DB::rollback();
            throw $error;
        }
    }

    public static function delete(array $actor, int $userId): void
    {
        Authorization::require($actor, 'users.delete');
        if ($userId <= 0 || $userId === (int)$actor['id'] || self::isAdmin($userId)) throw new RuntimeException('Son admin veya mevcut oturum silinemez.');
        $target = self::find($userId);
        if (!$target) throw new RuntimeException('Kullanıcı bulunamadı.');
        $hasHistory = (bool)DB::fetch('SELECT 1 FROM flight_assignments WHERE user_id = ? LIMIT 1', [$userId]);
        DB::begin();
        try {
            Audit::record((int)$actor['id'], 'user.deleted', 'user', $userId, ['mode' => $hasHistory ? 'anonymized' : 'hard_delete']);
            if (!$hasHistory) {
                DB::execute('DELETE FROM users WHERE id = ?', [$userId]);
            } else {
                DB::execute(
                    'UPDATE users SET username = ?, email = NULL, first_name = "Silinmiş", last_name = "Kullanıcı", password = ?, status = "deleted", deleted_at = NOW() WHERE id = ?',
                    ['deleted_' . $userId . '_' . time(), password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), $userId]
                );
                DB::execute('UPDATE flight_assignments SET status = "revoked", unassigned_at = NOW() WHERE user_id = ? AND status = "active"', [$userId]);
            }
            DB::commit();
        } catch (Throwable $error) {
            DB::rollback();
            throw $error;
        }
    }

    public static function isAdmin(int $userId): bool
    {
        return (bool)DB::fetch(
            'SELECT 1 FROM user_role_scopes urs JOIN roles r ON r.id = urs.role_id WHERE urs.user_id = ? AND r.code = "admin" AND urs.scope_type = "global" LIMIT 1',
            [$userId]
        );
    }

    private static function saveAccess(array $actor, int $userId, array $data): void
    {
        Authorization::require($actor, 'users.assign_access');
        $roles = array_values(array_intersect(array_map('strval', (array)($data['roles'] ?? [])), ['operation', 'supervisor']));
        $airlineIds = array_values(array_unique(array_filter(array_map('intval', (array)($data['airline_ids'] ?? [])))));
        if (!$roles) throw new RuntimeException('En az bir rol seçilmelidir.');
        if (in_array('supervisor', $roles, true) && !$airlineIds) throw new RuntimeException('Supervisor için en az bir ICAO kapsamı seçilmelidir.');

        $roleRows = DB::fetchAll('SELECT id, code FROM roles WHERE code IN ("operation", "supervisor")');
        $roleIds = array_column($roleRows, 'id', 'code');
        DB::execute('DELETE FROM user_role_scopes WHERE user_id = ?', [$userId]);
        if (in_array('operation', $roles, true)) {
            DB::insert('INSERT INTO user_role_scopes (user_id, role_id, scope_type, created_by) VALUES (?, ?, "assigned", ?)', [$userId, (int)$roleIds['operation'], (int)$actor['id']]);
        }
        if (in_array('supervisor', $roles, true)) {
            foreach ($airlineIds as $airlineId) {
                if (!DB::fetch('SELECT id FROM airlines WHERE id = ? AND status = "active"', [$airlineId])) continue;
                DB::insert('INSERT INTO user_role_scopes (user_id, role_id, scope_type, airline_id, created_by) VALUES (?, ?, "airline", ?, ?)', [$userId, (int)$roleIds['supervisor'], $airlineId, (int)$actor['id']]);
            }
        }

        DB::execute('DELETE FROM user_permission_overrides WHERE user_id = ?', [$userId]);
        $allows = array_values(array_unique(array_map('intval', (array)($data['allow_permission_ids'] ?? []))));
        $denies = array_values(array_unique(array_map('intval', (array)($data['deny_permission_ids'] ?? []))));
        $validIds = array_map('intval', array_column(DB::fetchAll(
            'SELECT id FROM permissions WHERE code IN ("flights.view", "flights.update", "flights.cancel", "flights.delete", "flights.assign", "flights.complete", "processes.view", "processes.update", "reports.view")'
        ), 'id'));
        $scopeType = in_array('supervisor', $roles, true) ? 'airline' : 'assigned';
        $scopeAirlines = $scopeType === 'airline' ? $airlineIds : [null];
        foreach (['allow' => array_intersect($allows, $validIds), 'deny' => array_intersect($denies, $validIds)] as $effect => $permissionIds) {
            foreach ($permissionIds as $permissionId) {
                foreach ($scopeAirlines as $airlineId) {
                    DB::insert(
                        'INSERT INTO user_permission_overrides (user_id, permission_id, effect, scope_type, airline_id, created_by) VALUES (?, ?, ?, ?, ?, ?)',
                        [$userId, (int)$permissionId, $effect, $scopeType, $airlineId, (int)$actor['id']]
                    );
                }
            }
        }
    }
}

final class AirlineService
{
    public static function save(array $actor, array $data): int
    {
        Authorization::require($actor, 'airlines.manage');
        $id = (int)($data['airline_id'] ?? 0);
        $name = trim((string)($data['name'] ?? ''));
        $icao = strtoupper(trim((string)($data['icao_code'] ?? '')));
        $iata = strtoupper(trim((string)($data['iata_code'] ?? '')));
        $status = (string)($data['status'] ?? 'active');
        if ($name === '' || !preg_match('/^[A-Z0-9]{3}$/', $icao)) throw new RuntimeException('Ad ve üç karakterli ICAO kodu zorunludur.');
        if ($iata !== '' && !preg_match('/^[A-Z0-9]{2}$/', $iata)) throw new RuntimeException('IATA kodu iki karakter olmalıdır.');
        if (!in_array($status, ['active', 'inactive'], true)) throw new RuntimeException('Havayolu durumu geçersiz.');
        if ($id > 0) {
            DB::execute('UPDATE airlines SET name = ?, icao_code = ?, iata_code = ?, status = ? WHERE id = ?', [$name, $icao, $iata ?: null, $status, $id]);
        } else {
            $id = DB::insert('INSERT INTO airlines (name, icao_code, iata_code, status) VALUES (?, ?, ?, ?)', [$name, $icao, $iata ?: null, $status]);
        }
        Audit::record((int)$actor['id'], 'airline.saved', 'airline', $id, compact('name', 'icao', 'iata', 'status'));
        return $id;
    }
}

final class FlightService
{
    public static function assignedTasks(array $actor): array
    {
        return DB::fetchAll(
            'SELECT f.*, a.name AS airline_name, a.icao_code, a.iata_code, ft.name AS flight_type_name,
                    fa.status AS assignment_status
             FROM flight_assignments fa
             JOIN flights f ON f.id = fa.flight_id
             JOIN airlines a ON a.id = f.airline_id
             JOIN flight_types ft ON ft.id = f.flight_type_id
             WHERE fa.user_id = ? AND fa.status IN ("active", "completed")
               AND f.deleted_at IS NULL AND f.status IN ("scheduled", "active", "completed")
               AND fa.id = (
                    SELECT MAX(latest.id) FROM flight_assignments latest
                    WHERE latest.flight_id = fa.flight_id AND latest.status IN ("active", "completed")
               )
             ORDER BY CASE f.status WHEN "active" THEN 0 WHEN "scheduled" THEN 1 ELSE 2 END,
                      CASE WHEN f.status = "completed" THEN COALESCE(f.updated_at, f.created_at) END DESC,
                      COALESCE(f.scheduled_arrival_at, f.scheduled_departure_at) ASC',
            [(int)$actor['id']]
        );
    }

    public static function allVisible(array $actor, array $filters = [], string $permission = 'flights.view'): array
    {
        $params = [];
        $where = ['f.deleted_at IS NULL', 'f.status != "archived"'];
        if (!empty($filters['status']) && in_array($filters['status'], ['scheduled', 'active', 'completed', 'cancelled'], true)) {
            $where[] = 'f.status = ?'; $params[] = $filters['status'];
        }
        $rows = DB::fetchAll(
            'SELECT f.*, a.name AS airline_name, a.icao_code, a.iata_code, ft.name AS flight_type_name,
                    (SELECT CONCAT(u.first_name, " ", u.last_name)
                     FROM flight_assignments fa JOIN users u ON u.id = fa.user_id
                     WHERE fa.flight_id = f.id AND fa.status IN ("active", "completed")
                     ORDER BY fa.assigned_at DESC LIMIT 1) AS assignee_name
             FROM flights f JOIN airlines a ON a.id = f.airline_id JOIN flight_types ft ON ft.id = f.flight_type_id
             WHERE ' . implode(' AND ', $where) . ' ORDER BY COALESCE(f.scheduled_departure_at, f.scheduled_arrival_at) DESC',
            $params
        );
        return array_values(array_filter($rows, static fn(array $flight): bool => can($actor, $permission, self::context($flight))));
    }

    public static function find(int $flightId): ?array
    {
        return DB::fetch(
            'SELECT f.*, a.name AS airline_name, a.icao_code, a.iata_code, ft.code AS flight_type_code, ft.name AS flight_type_name
             FROM flights f JOIN airlines a ON a.id = f.airline_id JOIN flight_types ft ON ft.id = f.flight_type_id
             WHERE f.id = ? AND f.deleted_at IS NULL', [$flightId]
        );
    }

    public static function save(array $actor, array $data): int
    {
        $flightId = (int)($data['flight_id'] ?? 0);
        $existing = $flightId > 0 ? self::find($flightId) : null;
        if (!$existing) throw new RuntimeException('Yeni uçuşlar yalnızca Uçuş Ekle ekranından oluşturulabilir.');
        Authorization::require($actor, 'flights.update', self::context($existing));
        $payload = self::normalize($data);
        $payload['source'] = (string)$existing['source'];
        $payload['source_key'] = $existing['source_key'];
        $payload['actual_arrival_at'] = $existing['actual_arrival_at'];
        $payload['actual_departure_at'] = $existing['actual_departure_at'];
        $errors = self::validate($payload);
        if ($errors) throw new RuntimeException(implode(' ', $errors));
        if ($payload['status'] !== $existing['status']) throw new RuntimeException('Uçuş durumu bilgi düzenleme formundan değiştirilemez.');
        if ((int)$existing['airline_id'] !== (int)$payload['airline_id'] && !can($actor, 'flights.update', ['airline_id' => (int)$payload['airline_id']])) {
            throw new RuntimeException('Yeni havayolu kapsamı için yetkiniz yok.');
        }
        DB::begin();
        try {
            self::update($flightId, $payload, (int)$actor['id']);
            Audit::record((int)$actor['id'], 'flight.updated', 'flight', $flightId, $payload);
            DB::commit();
        } catch (Throwable $error) {
            DB::rollback();
            throw $error;
        }
        return $flightId;
    }

    public static function createManualFromImports(array $actor, array $data): int
    {
        $payload = self::normalize($data);
        $payload['status'] = 'scheduled';
        $payload['source'] = 'manual';
        $payload['source_key'] = null;
        $payload['actual_arrival_at'] = null;
        $payload['actual_departure_at'] = null;
        Authorization::require($actor, 'imports.commit', ['airline_id' => (int)$payload['airline_id']]);
        $errors = self::validate($payload);
        if ($errors) throw new RuntimeException(implode(' ', $errors));

        DB::begin();
        try {
            $flightId = self::create($payload, (int)$actor['id']);
            Audit::record((int)$actor['id'], 'flight.created_manual', 'flight', $flightId, $payload);
            DB::commit();
            return $flightId;
        } catch (Throwable $error) {
            DB::rollback();
            throw $error;
        }
    }

    public static function changeStatusByAdmin(array $actor, int $flightId, string $targetStatus): void
    {
        if (!UserService::isAdmin((int)$actor['id'])) throw new RuntimeException('Bu uçuş durumu değişikliğini yalnızca admin yapabilir.');
        $flight = self::find($flightId);
        if (!$flight) throw new RuntimeException('Uçuş bulunamadı.');
        $currentStatus = (string)$flight['status'];
        $allowedTargets = $currentStatus === 'completed'
            ? ['scheduled', 'active', 'cancelled']
            : ($currentStatus === 'active' ? ['scheduled'] : []);
        if (!in_array($targetStatus, $allowedTargets, true)) {
            throw new RuntimeException('Bu uçuş için istenen durum geçişine izin verilmiyor.');
        }

        DB::begin();
        try {
            $changed = DB::execute(
                'UPDATE flights SET status = ?, updated_by = ? WHERE id = ? AND status = ?',
                [$targetStatus, (int)$actor['id'], $flightId, $currentStatus]
            );
            if ($changed !== 1) throw new RuntimeException('Uçuş başka bir işlem tarafından değiştirilmiş.');
            if ($currentStatus === 'completed' && in_array($targetStatus, ['scheduled', 'active'], true)) {
                $assignment = DB::fetch('SELECT id FROM flight_assignments WHERE flight_id = ? AND status = "completed" ORDER BY id DESC LIMIT 1', [$flightId]);
                if ($assignment) DB::execute('UPDATE flight_assignments SET status = "active", unassigned_at = NULL WHERE id = ?', [(int)$assignment['id']]);
            }
            if ($currentStatus === 'active' && $targetStatus === 'scheduled') {
                DB::execute('UPDATE flight_assignments SET status = "revoked", unassigned_at = NOW() WHERE flight_id = ? AND status = "active"', [$flightId]);
            }
            Audit::record((int)$actor['id'], 'flight.status_changed_by_admin', 'flight', $flightId, ['from' => $currentStatus, 'to' => $targetStatus]);
            DB::commit();
        } catch (Throwable $error) { DB::rollback(); throw $error; }
    }

    public static function assignments(int $flightId): array
    {
        return DB::fetchAll('SELECT user_id FROM flight_assignments WHERE flight_id = ? AND status = "active" ORDER BY assigned_at DESC, id DESC LIMIT 1', [$flightId]);
    }

    public static function isAssignedTo(int $flightId, int $userId): bool
    {
        return (bool)DB::fetch(
            'SELECT 1 FROM flight_assignments
             WHERE flight_id = ? AND user_id = ? AND status = "active"
               AND id = (SELECT MAX(latest.id) FROM flight_assignments latest WHERE latest.flight_id = ? AND latest.status = "active")
             LIMIT 1',
            [$flightId, $userId, $flightId]
        );
    }

    public static function assign(array $actor, int $flightId, int $userId): void
    {
        $flight = self::find($flightId);
        if (!$flight) throw new RuntimeException('Uçuş bulunamadı.');
        Authorization::require($actor, 'flights.assign', self::context($flight));
        if (in_array($flight['status'], ['completed', 'cancelled'], true)) throw new RuntimeException('Tamamlanmış veya iptal edilmiş uçuş yeniden atanamaz.');
        if ($userId > 0 && !DB::fetch('SELECT id FROM users WHERE id = ? AND status = "active" AND deleted_at IS NULL', [$userId])) {
            throw new RuntimeException('Atanacak aktif kullanıcı bulunamadı.');
        }
        DB::begin();
        try {
            DB::execute('DELETE FROM flight_assignments WHERE flight_id = ?', [$flightId]);
            if ($userId > 0) {
                DB::execute(
                    'INSERT INTO flight_assignments (flight_id, user_id, assignment_role, status, assigned_by, assigned_at, unassigned_at)
                     VALUES (?, ?, "primary", "active", ?, NOW(), NULL)',
                    [$flightId, $userId, (int)$actor['id']]
                );
            }
            Audit::record((int)$actor['id'], 'flight.assigned', 'flight', $flightId, ['user_id' => $userId ?: null]);
            DB::commit();
        } catch (Throwable $error) { DB::rollback(); throw $error; }
    }

    public static function delete(array $actor, int $flightId): void
    {
        $flight = self::find($flightId);
        if (!$flight) throw new RuntimeException('Uçuş bulunamadı.');
        Authorization::require($actor, 'flights.delete', self::context($flight));
        DB::begin();
        try {
            DB::execute('DELETE FROM flights WHERE id = ?', [$flightId]);
            Audit::record((int)$actor['id'], 'flight.deleted', 'flight', $flightId, [
                'icao' => $flight['icao_code'],
                'arrival' => $flight['arrival_flight_number'],
                'departure' => $flight['departure_flight_number'],
            ]);
            DB::commit();
        } catch (Throwable $error) {
            DB::rollback();
            throw $error;
        }
    }

    public static function start(array $actor, int $flightId): void
    {
        $flight = self::find($flightId);
        if (!$flight) throw new RuntimeException('Başlatılacak uçuş bulunamadı.');
        Authorization::require($actor, 'flights.complete', self::context($flight));
        if (!self::isAssignedTo($flightId, (int)$actor['id'])) throw new RuntimeException('Bu uçuş size atanmamış.');
        if ($flight['status'] !== 'scheduled') throw new RuntimeException('Yalnızca planlanan uçuş başlatılabilir.');
        DB::begin();
        try {
            $changed = DB::execute('UPDATE flights SET status = "active", updated_by = ? WHERE id = ? AND status = "scheduled"', [(int)$actor['id'], $flightId]);
            if ($changed !== 1) throw new RuntimeException('Uçuş başka bir işlem tarafından başlatılmış veya değiştirilmiş.');
            Audit::record((int)$actor['id'], 'flight.started', 'flight', $flightId);
            DB::commit();
        } catch (Throwable $error) { DB::rollback(); throw $error; }
    }

    public static function complete(array $actor, int $flightId): void
    {
        $flight = self::find($flightId);
        if (!$flight) throw new RuntimeException('Tamamlanacak uçuş bulunamadı.');
        Authorization::require($actor, 'flights.complete', self::context($flight));
        if (!self::isAssignedTo($flightId, (int)$actor['id'])) throw new RuntimeException('Bu uçuş size atanmamış.');
        if ($flight['status'] !== 'active') throw new RuntimeException('Uçuş tamamlanmadan önce operasyon başlatılmalıdır.');
        DB::begin();
        try {
            $changed = DB::execute('UPDATE flights SET status = "completed", updated_by = ? WHERE id = ? AND status = "active"', [(int)$actor['id'], $flightId]);
            if ($changed !== 1) throw new RuntimeException('Uçuş başka bir işlem tarafından tamamlanmış veya değiştirilmiş.');
            DB::execute('UPDATE flight_assignments SET status = "completed" WHERE flight_id = ? AND user_id = ? AND status = "active"', [$flightId, (int)$actor['id']]);
            Audit::record((int)$actor['id'], 'flight.completed', 'flight', $flightId);
            DB::commit();
        } catch (Throwable $error) { DB::rollback(); throw $error; }
    }

    public static function context(array $flight): array
    {
        return ['flight_id' => (int)$flight['id'], 'airline_id' => (int)$flight['airline_id']];
    }

    public static function normalize(array $data): array
    {
        return [
            'airline_id' => (int)($data['airline_id'] ?? 0), 'flight_type_id' => (int)($data['flight_type_id'] ?? 0),
            'arrival_flight_number' => nullable_string($data['arrival_flight_number'] ?? null), 'departure_flight_number' => nullable_string($data['departure_flight_number'] ?? null),
            'arrival_origin' => nullable_string(strtoupper((string)($data['arrival_origin'] ?? ''))), 'arrival_destination' => nullable_string(strtoupper((string)($data['arrival_destination'] ?? ''))),
            'departure_origin' => nullable_string(strtoupper((string)($data['departure_origin'] ?? ''))), 'departure_destination' => nullable_string(strtoupper((string)($data['departure_destination'] ?? ''))),
            'scheduled_arrival_at' => datetime_input($data['scheduled_arrival_at'] ?? ''), 'estimated_arrival_at' => datetime_input($data['estimated_arrival_at'] ?? ''), 'actual_arrival_at' => datetime_input($data['actual_arrival_at'] ?? ''),
            'scheduled_departure_at' => datetime_input($data['scheduled_departure_at'] ?? ''), 'estimated_departure_at' => datetime_input($data['estimated_departure_at'] ?? ''), 'actual_departure_at' => datetime_input($data['actual_departure_at'] ?? ''),
            'tail_number' => nullable_string(strtoupper((string)($data['tail_number'] ?? ''))), 'aircraft_type' => nullable_string(strtoupper((string)($data['aircraft_type'] ?? ''))),
            'stand' => nullable_string(strtoupper((string)($data['stand'] ?? ''))), 'status' => (string)($data['status'] ?? 'scheduled'), 'note' => nullable_string($data['note'] ?? null),
            'source' => (string)($data['source'] ?? 'manual'), 'source_key' => nullable_string($data['source_key'] ?? null),
        ];
    }

    public static function validate(array $data, bool $checkReferences = true): array
    {
        $errors = [];
        if ((int)$data['airline_id'] <= 0 || ($checkReferences && !DB::fetch('SELECT id FROM airlines WHERE id = ? AND status = "active"', [(int)$data['airline_id']]))) $errors[] = 'Geçerli havayolu seçilmelidir.';
        if ((int)$data['flight_type_id'] <= 0 || ($checkReferences && !DB::fetch('SELECT id FROM flight_types WHERE id = ? AND status = "active"', [(int)$data['flight_type_id']]))) $errors[] = 'Geçerli uçuş tipi seçilmelidir.';
        if (!$data['arrival_flight_number'] && !$data['departure_flight_number']) $errors[] = 'En az bir uçuş numarası gerekir.';
        if (!$data['scheduled_arrival_at'] && !$data['scheduled_departure_at']) $errors[] = 'En az bir planlanan zaman gerekir.';
        if (!in_array($data['status'], ['scheduled', 'active', 'completed', 'cancelled'], true)) $errors[] = 'Uçuş durumu geçersiz.';
        return $errors;
    }

    public static function create(array $p, int $actorId): int
    {
        return DB::insert(
            'INSERT INTO flights (airline_id, flight_type_id, arrival_flight_number, departure_flight_number, arrival_origin, arrival_destination, departure_origin, departure_destination,
             scheduled_arrival_at, estimated_arrival_at, actual_arrival_at, scheduled_departure_at, estimated_departure_at, actual_departure_at,
             tail_number, aircraft_type, stand, status, note, source, source_key, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [(int)$p['airline_id'], (int)$p['flight_type_id'], $p['arrival_flight_number'], $p['departure_flight_number'], $p['arrival_origin'], $p['arrival_destination'], $p['departure_origin'], $p['departure_destination'],
             $p['scheduled_arrival_at'], $p['estimated_arrival_at'], $p['actual_arrival_at'], $p['scheduled_departure_at'], $p['estimated_departure_at'], $p['actual_departure_at'],
             $p['tail_number'], $p['aircraft_type'], $p['stand'], $p['status'], $p['note'], $p['source'], $p['source_key'], $actorId, $actorId]
        );
    }

    private static function update(int $flightId, array $p, int $actorId): void
    {
        DB::execute(
            'UPDATE flights SET airline_id = ?, flight_type_id = ?, arrival_flight_number = ?, departure_flight_number = ?, arrival_origin = ?, arrival_destination = ?, departure_origin = ?, departure_destination = ?,
             scheduled_arrival_at = ?, estimated_arrival_at = ?, actual_arrival_at = ?, scheduled_departure_at = ?, estimated_departure_at = ?, actual_departure_at = ?,
             tail_number = ?, aircraft_type = ?, stand = ?, status = ?, note = ?, updated_by = ? WHERE id = ? AND deleted_at IS NULL',
            [(int)$p['airline_id'], (int)$p['flight_type_id'], $p['arrival_flight_number'], $p['departure_flight_number'], $p['arrival_origin'], $p['arrival_destination'], $p['departure_origin'], $p['departure_destination'],
             $p['scheduled_arrival_at'], $p['estimated_arrival_at'], $p['actual_arrival_at'], $p['scheduled_departure_at'], $p['estimated_departure_at'], $p['actual_departure_at'],
             $p['tail_number'], $p['aircraft_type'], $p['stand'], $p['status'], $p['note'], $actorId, $flightId]
        );
    }
}

final class ProcessService
{
    public static function save(array $actor, array $data): void
    {
        $flightId = (int)($data['flight_id'] ?? 0);
        $processTypeId = (int)($data['process_type_id'] ?? 0);
        $action = (string)($data['process_action'] ?? '');
        $flight = FlightService::find($flightId);
        if (!$flight) throw new RuntimeException('Uçuş bulunamadı.');
        Authorization::require($actor, 'processes.update', FlightService::context($flight));
        if ($flight['status'] !== 'active') throw new RuntimeException('Önce uçuş operasyonunu başlatın.');
        if (!FlightService::isAssignedTo($flightId, (int)$actor['id'])) throw new RuntimeException('Süreçleri yalnızca uçuşun atanmış kullanıcısı değiştirebilir.');
        $mapped = DB::fetch('SELECT pt.input_type FROM flight_type_process_map m JOIN process_types pt ON pt.id = m.process_type_id WHERE m.flight_type_id = ? AND m.process_type_id = ?', [(int)$flight['flight_type_id'], $processTypeId]);
        if (!$mapped) throw new RuntimeException('Süreç bu uçuş tipine ait değil.');
        $allowedActions = [
            'state' => ['start', 'finish', 'not_used', 'undo'],
            'datetime' => ['mark_time', 'undo'],
            'text' => ['save_text', 'undo'],
        ];
        if (!in_array($action, $allowedActions[$mapped['input_type']] ?? [], true)) throw new RuntimeException('Süreç veri tipiyle işlem uyuşmuyor.');
        $current = DB::fetch('SELECT * FROM flight_processes WHERE flight_id = ? AND process_type_id = ?', [$flightId, $processTypeId]);
        $currentState = (string)($current['state'] ?? 'not_started');
        $alreadyRecorded = $current && (
            $current['state'] === 'finished'
            || $current['value_datetime'] !== null
            || trim((string)$current['value_text']) !== ''
        );
        $state = 'not_started'; $started = null; $finished = null; $valueDate = null; $valueText = null;
        if ($action === 'start') {
            if ($currentState !== 'not_started') throw new RuntimeException('Bu süreç zaten başlatılmış veya sonuçlandırılmış.');
            $state = 'started'; $started = date('Y-m-d H:i:s');
        }
        elseif ($action === 'finish') {
            if ($currentState !== 'started') throw new RuntimeException('Süreci bitirmeden önce başlatmalısınız.');
            $state = 'finished'; $started = $current['started_at'] ?? date('Y-m-d H:i:s'); $finished = date('Y-m-d H:i:s');
        }
        elseif ($action === 'not_used') {
            if ($currentState !== 'not_started') throw new RuntimeException('Yalnızca başlamamış süreç kullanılmadı olarak işaretlenebilir.');
            $state = 'not_used';
        }
        elseif ($action === 'undo') {
            $hasUndoableValue = $current && (
                $currentState !== 'not_started'
                || $current['value_datetime'] !== null
                || trim((string)$current['value_text']) !== ''
            );
            if (!$hasUndoableValue) throw new RuntimeException('Geri alınabilecek bir süreç işlemi bulunamadı.');
            if ($mapped['input_type'] === 'state' && $currentState === 'finished') {
                $state = 'started';
                $started = $current['started_at'] ?? date('Y-m-d H:i:s');
            }
        }
        elseif ($action === 'mark_time') {
            if ($alreadyRecorded || $currentState !== 'not_started') throw new RuntimeException('Tamamlanan süreç önce geri alınmalıdır.');
            $state = 'finished'; $valueDate = datetime_input($data['value_datetime'] ?? '') ?: date('Y-m-d H:i:s');
        }
        elseif ($action === 'save_text') {
            if ($alreadyRecorded || $currentState !== 'not_started') throw new RuntimeException('Tamamlanan süreç önce geri alınmalıdır.');
            $valueText = trim((string)($data['value_text'] ?? '')); $state = $valueText === '' ? 'not_started' : 'finished';
        }
        else throw new RuntimeException('Geçersiz süreç işlemi.');
        DB::execute(
            'INSERT INTO flight_processes (flight_id, process_type_id, state, started_at, finished_at, value_datetime, value_text, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE state = VALUES(state), started_at = VALUES(started_at), finished_at = VALUES(finished_at), value_datetime = VALUES(value_datetime), value_text = VALUES(value_text), updated_by = VALUES(updated_by)',
            [$flightId, $processTypeId, $state, $started, $finished, $valueDate, $valueText, (int)$actor['id']]
        );
        Audit::record((int)$actor['id'], 'process.' . $action, 'flight', $flightId, [
            'process_type_id' => $processTypeId,
            'state' => $state,
            'value_datetime' => $valueDate,
            'value_text' => $valueText,
        ]);
    }
}

final class TimelineService
{
    private const MIN_DURATION = 5;
    private const MAX_DURATION = 720;

    public static function normalizeDate(string $value): string
    {
        $value = trim($value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value ? $value : date('Y-m-d');
    }

    public static function settings(): array
    {
        $settings = DB::fetch('SELECT default_arrival_minutes, default_departure_minutes FROM flight_timeline_settings WHERE id = 1');
        return [
            'default_arrival_minutes' => (int)($settings['default_arrival_minutes'] ?? 40),
            'default_departure_minutes' => (int)($settings['default_departure_minutes'] ?? 60),
        ];
    }

    public static function saveDefaults(array $actor, array $data): void
    {
        self::requireManager($actor);
        $arrival = self::duration($data['default_arrival_minutes'] ?? 40);
        $departure = self::duration($data['default_departure_minutes'] ?? 60);
        DB::execute(
            'INSERT INTO flight_timeline_settings (id, default_arrival_minutes, default_departure_minutes, updated_by)
             VALUES (1, ?, ?, ?)
             ON DUPLICATE KEY UPDATE default_arrival_minutes = VALUES(default_arrival_minutes), default_departure_minutes = VALUES(default_departure_minutes), updated_by = VALUES(updated_by)',
            [$arrival, $departure, (int)$actor['id']]
        );
        Audit::record((int)$actor['id'], 'timeline.defaults_updated', 'flight_timeline_settings', 1, [
            'arrival_minutes' => $arrival,
            'departure_minutes' => $departure,
        ]);
    }

    public static function ruleRows(array $actor, int $airlineId): array
    {
        self::requireManager($actor);
        if (!DB::fetch('SELECT id FROM airlines WHERE id = ?', [$airlineId])) throw new RuntimeException('Havayolu bulunamadı.');
        $settings = self::settings();
        $types = DB::fetchAll(
            'SELECT aircraft_type FROM flights WHERE airline_id = ? AND deleted_at IS NULL AND aircraft_type IS NOT NULL AND TRIM(aircraft_type) != ""
             UNION
             SELECT aircraft_type FROM flight_timeline_rules WHERE airline_id = ?
             ORDER BY aircraft_type',
            [$airlineId, $airlineId]
        );
        $rules = DB::fetchAll('SELECT id, aircraft_type, arrival_minutes, departure_minutes FROM flight_timeline_rules WHERE airline_id = ?', [$airlineId]);
        $rulesByType = [];
        foreach ($rules as $rule) $rulesByType[strtoupper(trim((string)$rule['aircraft_type']))] = $rule;
        $rows = [];
        foreach ($types as $typeRow) {
            $aircraftType = strtoupper(trim((string)$typeRow['aircraft_type']));
            if ($aircraftType === '') continue;
            $rule = $rulesByType[$aircraftType] ?? null;
            $rows[] = [
                'id' => (int)($rule['id'] ?? 0),
                'aircraft_type' => $aircraftType,
                'arrival_minutes' => (int)($rule['arrival_minutes'] ?? $settings['default_arrival_minutes']),
                'departure_minutes' => (int)($rule['departure_minutes'] ?? $settings['default_departure_minutes']),
                'has_rule' => $rule !== null,
            ];
        }
        return $rows;
    }

    public static function saveRule(array $actor, array $data): void
    {
        self::requireManager($actor);
        $airlineId = (int)($data['airline_id'] ?? 0);
        if (!DB::fetch('SELECT id FROM airlines WHERE id = ? AND status = "active"', [$airlineId])) throw new RuntimeException('Aktif havayolu bulunamadı.');
        $aircraftType = strtoupper(trim((string)($data['aircraft_type'] ?? '')));
        if ($aircraftType === '' || strlen($aircraftType) > 20) throw new RuntimeException('Uçak tipi 1-20 karakter olmalıdır.');
        $arrival = self::duration($data['arrival_minutes'] ?? 40);
        $departure = self::duration($data['departure_minutes'] ?? 60);
        DB::execute(
            'INSERT INTO flight_timeline_rules (airline_id, aircraft_type, arrival_minutes, departure_minutes, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE arrival_minutes = VALUES(arrival_minutes), departure_minutes = VALUES(departure_minutes), updated_by = VALUES(updated_by)',
            [$airlineId, $aircraftType, $arrival, $departure, (int)$actor['id'], (int)$actor['id']]
        );
        $rule = DB::fetch('SELECT id FROM flight_timeline_rules WHERE airline_id = ? AND aircraft_type = ?', [$airlineId, $aircraftType]);
        Audit::record((int)$actor['id'], 'timeline.rule_saved', 'flight_timeline_rule', (int)($rule['id'] ?? 0), [
            'airline_id' => $airlineId,
            'aircraft_type' => $aircraftType,
            'arrival_minutes' => $arrival,
            'departure_minutes' => $departure,
        ]);
    }

    public static function deleteRule(array $actor, int $ruleId): void
    {
        self::requireManager($actor);
        $rule = DB::fetch('SELECT * FROM flight_timeline_rules WHERE id = ?', [$ruleId]);
        if (!$rule) throw new RuntimeException('Silinecek süre kuralı bulunamadı.');
        DB::execute('DELETE FROM flight_timeline_rules WHERE id = ?', [$ruleId]);
        Audit::record((int)$actor['id'], 'timeline.rule_deleted', 'flight_timeline_rule', $ruleId, [
            'airline_id' => (int)$rule['airline_id'],
            'aircraft_type' => $rule['aircraft_type'],
        ]);
    }

    public static function data(array $actor, string $requestedDate): array
    {
        Authorization::require($actor, 'timeline.view');
        $date = self::normalizeDate($requestedDate);
        $dayStart = new DateTimeImmutable($date . ' 00:00:00');
        $dayEnd = $dayStart->modify('+1 day');
        $searchStart = $dayStart->modify('-1 day')->format('Y-m-d H:i:s');
        $searchEnd = $dayEnd->modify('+1 day')->format('Y-m-d H:i:s');
        $settings = self::settings();
        $ruleRows = DB::fetchAll('SELECT airline_id, aircraft_type, arrival_minutes, departure_minutes FROM flight_timeline_rules');
        $rules = [];
        foreach ($ruleRows as $rule) {
            $key = (int)$rule['airline_id'] . '|' . strtoupper(trim((string)$rule['aircraft_type']));
            $rules[$key] = [(int)$rule['arrival_minutes'], (int)$rule['departure_minutes']];
        }

        $candidates = DB::fetchAll(
            'SELECT f.*, a.name AS airline_name, a.icao_code, ft.code AS flight_type_code, ft.name AS flight_type_name,
                    (SELECT CONCAT(u.first_name, " ", u.last_name)
                     FROM flight_assignments fa JOIN users u ON u.id = fa.user_id
                     WHERE fa.flight_id = f.id AND fa.status IN ("active", "completed")
                     ORDER BY fa.id DESC LIMIT 1) AS assignee_name
             FROM flights f
             JOIN airlines a ON a.id = f.airline_id
             JOIN flight_types ft ON ft.id = f.flight_type_id
             WHERE f.deleted_at IS NULL AND (
                (COALESCE(f.estimated_arrival_at, f.scheduled_arrival_at) >= ? AND COALESCE(f.estimated_arrival_at, f.scheduled_arrival_at) < ?)
                OR (COALESCE(f.estimated_departure_at, f.scheduled_departure_at) >= ? AND COALESCE(f.estimated_departure_at, f.scheduled_departure_at) < ?)
             )',
            [$searchStart, $searchEnd, $searchStart, $searchEnd]
        );

        $flights = [];
        $missing = [];
        foreach ($candidates as $flight) {
            $context = FlightService::context($flight);
            if (!can($actor, 'timeline.view', $context)) continue;
            $aircraftType = strtoupper(trim((string)($flight['aircraft_type'] ?? '')));
            [$arrivalMinutes, $departureMinutes] = $rules[(int)$flight['airline_id'] . '|' . $aircraftType]
                ?? [$settings['default_arrival_minutes'], $settings['default_departure_minutes']];
            $arrivalAt = self::dateTime($flight['estimated_arrival_at'] ?: $flight['scheduled_arrival_at']);
            $departureAt = self::dateTime($flight['estimated_departure_at'] ?: $flight['scheduled_departure_at']);
            [$startAt, $endAt] = self::window((string)$flight['flight_type_code'], $arrivalAt, $departureAt, $arrivalMinutes, $departureMinutes);
            $item = self::flightItem($flight, $arrivalMinutes, $departureMinutes);
            if (!$startAt || !$endAt) {
                $anchor = $arrivalAt ?: $departureAt;
                if ($anchor && $anchor->format('Y-m-d') === $date) {
                    $item['missing_reason'] = 'Uçuş tipi için gerekli ETA/STA veya ETD/STD bilgisi eksik.';
                    $missing[] = $item;
                }
                continue;
            }
            if ($startAt >= $dayEnd || $endAt <= $dayStart) continue;
            $clippedStart = $startAt < $dayStart ? $dayStart : $startAt;
            $clippedEnd = $endAt > $dayEnd ? $dayEnd : $endAt;
            $item['start_at'] = $startAt->format(DATE_ATOM);
            $item['end_at'] = $endAt->format(DATE_ATOM);
            $item['start_label'] = $startAt->format('H:i');
            $item['end_label'] = $endAt->format('H:i');
            $item['start_minute'] = ($clippedStart->getTimestamp() - $dayStart->getTimestamp()) / 60;
            $item['duration_minutes'] = max(1, ($clippedEnd->getTimestamp() - $clippedStart->getTimestamp()) / 60);
            $item['continues_before'] = $startAt < $dayStart;
            $item['continues_after'] = $endAt > $dayEnd;
            $item['sort_timestamp'] = $startAt->getTimestamp();
            $flights[] = $item;
        }

        $allItems = array_merge($flights, $missing);
        $processesByFlight = self::processes(array_map(static fn(array $flight): int => (int)$flight['id'], $allItems));
        foreach ($flights as &$flight) $flight['processes'] = $processesByFlight[(int)$flight['id']] ?? [];
        unset($flight);
        foreach ($missing as &$flight) $flight['processes'] = $processesByFlight[(int)$flight['id']] ?? [];
        unset($flight);

        usort($flights, static function (array $left, array $right): int {
            $icaoOrder = strcmp((string)$left['icao_code'], (string)$right['icao_code']);
            if ($icaoOrder !== 0) return $icaoOrder;
            $timeOrder = (int)$left['sort_timestamp'] <=> (int)$right['sort_timestamp'];
            return $timeOrder !== 0 ? $timeOrder : (int)$left['id'] <=> (int)$right['id'];
        });
        usort($missing, static function (array $left, array $right): int {
            $icaoOrder = strcmp((string)$left['icao_code'], (string)$right['icao_code']);
            return $icaoOrder !== 0 ? $icaoOrder : (int)$left['id'] <=> (int)$right['id'];
        });
        $groups = [];
        foreach ($flights as $flight) {
            $icao = (string)$flight['icao_code'];
            if (!isset($groups[$icao])) $groups[$icao] = ['icao_code' => $icao, 'airline_name' => $flight['airline_name'], 'flights' => []];
            unset($flight['sort_timestamp']);
            $groups[$icao]['flights'][] = $flight;
        }

        $today = date('Y-m-d');
        $nowMinute = null;
        if ($date === $today) {
            $now = new DateTimeImmutable();
            $nowMinute = ($now->getTimestamp() - $dayStart->getTimestamp()) / 60;
        }
        return [
            'date' => $date,
            'generated_at' => date(DATE_ATOM),
            'now_minute' => $nowMinute,
            'groups' => array_values($groups),
            'missing' => $missing,
            'totals' => ['flights' => count($flights), 'missing' => count($missing)],
        ];
    }

    private static function duration(mixed $value): int
    {
        $minutes = (int)$value;
        if ($minutes < self::MIN_DURATION || $minutes > self::MAX_DURATION) {
            throw new RuntimeException('Görev süresi 5 ile 720 dakika arasında olmalıdır.');
        }
        return $minutes;
    }

    private static function requireManager(array $actor): void
    {
        Authorization::require($actor, 'timeline.manage');
        if (!UserService::isAdmin((int)$actor['id'])) throw new RuntimeException('Süre kurallarını yalnızca admin yönetebilir.');
    }

    private static function dateTime(mixed $value): ?DateTimeImmutable
    {
        if (!$value) return null;
        try { return new DateTimeImmutable((string)$value); }
        catch (Throwable) { return null; }
    }

    private static function window(string $flightType, ?DateTimeImmutable $arrivalAt, ?DateTimeImmutable $departureAt, int $arrivalMinutes, int $departureMinutes): array
    {
        if ($flightType === 'arrival' && $arrivalAt) return [$arrivalAt, $arrivalAt->modify('+' . $arrivalMinutes . ' minutes')];
        if ($flightType === 'departure' && $departureAt) return [$departureAt->modify('-' . $departureMinutes . ' minutes'), $departureAt];
        if ($flightType === 'turnaround' && $arrivalAt && $departureAt) {
            $departureStart = $departureAt->modify('-' . $departureMinutes . ' minutes');
            $arrivalEnd = $arrivalAt->modify('+' . $arrivalMinutes . ' minutes');
            return [$arrivalAt < $departureStart ? $arrivalAt : $departureStart, $arrivalEnd > $departureAt ? $arrivalEnd : $departureAt];
        }
        return [null, null];
    }

    private static function flightItem(array $flight, int $arrivalMinutes, int $departureMinutes): array
    {
        return [
            'id' => (int)$flight['id'],
            'airline_id' => (int)$flight['airline_id'],
            'airline_name' => (string)$flight['airline_name'],
            'icao_code' => (string)$flight['icao_code'],
            'flight_type_code' => (string)$flight['flight_type_code'],
            'flight_type_name' => (string)$flight['flight_type_name'],
            'arrival_flight_number' => $flight['arrival_flight_number'],
            'departure_flight_number' => $flight['departure_flight_number'],
            'arrival_origin' => $flight['arrival_origin'],
            'departure_destination' => $flight['departure_destination'],
            'tail_number' => $flight['tail_number'],
            'aircraft_type' => $flight['aircraft_type'],
            'stand' => $flight['stand'],
            'status' => (string)$flight['status'],
            'assignee_name' => $flight['assignee_name'],
            'scheduled_arrival_at' => $flight['scheduled_arrival_at'],
            'estimated_arrival_at' => $flight['estimated_arrival_at'],
            'scheduled_departure_at' => $flight['scheduled_departure_at'],
            'estimated_departure_at' => $flight['estimated_departure_at'],
            'arrival_minutes' => $arrivalMinutes,
            'departure_minutes' => $departureMinutes,
        ];
    }

    private static function processes(array $flightIds): array
    {
        $flightIds = array_values(array_unique(array_filter(array_map('intval', $flightIds))));
        if (!$flightIds) return [];
        $placeholders = implode(', ', array_fill(0, count($flightIds), '?'));
        $rows = DB::fetchAll(
            'SELECT f.id AS flight_id, pt.code, pt.name, pt.icon, pt.input_type, m.order_no,
                    COALESCE(fp.state, "not_started") AS state, fp.started_at, fp.finished_at, fp.value_datetime, fp.updated_at AS recorded_at,
                    CASE WHEN fp.value_text IS NOT NULL AND TRIM(fp.value_text) != "" THEN 1 ELSE 0 END AS has_text
             FROM flights f
             JOIN flight_type_process_map m ON m.flight_type_id = f.flight_type_id
             JOIN process_types pt ON pt.id = m.process_type_id AND pt.status = "active"
             LEFT JOIN flight_processes fp ON fp.flight_id = f.id AND fp.process_type_id = pt.id
             WHERE f.id IN (' . $placeholders . ')
             ORDER BY f.id, m.order_no, pt.id',
            $flightIds
        );
        $allowedIcons = ['inblock', 'door-open', 'deboarding', 'cleaning', 'catering', 'fueling', 'boarding', 'door-closed', 'offblock', 'note'];
        $result = [];
        foreach ($rows as $row) {
            $icon = (string)($row['icon'] ?: self::fallbackIcon((string)$row['code']));
            if (!in_array($icon, $allowedIcons, true)) $icon = 'note';
            $result[(int)$row['flight_id']][] = [
                'code' => (string)$row['code'],
                'name' => (string)$row['name'],
                'icon' => $icon,
                'state' => (string)$row['state'],
                'started_at' => $row['started_at'],
                'finished_at' => $row['finished_at'],
                'value_datetime' => $row['value_datetime'],
                'recorded_at' => $row['recorded_at'],
                'has_text' => (bool)$row['has_text'],
            ];
        }
        return $result;
    }

    private static function fallbackIcon(string $code): string
    {
        return [
            'inblock' => 'inblock', 'doors_open' => 'door-open', 'deboarding' => 'deboarding',
            'cleaning' => 'cleaning', 'catering' => 'catering', 'fueling' => 'fueling',
            'boarding' => 'boarding', 'doors_closed' => 'door-closed', 'offblock' => 'offblock',
            'operation_note' => 'note',
        ][$code] ?? 'note';
    }
}

final class ImportService
{
    private const MAX_ROWS = 2000;
    private const STATION_CODE = 'AYT';
    private static ?array $airlinesByIcao = null;
    private static ?array $flightTypesByCode = null;

    public static function cleanupTransient(): void
    {
        DB::execute(
            'DELETE FROM flight_import_batches
             WHERE status IN ("completed", "completed_with_errors", "failed")
                OR (status = "preview" AND created_at < DATE_SUB(NOW(), INTERVAL 2 HOUR))'
        );
    }

    public static function batch(int $batchId): ?array
    {
        return DB::fetch('SELECT * FROM flight_import_batches WHERE id = ?', [$batchId]);
    }

    public static function batchForActor(array $actor, int $batchId, string $permission = 'imports.view'): ?array
    {
        $batch = self::batch($batchId);
        if (!$batch) return null;
        if ((int)$batch['imported_by'] !== (int)$actor['id'] && !Authorization::isGlobal($actor, $permission)) {
            throw new RuntimeException('Bu geçici Excel önizlemesine erişim yetkiniz yok.');
        }
        return $batch;
    }

    public static function rows(int $batchId): array
    {
        $rows = DB::fetchAll('SELECT * FROM flight_import_rows WHERE batch_id = ? ORDER BY source_row_number', [$batchId]);
        foreach ($rows as &$row) $row['data'] = json_decode((string)$row['payload'], true) ?: [];
        unset($row);
        return $rows;
    }

    public static function invalidCount(int $batchId): int
    {
        $row = DB::fetch('SELECT COUNT(*) AS total FROM flight_import_rows WHERE batch_id = ? AND status = "invalid"', [$batchId]);
        return (int)($row['total'] ?? 0);
    }

    public static function stage(array $actor, array $file): int
    {
        Authorization::require($actor, 'imports.stage');
        self::cleanupTransient();
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name'])) throw new RuntimeException('Excel dosyası yüklenemedi.');
        if ((int)($file['size'] ?? 0) > 10 * 1024 * 1024) throw new RuntimeException('Dosya 10 MB sınırını aşıyor.');
        $name = basename((string)($file['name'] ?? 'import.xlsx'));
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($extension, ['xlsx', 'csv'], true)) throw new RuntimeException('Yalnızca XLSX veya CSV dosyası yükleyin.');
        $rawRows = $extension === 'xlsx' ? self::readXlsx((string)$file['tmp_name']) : self::readCsv((string)$file['tmp_name']);
        $headerIndex = self::findFlightHeaderRow($rawRows);
        if ($headerIndex < 0) throw new RuntimeException('İlk sayfada A/C, GELİŞ, GİDİŞ ve PP başlıkları bulunamadı.');
        $flightDate = self::flightDateFromMatrix($rawRows, $name);
        $importRows = [];
        for ($rowIndex = $headerIndex + 1, $rowCount = count($rawRows); $rowIndex < $rowCount; $rowIndex++) {
            $rawRow = $rawRows[$rowIndex] ?? [];
            $arrivalNo = self::normalizeFlightNumber($rawRow[1] ?? '');
            $departureNo = self::normalizeFlightNumber($rawRow[2] ?? '');
            if ($arrivalNo === null && $departureNo === null) continue;
            $arrivalAt = $arrivalNo !== null ? self::combineFlightDateAndTime($flightDate, $rawRow[8] ?? '') : null;
            $departureAt = $departureNo !== null ? self::combineFlightDateAndTime($flightDate, $rawRow[9] ?? '') : null;
            $importRows[] = [
                'source_row_number' => $rowIndex + 1,
                'source' => [
                    'airline_icao' => self::normalizeAirlineCode($rawRow[0] ?? ''),
                    'arrival_flight_number' => $arrivalNo,
                    'departure_flight_number' => $departureNo,
                    'arrival_origin' => strtoupper(trim((string)($rawRow[7] ?? ''))),
                    'arrival_destination' => $arrivalNo !== null ? self::STATION_CODE : '',
                    'departure_origin' => $departureNo !== null ? self::STATION_CODE : '',
                    'departure_destination' => strtoupper(trim((string)($rawRow[10] ?? ''))),
                    'scheduled_arrival_at' => $arrivalAt,
                    'scheduled_departure_at' => $departureAt,
                    'estimated_arrival_at' => self::combineFlightDateAndTime($flightDate, $rawRow[4] ?? ''),
                    'estimated_departure_at' => self::combineFlightDateAndTime($flightDate, $rawRow[12] ?? ''),
                    'excel_eaf_at' => self::combineFlightDateAndTime($flightDate, $rawRow[5] ?? ''),
                    'arrival_g2' => strtoupper(trim((string)($rawRow[6] ?? ''))),
                    'departure_g2' => strtoupper(trim((string)($rawRow[11] ?? ''))),
                    'aircraft_type' => strtoupper(trim((string)($rawRow[13] ?? ''))),
                    'registration_arrival' => strtoupper(trim((string)($rawRow[14] ?? ''))),
                    'registration' => strtoupper(trim((string)($rawRow[15] ?? ''))),
                    'registration_departure' => strtoupper(trim((string)($rawRow[16] ?? ''))),
                    'stand' => trim((string)($rawRow[3] ?? '')),
                ],
            ];
        }
        if (!$importRows) throw new RuntimeException('İlk sayfanın A:Q kolonlarında kullanılabilir geliş veya gidiş uçuşu bulunamadı.');
        if (count($importRows) > self::MAX_ROWS) throw new RuntimeException('Tek dosyada en fazla ' . self::MAX_ROWS . ' uçuş işlenebilir.');
        $hash = hash_file('sha256', (string)$file['tmp_name']);
        if (!is_string($hash)) throw new RuntimeException('Yüklenen dosyanın özeti hesaplanamadı.');

        DB::begin();
        try {
            DB::execute('DELETE FROM flight_import_batches WHERE imported_by = ? AND status = "preview"', [(int)$actor['id']]);
            $batchId = DB::insert(
                'INSERT INTO flight_import_batches (file_name, file_hash, status, total_rows, success_rows, imported_by) VALUES (?, ?, "preview", ?, ?, ?)',
                [$name, $hash, count($importRows), 0, (int)$actor['id']]
            );
            foreach ($importRows as $importRow) {
                $source = $importRow['source'];
                [$payload, $status, $errors, $sourceKey] = self::prepareRow($source);
                DB::insert(
                    'INSERT INTO flight_import_rows (batch_id, source_row_number, status, source_key, payload, errors) VALUES (?, ?, ?, ?, ?, ?)',
                    [$batchId, (int)$importRow['source_row_number'], $status, $sourceKey, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $errors ? json_encode($errors, JSON_UNESCAPED_UNICODE) : null]
                );
            }
            self::revalidateDuplicateStatuses($batchId);
            Audit::record((int)$actor['id'], 'import.staged', 'flight_import_batch', $batchId, ['file_name' => $name, 'rows' => count($importRows)]);
            DB::commit();
            return $batchId;
        } catch (Throwable $error) { DB::rollback(); throw $error; }
    }

    public static function discard(array $actor, int $batchId): void
    {
        Authorization::require($actor, 'imports.stage');
        $batch = self::batchForActor($actor, $batchId, 'imports.stage');
        if (!$batch || $batch['status'] !== 'preview') throw new RuntimeException('Silinecek Excel önizlemesi bulunamadı.');
        DB::begin();
        try {
            DB::execute('DELETE FROM flight_import_batches WHERE id = ?', [$batchId]);
            Audit::record((int)$actor['id'], 'import.discarded', 'flight_import_batch', $batchId);
            DB::commit();
        } catch (Throwable $error) { DB::rollback(); throw $error; }
    }

    public static function updateRows(array $actor, int $batchId, array $rows): void
    {
        Authorization::require($actor, 'imports.stage');
        $batch = self::batchForActor($actor, $batchId, 'imports.stage');
        if (!$batch || $batch['status'] !== 'preview') throw new RuntimeException('Bu import artık düzenlenemez.');
        DB::begin();
        try {
            foreach ($rows as $rowId => $source) {
                $rowId = (int)$rowId;
                if (!is_array($source) || !DB::fetch('SELECT id FROM flight_import_rows WHERE id = ? AND batch_id = ?', [$rowId, $batchId])) continue;
                [$payload, $status, $errors, $sourceKey] = self::prepareRow($source);
                DB::execute(
                    'UPDATE flight_import_rows SET status = ?, source_key = ?, payload = ?, errors = ? WHERE id = ? AND batch_id = ?',
                    [$status, $sourceKey, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $errors ? json_encode($errors, JSON_UNESCAPED_UNICODE) : null, $rowId, $batchId]
                );
            }
            self::revalidateDuplicateStatuses($batchId);
            Audit::record((int)$actor['id'], 'import.preview_updated', 'flight_import_batch', $batchId);
            DB::commit();
        } catch (Throwable $error) { DB::rollback(); throw $error; }
    }

    public static function deleteRows(array $actor, int $batchId, array $rowIds): void
    {
        Authorization::require($actor, 'imports.stage');
        $batch = self::batchForActor($actor, $batchId, 'imports.stage');
        if (!$batch || $batch['status'] !== 'preview') throw new RuntimeException('Bu importtan artık satır silinemez.');
        $rowIds = array_values(array_unique(array_filter(array_map('intval', $rowIds), static fn(int $id): bool => $id > 0)));
        if (!$rowIds) throw new RuntimeException('Silinecek uçuş seçilmedi.');
        $placeholders = implode(', ', array_fill(0, count($rowIds), '?'));
        DB::begin();
        try {
            $existingRows = DB::fetchAll(
                'SELECT id FROM flight_import_rows WHERE batch_id = ? AND id IN (' . $placeholders . ')',
                array_merge([$batchId], $rowIds)
            );
            $rowIds = array_map('intval', array_column($existingRows, 'id'));
            if (!$rowIds) throw new RuntimeException('Seçilen uçuşlar bu önizlemede bulunamadı.');
            $placeholders = implode(', ', array_fill(0, count($rowIds), '?'));
            DB::execute(
                'DELETE FROM flight_import_rows WHERE batch_id = ? AND id IN (' . $placeholders . ')',
                array_merge([$batchId], $rowIds)
            );
            $remaining = DB::fetch('SELECT COUNT(*) AS total FROM flight_import_rows WHERE batch_id = ?', [$batchId]);
            DB::execute('UPDATE flight_import_batches SET total_rows = ? WHERE id = ?', [(int)($remaining['total'] ?? 0), $batchId]);
            self::revalidateDuplicateStatuses($batchId);
            Audit::record((int)$actor['id'], 'import.rows_deleted', 'flight_import_batch', $batchId, [
                'deleted_count' => count($rowIds),
            ]);
            DB::commit();
        } catch (Throwable $error) { DB::rollback(); throw $error; }
    }

    public static function commit(array $actor, int $batchId): void
    {
        Authorization::require($actor, 'imports.commit');
        $batch = self::batchForActor($actor, $batchId, 'imports.commit');
        if (!$batch || $batch['status'] !== 'preview') throw new RuntimeException('Import onay beklemiyor veya zaten işlendi.');
        self::revalidateDuplicateStatuses($batchId);
        $rows = self::rows($batchId);
        if (!$rows) throw new RuntimeException('SQL aktarımı için en az bir uçuş bırakılmalıdır.');
        $invalid = array_filter($rows, static fn(array $row): bool => $row['status'] === 'invalid');
        if ($invalid) throw new RuntimeException('Hatalı satırlar düzeltilmeden SQL importu başlatılamaz.');

        foreach ($rows as $row) {
            if ($row['status'] === 'duplicate') continue;
            $airlineId = (int)($row['data']['airline_id'] ?? 0);
            if (!can($actor, 'imports.commit', ['airline_id' => $airlineId])) {
                throw new RuntimeException('Satır ' . $row['source_row_number'] . ' için ICAO import yetkiniz yok.');
            }
        }

        DB::begin();
        $success = 0; $failed = 0;
        try {
            DB::execute('UPDATE flight_import_batches SET status = "processing" WHERE id = ?', [$batchId]);
            foreach ($rows as $row) {
                if ($row['status'] === 'duplicate') { $failed++; continue; }
                $payload = FlightService::normalize((array)$row['data']);
                $payload['source'] = 'excel';
                $payload['source_key'] = (string)$row['source_key'];
                $errors = FlightService::validate($payload);
                if ($errors) throw new RuntimeException('Satır ' . $row['source_row_number'] . ': ' . implode(' ', $errors));
                $flightId = FlightService::create($payload, (int)$actor['id']);
                DB::execute('UPDATE flight_import_rows SET status = "imported", flight_id = ? WHERE id = ?', [$flightId, (int)$row['id']]);
                $success++;
            }
            Audit::record((int)$actor['id'], 'import.committed', 'flight_import_batch', $batchId, ['success' => $success, 'skipped' => $failed]);
            DB::execute('DELETE FROM flight_import_batches WHERE id = ?', [$batchId]);
            DB::commit();
        } catch (Throwable $error) { DB::rollback(); throw $error; }
    }

    private static function prepareRow(array $source): array
    {
        $icao = self::normalizeAirlineCode($source['airline_icao'] ?? '');
        $airlineId = (int)(self::airlineMap()[$icao] ?? 0);
        $typeCode = strtolower(trim((string)($source['flight_type'] ?? '')));
        $arrivalNo = self::normalizeFlightNumber($source['arrival_flight_number'] ?? null);
        $departureNo = self::normalizeFlightNumber($source['departure_flight_number'] ?? null);
        if (!in_array($typeCode, ['arrival', 'departure', 'turnaround'], true)) $typeCode = $arrivalNo && $departureNo ? 'turnaround' : ($departureNo ? 'departure' : 'arrival');
        $flightTypeId = (int)(self::flightTypeMap()[$typeCode] ?? 0);
        $payload = FlightService::normalize([
            'airline_id' => $airlineId, 'flight_type_id' => $flightTypeId,
            'arrival_flight_number' => $arrivalNo, 'departure_flight_number' => $departureNo,
            'arrival_origin' => $source['arrival_origin'] ?? '', 'arrival_destination' => $source['arrival_destination'] ?? '',
            'departure_origin' => $source['departure_origin'] ?? '', 'departure_destination' => $source['departure_destination'] ?? '',
            'scheduled_arrival_at' => self::spreadsheetDate($source['scheduled_arrival_at'] ?? ''), 'estimated_arrival_at' => self::spreadsheetDate($source['estimated_arrival_at'] ?? ''),
            'scheduled_departure_at' => self::spreadsheetDate($source['scheduled_departure_at'] ?? ''), 'estimated_departure_at' => self::spreadsheetDate($source['estimated_departure_at'] ?? ''),
            'tail_number' => ($source['registration'] ?? '') ?: (($source['registration_arrival'] ?? '') ?: ($source['registration_departure'] ?? '')),
            'aircraft_type' => $source['aircraft_type'] ?? '', 'stand' => $source['stand'] ?? '', 'note' => $source['note'] ?? '',
            'status' => 'scheduled', 'source' => 'excel',
        ]);
        $payload['airline_icao'] = $icao;
        $payload['flight_type'] = $typeCode;
        $payload['excel_eaf_at'] = self::spreadsheetDate($source['excel_eaf_at'] ?? '');
        $payload['arrival_g2'] = nullable_string(strtoupper((string)($source['arrival_g2'] ?? '')));
        $payload['departure_g2'] = nullable_string(strtoupper((string)($source['departure_g2'] ?? '')));
        $payload['registration_arrival'] = nullable_string(strtoupper((string)($source['registration_arrival'] ?? '')));
        $payload['registration'] = nullable_string(strtoupper((string)($source['registration'] ?? '')));
        $payload['registration_departure'] = nullable_string(strtoupper((string)($source['registration_departure'] ?? '')));
        $sourceKey = hash('sha256', implode('|', [$icao, $arrivalNo, $departureNo, $payload['scheduled_arrival_at'], $payload['scheduled_departure_at']]));
        $payload['source_key'] = $sourceKey;
        $errors = FlightService::validate($payload, false);
        if ($icao === '') array_unshift($errors, 'ICAO kodu eksik.');
        elseif ($airlineId <= 0) array_unshift($errors, $icao . ' ICAO kodu sistemde kayıtlı değil.');
        $status = $errors ? 'invalid' : 'valid';
        return [$payload, $status, $errors, $sourceKey];
    }

    private static function revalidateDuplicateStatuses(int $batchId): void
    {
        DB::execute('UPDATE flight_import_rows SET status = "valid" WHERE batch_id = ? AND status != "invalid"', [$batchId]);
        $existingKeys = array_fill_keys(array_column(DB::fetchAll(
            'SELECT DISTINCT fir.source_key FROM flight_import_rows fir
             JOIN flights f ON f.source_key = fir.source_key AND f.deleted_at IS NULL
             WHERE fir.batch_id = ? AND fir.source_key IS NOT NULL',
            [$batchId]
        ), 'source_key'), true);
        $seenKeys = [];
        foreach (DB::fetchAll('SELECT id, source_key, status FROM flight_import_rows WHERE batch_id = ? ORDER BY source_row_number', [$batchId]) as $row) {
            if ($row['status'] === 'invalid') continue;
            $sourceKey = (string)$row['source_key'];
            if (isset($existingKeys[$sourceKey]) || isset($seenKeys[$sourceKey])) {
                DB::execute('UPDATE flight_import_rows SET status = "duplicate" WHERE id = ?', [(int)$row['id']]);
            } else {
                $seenKeys[$sourceKey] = true;
            }
        }
        $counts = DB::fetch(
            'SELECT SUM(status = "valid") AS valid_rows, SUM(status IN ("invalid", "duplicate")) AS problem_rows FROM flight_import_rows WHERE batch_id = ?',
            [$batchId]
        );
        DB::execute('UPDATE flight_import_batches SET success_rows = ?, failed_rows = ? WHERE id = ?', [(int)($counts['valid_rows'] ?? 0), (int)($counts['problem_rows'] ?? 0), $batchId]);
    }

    private static function airlineMap(): array
    {
        if (self::$airlinesByIcao === null) {
            self::$airlinesByIcao = array_map('intval', array_column(DB::fetchAll('SELECT id, icao_code FROM airlines WHERE status = "active"'), 'id', 'icao_code'));
        }
        return self::$airlinesByIcao;
    }

    private static function flightTypeMap(): array
    {
        if (self::$flightTypesByCode === null) {
            self::$flightTypesByCode = array_map('intval', array_column(DB::fetchAll('SELECT id, code FROM flight_types WHERE status = "active"'), 'id', 'code'));
        }
        return self::$flightTypesByCode;
    }

    private static function findFlightHeaderRow(array $matrix): int
    {
        foreach ($matrix as $index => $row) {
            if (
                self::normalizeFlightHeader($row[0] ?? '') === 'AC'
                && self::normalizeFlightHeader($row[1] ?? '') === 'GELIS'
                && self::normalizeFlightHeader($row[2] ?? '') === 'GIDIS'
                && self::normalizeFlightHeader($row[3] ?? '') === 'PP'
            ) return (int)$index;
        }
        return -1;
    }

    private static function normalizeFlightHeader(mixed $value): string
    {
        $text = strtr(trim((string)$value), [
            'Ç'=>'C', 'ç'=>'C', 'Ğ'=>'G', 'ğ'=>'G', 'İ'=>'I', 'I'=>'I', 'ı'=>'I',
            'Ö'=>'O', 'ö'=>'O', 'Ş'=>'S', 'ş'=>'S', 'Ü'=>'U', 'ü'=>'U',
        ]);
        $text = strtoupper($text);
        return preg_replace('/[^A-Z0-9]/', '', $text) ?? '';
    }

    private static function normalizeFlightNumber(mixed $value): ?string
    {
        $value = strtoupper(preg_replace('/\s+/', '', trim((string)$value)) ?? '');
        return $value === '' ? null : $value;
    }

    private static function normalizeAirlineCode(mixed $value): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim((string)$value)) ?? '');
    }

    private static function flightDateFromMatrix(array $matrix, string $fileName): string
    {
        for ($index = 0, $limit = min(5, count($matrix)); $index < $limit; $index++) {
            $date = self::spreadsheetDate($matrix[$index][0] ?? '');
            if ($date !== null) return date('Y-m-d 00:00:00', strtotime($date));
        }
        if (preg_match('/(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})/', $fileName, $match)) {
            return sprintf('%04d-%02d-%02d 00:00:00', (int)$match[3], (int)$match[2], (int)$match[1]);
        }
        if (preg_match('/(\d{4})[.\/-](\d{1,2})[.\/-](\d{1,2})/', $fileName, $match)) {
            return sprintf('%04d-%02d-%02d 00:00:00', (int)$match[1], (int)$match[2], (int)$match[3]);
        }
        return date('Y-m-d 00:00:00');
    }

    private static function combineFlightDateAndTime(string $flightDate, mixed $timeValue): ?string
    {
        $time = self::spreadsheetTime($timeValue);
        if ($time === null) return null;
        $date = new DateTimeImmutable(substr($flightDate, 0, 10));
        if (is_numeric($timeValue)) {
            $dayOffset = max(0, (int)floor((float)$timeValue));
            if ($dayOffset > 0 && $dayOffset < 100) $date = $date->modify('+' . $dayOffset . ' days');
        }
        return $date->format('Y-m-d') . ' ' . $time;
    }

    private static function spreadsheetTime(mixed $value): ?string
    {
        if (is_numeric($value)) {
            $fraction = fmod(max(0.0, (float)$value), 1.0);
            $seconds = (int)round($fraction * 86400) % 86400;
            return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
        }
        $text = trim((string)$value);
        if ($text === '') return null;
        if (preg_match('/^(\d{1,2})[:.](\d{2})(?::(\d{2}))?$/', $text, $match)) {
            return sprintf('%02d:%02d:%02d', (int)$match[1], (int)$match[2], (int)($match[3] ?? 0));
        }
        $timestamp = strtotime($text);
        return $timestamp ? date('H:i:s', $timestamp) : null;
    }

    private static function spreadsheetDate(mixed $value): ?string
    {
        if (is_numeric($value) && (float)$value > 20000) {
            $seconds = (int)round((float)$value * 86400);
            return (new DateTimeImmutable('1899-12-30 00:00:00', new DateTimeZone('Europe/Istanbul')))->modify('+' . $seconds . ' seconds')->format('Y-m-d H:i:s');
        }
        return datetime_input($value);
    }

    private static function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if (!$handle) throw new RuntimeException('CSV dosyası açılamadı.');
        $first = fgets($handle) ?: '';
        rewind($handle);
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $row = array_slice($row, 0, 17);
            if (array_filter($row, static fn($value): bool => trim((string)$value) !== '')) $rows[] = $row;
            if (count($rows) > self::MAX_ROWS + 1) break;
        }
        fclose($handle);
        return $rows;
    }

    private static function readXlsx(string $path): array
    {
        if (!class_exists('ZipArchive')) throw new RuntimeException('XLSX için PHP ZipArchive eklentisi aktif olmalıdır.');
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new RuntimeException('XLSX dosyası açılamadı.');
        $shared = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXml !== false) {
            $xml = simplexml_load_string($sharedXml);
            if ($xml) foreach ($xml->si as $si) {
                $text = (string)$si->t;
                if ($text === '') foreach ($si->r as $run) $text .= (string)$run->t;
                $shared[] = $text;
            }
        }
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if ($sheetXml === false) throw new RuntimeException('XLSX ilk çalışma sayfası bulunamadı.');
        $sheet = simplexml_load_string($sheetXml);
        if (!$sheet) throw new RuntimeException('XLSX içeriği okunamadı.');
        $rows = [];
        foreach ($sheet->sheetData->row as $rowNode) {
            $sourceRowNumber = max(1, (int)$rowNode['r']);
            while (count($rows) < $sourceRowNumber - 1) $rows[] = [];
            $row = [];
            foreach ($rowNode->c as $cell) {
                $reference = (string)$cell['r'];
                preg_match('/^[A-Z]+/', $reference, $matches);
                $letters = $matches[0] ?? 'A';
                $index = 0;
                foreach (str_split($letters) as $letter) $index = $index * 26 + (ord($letter) - 64);
                $index--;
                if ($index > 16) continue;
                $type = (string)$cell['t'];
                $value = $type === 'inlineStr' ? (string)$cell->is->t : (string)$cell->v;
                if ($type === 's') $value = $shared[(int)$value] ?? '';
                $row[$index] = $value;
            }
            if ($row) {
                $max = max(array_keys($row));
                $rows[$sourceRowNumber - 1] = array_replace(array_fill(0, $max + 1, ''), $row);
            } else {
                $rows[$sourceRowNumber - 1] = [];
            }
            if (count($rows) > self::MAX_ROWS + 100) break;
        }
        return $rows;
    }
}
