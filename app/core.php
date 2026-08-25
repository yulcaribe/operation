<?php
declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function normalize_app_path(string $path): string
{
    $path = '/' . trim($path, '/');
    return $path === '//' ? '/' : $path;
}

function app_base_path(): string
{
    static $base = null;
    if ($base !== null) return $base;

    $configured = trim((string)(getenv('OPERATION_BASE_PATH') ?: ''), '/');
    if ($configured !== '') return $base = '/' . $configured;

    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $dir = trim(dirname($script), '/.');
    return $base = $dir === '' ? '' : '/' . $dir;
}

function url_for(string $path = ''): string
{
    if (preg_match('#^(?:https?:)?//#i', $path) || str_starts_with($path, '#')) return $path;
    $path = normalize_app_path($path);
    $base = app_base_path();
    return $path === '/' ? ($base ?: '/') : $base . $path;
}

function asset_url(string $path): string
{
    $relativePath = ltrim($path, '/');
    $url = url_for('/' . $relativePath);
    $filePath = defined('BASE_PATH') ? BASE_PATH . '/' . $relativePath : '';
    if ($filePath !== '' && is_file($filePath)) {
        $modifiedAt = filemtime($filePath);
        if ($modifiedAt !== false) return $url . '?v=' . $modifiedAt;
    }
    return $url;
}

function redirect_to(string $path): never
{
    header('Location: ' . url_for($path));
    exit;
}

function current_path(): string
{
    $uri = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
    $base = app_base_path();
    if ($base !== '' && ($uri === $base || str_starts_with($uri, $base . '/'))) {
        $uri = substr($uri, strlen($base)) ?: '/';
    }
    $path = normalize_app_path($uri);
    return $path === '/index.php' ? '/' : $path;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $token = (string)($_POST['_csrf'] ?? '');
    if ($token === '' || !hash_equals((string)($_SESSION['_csrf'] ?? ''), $token)) {
        throw new RuntimeException('Oturum güvenlik doğrulaması başarısız.');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function pull_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : null;
}

function client_ip(): ?string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    return is_string($ip) ? substr($ip, 0, 45) : null;
}

function nullable_string(mixed $value): ?string
{
    if ($value === null) return null;
    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

function datetime_input(mixed $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') return null;
    foreach (['Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i', 'd.m.Y H:i', 'd/m/Y H:i'] as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $value);
        if ($date instanceof DateTimeImmutable) return $date->format('Y-m-d H:i:s');
    }
    $timestamp = strtotime($value);
    return $timestamp ? date('Y-m-d H:i:s', $timestamp) : null;
}

function datetime_local(?string $value): string
{
    return $value && strtotime($value) ? date('Y-m-d\TH:i', strtotime($value)) : '';
}

function friendly_error(Throwable $error): string
{
    $message = $error->getMessage();
    if (str_contains($message, 'Access denied')) return 'Veritabanı kullanıcı adı, şifresi veya yetkisi hatalı.';
    if (str_contains($message, 'flight_timeline_settings') || str_contains($message, 'flight_timeline_rules')) {
        return 'Uçuş Zaman Çizelgesi migration’ı eksik. Yalnızca database/migrations/001_flight_timeline.sql dosyasını bir kez içe aktarın.';
    }
    if (str_contains($message, "doesn't exist") || str_contains($message, 'Unknown table')) return 'Veritabanı şeması eksik. database/schema.sql dosyasını içe aktarın.';
    if (str_contains($message, 'Duplicate entry')) return 'Aynı benzersiz bilgiyle kayıt zaten mevcut.';
    if ($error instanceof DatabaseException) {
        error_log('Operation database error: ' . $message);
        return 'Veritabanı işlemi tamamlanamadı. Sistem kaydını kontrol edin.';
    }
    return $message;
}

final class DatabaseException extends RuntimeException {}

final class DB
{
    private static function conn(): mysqli
    {
        $conn = $GLOBALS['conn'] ?? null;
        if (!$conn instanceof mysqli) throw new DatabaseException('Veritabanı bağlantısı bulunamadı.');
        return $conn;
    }

