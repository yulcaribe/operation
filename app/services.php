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
            'SELECT id FROM permissions WHERE code IN ("flights.view", "flights.create", "flights.update", "flights.cancel", "flights.delete", "flights.assign", "flights.complete", "processes.view", "processes.update", "processes.override", "reports.view")'
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
    public static function allVisible(array $actor, array $filters = [], string $permission = 'flights.view'): array
    {
        $params = [];
        $where = ['f.deleted_at IS NULL'];
        if (!empty($filters['status']) && in_array($filters['status'], ['scheduled', 'active', 'completed', 'cancelled', 'archived'], true)) {
            $where[] = 'f.status = ?'; $params[] = $filters['status'];
        }
        $rows = DB::fetchAll(
            'SELECT f.*, a.name AS airline_name, a.icao_code, a.iata_code, ft.name AS flight_type_name,
                    (SELECT GROUP_CONCAT(DISTINCT CONCAT(u.first_name, " ", u.last_name) SEPARATOR ", ")
                     FROM flight_assignments fa JOIN users u ON u.id = fa.user_id
                     WHERE fa.flight_id = f.id AND fa.status IN ("active", "completed")) AS assignees
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
        Authorization::require($actor, $existing ? 'flights.update' : 'flights.create', $existing ? self::context($existing) : []);
        $payload = self::normalize($data);
        $errors = self::validate($payload);
        if ($errors) throw new RuntimeException(implode(' ', $errors));
        if ($payload['status'] === 'completed' && (!$existing || $existing['status'] !== 'completed')) {
            throw new RuntimeException('Uçuş yalnızca operasyon ekranındaki tamamlama işlemiyle tamamlanabilir.');
        }
        if ($existing && $payload['status'] !== $existing['status'] && in_array($payload['status'], ['cancelled', 'archived'], true)) {
            Authorization::require($actor, 'flights.cancel', self::context($existing));
        }
        if ($existing && $existing['status'] === 'completed' && $payload['status'] !== 'completed') {
            Authorization::require($actor, 'flights.cancel', self::context($existing));
        }
        if (!$existing && !can($actor, 'flights.create', ['airline_id' => (int)$payload['airline_id']])) throw new RuntimeException('Seçilen havayolu için uçuş oluşturma yetkiniz yok.');
        if ($existing && (int)$existing['airline_id'] !== (int)$payload['airline_id'] && !can($actor, 'flights.update', ['airline_id' => (int)$payload['airline_id']])) {
            throw new RuntimeException('Yeni havayolu kapsamı için yetkiniz yok.');
        }
        DB::begin();
        try {
            if ($existing) {
                self::update($flightId, $payload, (int)$actor['id']);
            } else {
                $flightId = self::create($payload, (int)$actor['id']);
            }
            Audit::record((int)$actor['id'], $existing ? 'flight.updated' : 'flight.created', 'flight', $flightId, $payload);
            DB::commit();
        } catch (Throwable $error) {
            DB::rollback();
            throw $error;
        }
        return $flightId;
    }

    public static function assignments(int $flightId): array
    {
        return DB::fetchAll('SELECT user_id FROM flight_assignments WHERE flight_id = ? AND status = "active"', [$flightId]);
    }

    public static function assign(array $actor, int $flightId, array $userIds): void
    {
        $flight = self::find($flightId);
        if (!$flight) throw new RuntimeException('Uçuş bulunamadı.');
        Authorization::require($actor, 'flights.assign', self::context($flight));
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        DB::begin();
        try {
            DB::execute('UPDATE flight_assignments SET status = "revoked", unassigned_at = NOW() WHERE flight_id = ? AND status = "active"', [$flightId]);
            foreach ($userIds as $userId) {
                if (!DB::fetch('SELECT id FROM users WHERE id = ? AND status = "active" AND deleted_at IS NULL', [$userId])) continue;
                DB::execute(
                    'INSERT INTO flight_assignments (flight_id, user_id, assignment_role, status, assigned_by, assigned_at, unassigned_at)
                     VALUES (?, ?, "primary", "active", ?, NOW(), NULL)
                     ON DUPLICATE KEY UPDATE status = "active", assigned_by = VALUES(assigned_by), assigned_at = NOW(), unassigned_at = NULL',
                    [$flightId, $userId, (int)$actor['id']]
                );
            }
            Audit::record((int)$actor['id'], 'flight.assigned', 'flight', $flightId, ['user_ids' => $userIds]);
            DB::commit();
        } catch (Throwable $error) { DB::rollback(); throw $error; }
    }

    public static function archive(array $actor, int $flightId): void
    {
        $flight = self::find($flightId);
        if (!$flight) throw new RuntimeException('Uçuş bulunamadı.');
        Authorization::require($actor, 'flights.cancel', self::context($flight));
        DB::execute('UPDATE flights SET status = "archived", updated_by = ? WHERE id = ?', [(int)$actor['id'], $flightId]);
        Audit::record((int)$actor['id'], 'flight.archived', 'flight', $flightId);
    }

    public static function delete(array $actor, int $flightId): void
    {
        $flight = self::find($flightId);
        if (!$flight) throw new RuntimeException('Uçuş bulunamadı.');
        Authorization::require($actor, 'flights.delete', self::context($flight));
        DB::begin();
        try {
            DB::execute('UPDATE flights SET deleted_at = NOW(), source_key = NULL, updated_by = ? WHERE id = ?', [(int)$actor['id'], $flightId]);
            DB::execute('UPDATE flight_assignments SET status = "revoked", unassigned_at = NOW() WHERE flight_id = ? AND status = "active"', [$flightId]);
            Audit::record((int)$actor['id'], 'flight.deleted', 'flight', $flightId, ['soft_delete' => true]);
            DB::commit();
        } catch (Throwable $error) {
            DB::rollback();
            throw $error;
        }
    }

    public static function complete(array $actor, int $flightId): void
    {
        $flight = self::find($flightId);
        if (!$flight) throw new RuntimeException('Uçuş bulunamadı.');
        Authorization::require($actor, 'flights.complete', self::context($flight));
        if (in_array($flight['status'], ['completed', 'cancelled', 'archived'], true)) throw new RuntimeException('Bu durumdaki uçuş tamamlanamaz.');
        $missing = DB::fetchAll(
            'SELECT pt.name FROM flight_type_process_map m
             JOIN process_types pt ON pt.id = m.process_type_id
             LEFT JOIN flight_processes fp ON fp.flight_id = ? AND fp.process_type_id = pt.id
             WHERE m.flight_type_id = ? AND m.required = 1
               AND ((pt.input_type = "state" AND (fp.state IS NULL OR fp.state != "finished"))
                 OR (pt.input_type = "datetime" AND fp.value_datetime IS NULL)
                 OR (pt.input_type = "text" AND (fp.value_text IS NULL OR fp.value_text = "")))',
            [$flightId, (int)$flight['flight_type_id']]
        );
        if ($missing) throw new RuntimeException('Zorunlu süreçler tamamlanmadı: ' . implode(', ', array_column($missing, 'name')));
        DB::execute('UPDATE flights SET status = "completed", updated_by = ? WHERE id = ?', [(int)$actor['id'], $flightId]);
        DB::execute('UPDATE flight_assignments SET status = "completed" WHERE flight_id = ? AND status = "active"', [$flightId]);
        Audit::record((int)$actor['id'], 'flight.completed', 'flight', $flightId);
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
        if (!in_array($data['status'], ['scheduled', 'active', 'completed', 'cancelled', 'archived'], true)) $errors[] = 'Uçuş durumu geçersiz.';
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
        $permission = $action === 'reset' ? 'processes.override' : 'processes.update';
        Authorization::require($actor, $permission, FlightService::context($flight));
        $mapped = DB::fetch('SELECT pt.input_type FROM flight_type_process_map m JOIN process_types pt ON pt.id = m.process_type_id WHERE m.flight_type_id = ? AND m.process_type_id = ?', [(int)$flight['flight_type_id'], $processTypeId]);
        if (!$mapped) throw new RuntimeException('Süreç bu uçuş tipine ait değil.');
        $allowedActions = [
            'state' => ['start', 'finish', 'not_used', 'reset'],
            'datetime' => ['mark_time', 'reset'],
            'text' => ['save_text', 'reset'],
        ];
        if (!in_array($action, $allowedActions[$mapped['input_type']] ?? [], true)) throw new RuntimeException('Süreç veri tipiyle işlem uyuşmuyor.');
        $current = DB::fetch('SELECT * FROM flight_processes WHERE flight_id = ? AND process_type_id = ?', [$flightId, $processTypeId]);
        $alreadyRecorded = $current && (
            $current['state'] === 'finished'
            || $current['value_datetime'] !== null
            || trim((string)$current['value_text']) !== ''
        );
        if (($alreadyRecorded || in_array($flight['status'], ['completed', 'cancelled', 'archived'], true)) && $action !== 'reset') {
            Authorization::require($actor, 'processes.override', FlightService::context($flight));
        }
        $state = 'not_started'; $started = null; $finished = null; $valueDate = null; $valueText = null;
        if ($action === 'start') { $state = 'started'; $started = date('Y-m-d H:i:s'); }
        elseif ($action === 'finish') { $state = 'finished'; $started = $current['started_at'] ?? date('Y-m-d H:i:s'); $finished = date('Y-m-d H:i:s'); }
        elseif ($action === 'not_used') { $state = 'not_used'; }
        elseif ($action === 'mark_time') { $state = 'finished'; $valueDate = datetime_input($data['value_datetime'] ?? '') ?: date('Y-m-d H:i:s'); }
        elseif ($action === 'save_text') { $valueText = trim((string)($data['value_text'] ?? '')); $state = $valueText === '' ? 'not_started' : 'finished'; }
        elseif ($action !== 'reset') throw new RuntimeException('Geçersiz süreç işlemi.');
        DB::execute(
            'INSERT INTO flight_processes (flight_id, process_type_id, state, started_at, finished_at, value_datetime, value_text, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE state = VALUES(state), started_at = COALESCE(VALUES(started_at), started_at), finished_at = VALUES(finished_at), value_datetime = VALUES(value_datetime), value_text = VALUES(value_text), updated_by = VALUES(updated_by)',
            [$flightId, $processTypeId, $state, $started, $finished, $valueDate, $valueText, (int)$actor['id']]
        );
        if ($action === 'reset') DB::execute('UPDATE flight_processes SET state = "not_started", started_at = NULL, finished_at = NULL, value_datetime = NULL, value_text = NULL, updated_by = ? WHERE flight_id = ? AND process_type_id = ?', [(int)$actor['id'], $flightId, $processTypeId]);
        Audit::record((int)$actor['id'], 'process.' . $action, 'flight', $flightId, [
            'process_type_id' => $processTypeId,
            'state' => $action === 'reset' ? 'not_started' : $state,
            'value_datetime' => $valueDate,
            'value_text' => $valueText,
        ]);
    }
}

