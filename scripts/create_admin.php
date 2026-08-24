<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/app/bootstrap.php';

function prompt(string $label): string
{
    fwrite(STDOUT, $label . ': ');
    $value = fgets(STDIN);
    return trim($value === false ? '' : $value);
}

$existing = $conn->query(
    "SELECT COUNT(*) AS total FROM user_role_scopes urs
     JOIN roles r ON r.id = urs.role_id
     WHERE r.code = 'admin' AND urs.scope_type = 'global'"
)->fetch_assoc();

if ((int)($existing['total'] ?? 0) > 0) {
    fwrite(STDERR, "Global admin zaten mevcut. Yeni admin oluşturulmadı.\n");
    exit(1);
}

$username = prompt('Kullanıcı adı');
$email = prompt('E-posta (opsiyonel)');
$firstName = prompt('Ad');
$lastName = prompt('Soyad');
$password = prompt('Geçici şifre (en az 12 karakter)');

if ($username === '' || $firstName === '' || $lastName === '' || strlen($password) < 12) {
    fwrite(STDERR, "Zorunlu alanlar eksik veya şifre 12 karakterden kısa.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$emailValue = $email !== '' ? $email : null;

$conn->begin_transaction();
try {
    $stmt = $conn->prepare(
        "INSERT INTO users (username, email, password, first_name, last_name, status, must_change_password)
         VALUES (?, ?, ?, ?, ?, 'active', 1)"
    );
    $stmt->bind_param('sssss', $username, $emailValue, $hash, $firstName, $lastName);
    $stmt->execute();
    $userId = (int)$conn->insert_id;

    $role = $conn->query("SELECT id FROM roles WHERE code = 'admin' LIMIT 1")->fetch_assoc();
    if (!$role) {
        throw new RuntimeException('Admin rolü bulunamadı. Önce database/schema.sql çalıştırılmalı.');
    }

    $scope = $conn->prepare(
        "INSERT INTO user_role_scopes (user_id, role_id, scope_type, airline_id, created_by)
         VALUES (?, ?, 'global', NULL, ?)"
    );
    $scope->bind_param('iii', $userId, $role['id'], $userId);
    $scope->execute();

    Audit::record($userId, 'user.bootstrap_admin', 'user', $userId, ['username' => $username]);
    $conn->commit();
    fwrite(STDOUT, "İlk admin başarıyla oluşturuldu.\n");
} catch (Throwable $exception) {
    $conn->rollback();
    fwrite(STDERR, 'Admin oluşturulamadı: ' . $exception->getMessage() . "\n");
    exit(1);
}