    private static function statement(string $sql, array $params = []): mysqli_stmt
    {
        try {
            $stmt = self::conn()->prepare($sql);
        } catch (mysqli_sql_exception $error) {
            throw new DatabaseException($error->getMessage(), (int)$error->getCode(), $error);
        }
        if (!$stmt) throw new DatabaseException(self::conn()->error);
        if ($params) {
            $types = '';
            $values = array_values($params);
            foreach ($values as $value) {
                $types .= is_int($value) || is_bool($value) ? 'i' : (is_float($value) ? 'd' : 's');
            }
            $refs = [];
            foreach ($values as $index => $value) $refs[$index] = &$values[$index];
            $stmt->bind_param($types, ...$refs);
        }
        try {
            if (!$stmt->execute()) throw new DatabaseException($stmt->error);
        } catch (mysqli_sql_exception $error) {
            throw new DatabaseException($error->getMessage(), (int)$error->getCode(), $error);
        }
        return $stmt;
    }

    public static function fetch(string $sql, array $params = []): ?array
    {
        $row = self::statement($sql, $params)->get_result()->fetch_assoc();
        return $row ?: null;
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::statement($sql, $params)->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public static function execute(string $sql, array $params = []): int
    {
        return self::statement($sql, $params)->affected_rows;
    }

    public static function insert(string $sql, array $params = []): int
    {
        self::statement($sql, $params);
        return (int)self::conn()->insert_id;
    }

    public static function begin(): void { self::conn()->begin_transaction(); }
    public static function commit(): void { self::conn()->commit(); }
    public static function rollback(): void { self::conn()->rollback(); }
}

final class Audit
{
    public static function record(?int $actorId, string $action, string $entityType, ?int $entityId = null, ?array $data = null): void
    {
        DB::insert(
            'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, new_values, ip_address) VALUES (?, ?, ?, ?, ?, ?)',
            [$actorId, $action, $entityType, $entityId, $data === null ? null : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), client_ip()]
        );
    }
}

final class Auth
{
    public static function attempt(string $identity, string $password): ?array
    {
        $identity = strtolower(trim($identity));
        $user = DB::fetch(
            'SELECT * FROM users WHERE (LOWER(username) = ? OR LOWER(email) = ?) AND status = "active" AND deleted_at IS NULL LIMIT 1',
            [$identity, $identity]
        );
        if (!$user || !password_verify($password, (string)$user['password'])) return null;

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        unset($_SESSION['_csrf']);
        DB::execute('UPDATE users SET last_login_at = NOW() WHERE id = ?', [(int)$user['id']]);
        Audit::record((int)$user['id'], 'auth.login', 'user', (int)$user['id']);
        return self::sanitize($user);
    }

    public static function currentUser(): ?array
    {
        if (empty($_SESSION['user_id'])) return null;
        $user = DB::fetch('SELECT * FROM users WHERE id = ? AND status = "active" AND deleted_at IS NULL', [(int)$_SESSION['user_id']]);
        if (!$user) self::logout();
        return $user ? self::sanitize($user) : null;
    }

    public static function requireWeb(): array
    {
        $user = self::currentUser();
        if (!$user) redirect_to('/login');
        return $user;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
        }
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    }

    public static function changePassword(array $actor, array $data): void
    {
        $user = DB::fetch('SELECT password FROM users WHERE id = ?', [(int)$actor['id']]);
        if (!$user || !password_verify((string)($data['current_password'] ?? ''), (string)$user['password'])) {
            throw new RuntimeException('Mevcut şifre doğru değil.');
        }
        $password = (string)($data['new_password'] ?? '');
        if (strlen($password) < 12 || $password !== (string)($data['new_password_confirmation'] ?? '')) {
            throw new RuntimeException('Yeni şifre en az 12 karakter olmalı ve tekrarıyla eşleşmelidir.');
        }
        DB::execute('UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), (int)$actor['id']]);
        Audit::record((int)$actor['id'], 'user.password_changed', 'user', (int)$actor['id']);
    }

    private static function sanitize(array $user): array
    {
        unset($user['password']);
        $user['name'] = trim((string)$user['first_name'] . ' ' . (string)$user['last_name']);
        return $user;
    }
}
