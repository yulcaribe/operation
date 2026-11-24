<?php
require_once __DIR__ . '/beta_debug.php';
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "db.php";

// Oturum kontrolü
if (!isset($_SESSION['user_id'])) {
    echo json_encode(["status" => "error", "message" => "Kullanıcı oturumu bulunamadı."]);
    exit;
}

$user_id = $_SESSION['user_id'];

// Kullanıcının gerçekten aktif uçuşu var mı ve ona mı ait?
$stmt = $conn->prepare("
    SELECT id 
    FROM flights 
    WHERE user_id = ? AND status = 'active' 
    ORDER BY id DESC 
    LIMIT 1
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();

$flight_id = $row['id'] ?? null;

if (!$flight_id) {
    echo json_encode(["status" => "error", "message" => "Aktif uçuş bulunamadı."]);
    exit;
}

$action = $_POST['action'] ?? null;
$process_type = $_POST['process_type'] ?? null;
$reset_target = $_POST['reset_target'] ?? null;

if ($action === 'mark_time' && $process_type) {
    $column = $process_type;
    $query = "UPDATE flights SET $column = CURRENT_TIMESTAMP WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $flight_id);
    $stmt->execute();
    echo json_encode(["status" => "success", "message" => "$process_type zamanı işlendi"]);
    exit;
}

if ($action === 'reset_single' && $process_type) {
    $column = $process_type;
    $query = "UPDATE flights SET $column = NULL WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $flight_id);
    $stmt->execute();
    echo json_encode(["status" => "success", "message" => "$process_type sıfırlandı"]);
    exit;
}

if ($action === 'save_arrival_data') {
    header('Content-Type: application/json');

    $touchdown = (!empty($_POST['touchdown_date']) && !empty($_POST['touchdown_time']))
        ? $_POST['touchdown_date'] . ' ' . $_POST['touchdown_time'] . ':00'
        : null;

    $onblock = (!empty($_POST['onblock_date']) && !empty($_POST['onblock_time']))
        ? $_POST['onblock_date'] . ' ' . $_POST['onblock_time'] . ':00'
        : null;

    $remainingfuel = ($_POST['remainingfuel'] !== "") ? (int)$_POST['remainingfuel'] : null;
    $arrival_note  = ($_POST['arrival_note'] !== "") ? $_POST['arrival_note'] : null;

    $stmt = $conn->prepare("
        UPDATE flights 
        SET touchdown = ?, onblock = ?, remainingfuel = ?, arrival_note = ?
        WHERE id = ?
    ");
    $stmt->bind_param("ssisi", $touchdown, $onblock, $remainingfuel, $arrival_note, $flight_id);

    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Arrival bilgileri kaydedildi"]);
    } else {
        echo json_encode(["status" => "error", "message" => $stmt->error]);
    }
    exit;
}






if ($action === 'save_departure_data') {
    header('Content-Type: application/json');
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    error_reporting(E_ALL);

    // Gelen verileri işle
    $blockfuel = !empty($_POST['blockfuel']) ? (int)$_POST['blockfuel'] : 0;

    $ctot_time = $_POST['ctot'] ?? '';
    $ctot = (!empty($ctot_time)) ? date('Y-m-d') . ' ' . $ctot_time . ':00' : null;

    $final_pax = !empty($_POST['final_pax']) ? $_POST['final_pax'] : '0';
    $departure_note = !empty($_POST['departure_note']) ? $_POST['departure_note'] : '';

    // SQL hazırlığı
    $stmt = $conn->prepare("UPDATE flights SET blockfuel = ?, ctot = ?, final_pax = ?, departure_note = ? WHERE id = ?");
    
    // prepare kontrolü
    if (!$stmt) {
        echo json_encode(["status" => "error", "message" => "Prepare hatası: " . $conn->error]);
        exit;
    }

    $stmt->bind_param("isssi", $blockfuel, $ctot, $final_pax, $departure_note, $flight_id);

    // Sorguyu çalıştır
    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Departure bilgileri kaydedildi"]);
    } else {
        echo json_encode(["status" => "error", "message" => $stmt->error]);
    }
    exit;
}


if ($action === "reset" && $process_type && $reset_target) {
    $status_column = $process_type . "_status";
    $start_column = $process_type . "_start_time";
    $end_column = $process_type . "_end_time";

    if ($reset_target === 'start') {
        $query = "UPDATE flights SET $start_column = NULL, $status_column = 'not_started' WHERE id = ?";
    } elseif ($reset_target === 'finish') {
        $query = "UPDATE flights SET $end_column = NULL, $status_column = 'started' WHERE id = ?";
    } elseif ($reset_target === 'not_used') {
        $query = "UPDATE flights SET $status_column = 'not_started' WHERE id = ?";
    } else {
        echo json_encode(["status" => "error", "message" => "Geçersiz reset hedefi"]);
        exit;
    }

    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $flight_id);
    $stmt->execute();
    echo json_encode(["status" => "success", "new_status" => "active"]);
    exit;
}

if (in_array($action, ['start', 'finish', 'not_used']) && $process_type) {
    $status_column = $process_type . "_status";
    $start_column = $process_type . "_start_time";
    $end_column = $process_type . "_end_time";

    switch ($action) {
        case "start":
            $new_status = "started";
            $time_column = $start_column;
            break;
        case "finish":
            $new_status = "finished";
            $time_column = $end_column;
            break;
        case "not_used":
            $new_status = "not_used";
            $time_column = null;
            break;
        default:
            echo json_encode(["status" => "error", "message" => "Geçersiz işlem"]);
            exit;
    }

    if (isset($time_column)) {
        $query = "UPDATE flights SET $status_column = ?, $time_column = CURRENT_TIMESTAMP WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("si", $new_status, $flight_id);
    } else {
        $query = "UPDATE flights SET $status_column = ? WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("si", $new_status, $flight_id);
    }

    $success = $stmt->execute();

    if ($success) {
        echo json_encode(["status" => "success", "message" => "İşlem başarıyla güncellendi", "new_status" => $new_status]);
    } else {
        echo json_encode(["status" => "error", "message" => "Veritabanı hatası: " . $stmt->error]);
    }
    exit;
}

echo json_encode(["status" => "error", "message" => "Geçersiz işlem"]);
