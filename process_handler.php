<?php
require_once __DIR__ . '/beta_debug.php';
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/operation_functions.php';
function op_reply(bool $ok, string $message, array $extra = []): void {
    echo json_encode(array_merge(['status' => $ok ? 'success' : 'error', 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}
function op_form_datetime(string $dateKey, string $timeKey, ?string $fallbackDate = null): ?string {
    $time = trim((string)($_POST[$timeKey] ?? ''));
    $date = trim((string)($_POST[$dateKey] ?? ''));
    if ($time === '') return null;
    if ($date === '') $date = $fallbackDate ?? date('Y-m-d');
    $text = $date . ' ' . substr($time, 0, 5);
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $text);
    return $dt && $dt->format('Y-m-d H:i') === $text ? $dt->format('Y-m-d H:i:s') : null;
}
$userId = (int)($_SESSION['user_id'] ?? 0);
if (!$userId) { http_response_code(401); op_reply(false, 'Oturum bulunamadı.'); }
$flightId = (int)($_POST['flight_id'] ?? $_SESSION['active_flight_id'] ?? 0);
$flight = op_current_flight($conn, $userId, $flightId);
if (!$flight) { http_response_code(403); op_reply(false, 'Aktif uçuş bulunamadı veya size ait değil.'); }
$flightId = (int)$flight['id'];
$action = (string)($_POST['action'] ?? '');
$phase = (string)($_POST['phase'] ?? '');
$key = (string)($_POST['process_type'] ?? '');
$allowed = op_process_keys();
try {
    if ($action === 'save_arrival_data' || $action === 'save_departure_data') {
        if ($action === 'save_arrival_data') {
            if ($flight['flight_type'] === 'departure') op_reply(false, 'Bu uçuşta Arrival yok.');
            $schedule = $flight['scheduled_arrival_at'] ? substr($flight['scheduled_arrival_at'], 0, 10) : date('Y-m-d');
            $touchdown = op_form_datetime('touchdown_date', 'touchdown_time', $schedule);
            $onblock = op_form_datetime('onblock_date', 'onblock_time', $schedule);
            if ($touchdown === null && !empty($_POST['touchdown'])) { $_POST['touchdown_time'] = $_POST['touchdown']; $touchdown = op_form_datetime('touchdown_date', 'touchdown_time', $schedule); }
            if ($onblock === null && !empty($_POST['onblock'])) { $_POST['onblock_time'] = $_POST['onblock']; $onblock = op_form_datetime('onblock_date', 'onblock_time', $schedule); }
            $fuel = trim((string)($_POST['remainingfuel'] ?? ''));
            $fuel = $fuel === '' ? null : str_replace(',', '.', $fuel);
            if ($fuel !== null && !is_numeric($fuel)) op_reply(false, 'Geçersiz yakıt değeri.');
            $note = trim((string)($_POST['arrival_note'] ?? ''));
            $stmt = $conn->prepare("UPDATE flights SET touchdown_at = ?, onblock_at = ?, remaining_fuel = ?, arrival_note = ? WHERE id = ? AND user_id = ?");
            $stmt->bind_param('ssssii', $touchdown, $onblock, $fuel, $note, $flightId, $userId);
            $stmt->execute();
            op_reply(true, 'Arrival bilgileri kaydedildi.');
        }
        if ($flight['flight_type'] === 'arrival') op_reply(false, 'Bu uçuşta Departure yok.');
        $fuel = trim((string)($_POST['blockfuel'] ?? ''));
        $fuel = $fuel === '' ? null : str_replace(',', '.', $fuel);
        if ($fuel !== null && !is_numeric($fuel)) op_reply(false, 'Geçersiz yakıt değeri.');
        $schedule = $flight['scheduled_departure_at'] ? substr($flight['scheduled_departure_at'], 0, 10) : date('Y-m-d');
        $ctot = op_form_datetime('ctot_date', 'ctot', $schedule);
        $pax = trim((string)($_POST['final_pax'] ?? ''));
        $note = trim((string)($_POST['departure_note'] ?? ''));
        $stmt = $conn->prepare("UPDATE flights SET block_fuel = ?, ctot_at = ?, final_pax = ?, departure_note = ? WHERE id = ? AND user_id = ?");
        $stmt->bind_param('ssssii', $fuel, $ctot, $pax, $note, $flightId, $userId);
        $stmt->execute();
        op_reply(true, 'Departure bilgileri kaydedildi.');
    }
    if (!isset($allowed[$phase]) || !in_array($key, $allowed[$phase], true)) {
        http_response_code(400); op_reply(false, 'Geçersiz süreç.');
    }
    if ($flight['flight_type'] !== 'turnaround' && $flight['flight_type'] !== $phase)
        op_reply(false, 'Bu uçuş türünde süreç kullanılamaz.');
    if ($action === 'reset_single') {
        $stmt = $conn->prepare("DELETE FROM flight_processes WHERE flight_id = ? AND phase = ? AND process_key = ?");
        $stmt->bind_param('iss', $flightId, $phase, $key);
        $stmt->execute();
        op_reply(true, 'Süreç sıfırlandı.');
    }
    if ($action === 'reset') {
        $target = (string)($_POST['reset_target'] ?? '');
        if (!in_array($target, ['start', 'finish', 'not_used'], true)) op_reply(false, 'Geçersiz reset hedefi.');
        $stmt = $conn->prepare("SELECT state, started_at, finished_at FROM flight_processes WHERE flight_id = ? AND phase = ? AND process_key = ?");
        $stmt->bind_param('iss', $flightId, $phase, $key);
        $stmt->execute();
        $old = $stmt->get_result()->fetch_assoc();
        if (!$old) op_reply(true, 'Süreç sıfırlandı.', ['new_status' => 'not_started']);
        if ($target === 'start' || $target === 'not_used') {
            $state = 'not_started'; $start = null; $end = null;
        } else {
            $state = 'started'; $start = $old['started_at']; $end = null;
        }
        $update = $conn->prepare("UPDATE flight_processes SET state = ?, started_at = ?, finished_at = ?, value_datetime = NULL WHERE flight_id = ? AND phase = ? AND process_key = ?");
        $update->bind_param('sssiss', $state, $start, $end, $flightId, $phase, $key);
        $update->execute();
        op_reply(true, 'Süreç sıfırlandı.', ['new_status' => $state]);
    }
    if ($action === 'mark_time') {
        $stmt = $conn->prepare("INSERT INTO flight_processes (flight_id, phase, process_key, state, value_datetime) VALUES (?, ?, ?, 'finished', NOW()) ON DUPLICATE KEY UPDATE state = 'finished', value_datetime = NOW()");
        $stmt->bind_param('iss', $flightId, $phase, $key);
        $stmt->execute();
        op_reply(true, 'Zaman kaydedildi.', ['new_status' => 'finished']);
    }
    if (in_array($action, ['start', 'finish', 'not_used'], true)) {
        $state = $action === 'start' ? 'started' : ($action === 'finish' ? 'finished' : 'not_used');
        if ($action === 'start')
            $stmt = $conn->prepare("INSERT INTO flight_processes (flight_id, phase, process_key, state, started_at) VALUES (?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE state = VALUES(state), started_at = NOW(), finished_at = NULL");
        elseif ($action === 'finish')
            $stmt = $conn->prepare("INSERT INTO flight_processes (flight_id, phase, process_key, state, finished_at) VALUES (?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE state = VALUES(state), finished_at = NOW()");
        else
            $stmt = $conn->prepare("INSERT INTO flight_processes (flight_id, phase, process_key, state) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE state = VALUES(state), started_at = NULL, finished_at = NULL");
        $stmt->bind_param('isss', $flightId, $phase, $key, $state);
        $stmt->execute();
        op_reply(true, 'Süreç güncellendi.', ['new_status' => $state]);
    }
    http_response_code(400); op_reply(false, 'Geçersiz işlem.');
} catch (Throwable $e) {
    error_log('[Operation BETA] process_handler: ' . $e->getMessage());
    http_response_code(500); op_reply(false, 'Kayıt sırasında sistem hatası oluştu.');
}