final class ImportService
{
    private const MAX_ROWS = 2000;
    private static ?array $airlinesByIcao = null;
    private static ?array $flightTypesByCode = null;

    public static function batches(): array
    {
        return DB::fetchAll(
            'SELECT b.*, CONCAT(u.first_name, " ", u.last_name) AS imported_by_name
             FROM flight_import_batches b LEFT JOIN users u ON u.id = b.imported_by ORDER BY b.id DESC LIMIT 100'
        );
    }

    public static function batch(int $batchId): ?array
    {
        return DB::fetch('SELECT * FROM flight_import_batches WHERE id = ?', [$batchId]);
    }

    public static function rows(int $batchId): array
    {
        $rows = DB::fetchAll('SELECT * FROM flight_import_rows WHERE batch_id = ? ORDER BY source_row_number', [$batchId]);
        foreach ($rows as &$row) $row['data'] = json_decode((string)$row['payload'], true) ?: [];
        unset($row);
        return $rows;
    }

    public static function rowsPage(int $batchId, int $limit, int $offset): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $rows = DB::fetchAll(
            'SELECT * FROM flight_import_rows WHERE batch_id = ? ORDER BY source_row_number LIMIT ' . $limit . ' OFFSET ' . $offset,
            [$batchId]
        );
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
            $importRows[] = [
                'source_row_number' => $rowIndex + 1,
                'source' => [
                    'airline_icao' => self::normalizeAirlineCode($rawRow[0] ?? ''),
                    'arrival_flight_number' => $arrivalNo,
                    'departure_flight_number' => $departureNo,
                    'scheduled_arrival_at' => $arrivalNo !== null ? $flightDate : null,
                    'scheduled_departure_at' => $departureNo !== null ? $flightDate : null,
                    'stand' => trim((string)($rawRow[3] ?? '')),
                ],
            ];
        }
        if (!$importRows) throw new RuntimeException('İlk sayfanın A:D kolonlarında kullanılabilir geliş veya gidiş uçuşu bulunamadı.');
        if (count($importRows) > self::MAX_ROWS) throw new RuntimeException('Tek dosyada en fazla ' . self::MAX_ROWS . ' uçuş işlenebilir.');
        $hash = hash_file('sha256', (string)$file['tmp_name']);
        if (!is_string($hash)) throw new RuntimeException('Yüklenen dosyanın özeti hesaplanamadı.');

        DB::begin();
        try {
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

    public static function updateRows(array $actor, int $batchId, array $rows): void
    {
        Authorization::require($actor, 'imports.stage');
        $batch = self::batch($batchId);
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

    public static function commit(array $actor, int $batchId): void
    {
        Authorization::require($actor, 'imports.commit');
        $batch = self::batch($batchId);
        if (!$batch || $batch['status'] !== 'preview') throw new RuntimeException('Import onay beklemiyor veya zaten işlendi.');
        self::revalidateDuplicateStatuses($batchId);
        $rows = self::rows($batchId);
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
            DB::execute(
                'UPDATE flight_import_batches SET status = ?, success_rows = ?, failed_rows = ?, completed_at = NOW() WHERE id = ?',
                [$failed > 0 ? 'completed_with_errors' : 'completed', $success, $failed, $batchId]
            );
            Audit::record((int)$actor['id'], 'import.committed', 'flight_import_batch', $batchId, ['success' => $success, 'skipped' => $failed]);
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
            'tail_number' => $source['tail_number'] ?? '', 'aircraft_type' => $source['aircraft_type'] ?? '', 'stand' => $source['stand'] ?? '', 'note' => $source['note'] ?? '',
            'status' => 'scheduled', 'source' => 'excel',
        ]);
        $payload['airline_icao'] = $icao;
        $payload['flight_type'] = $typeCode;
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
            $row = array_slice($row, 0, 4);
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
                if ($index > 3) continue;
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
