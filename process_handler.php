<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "db.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

$user_id = $_SESSION['user_id'] ?? null;
$action = $_POST['action'] ?? null;
$process_id = (int)($_POST['process_id'] ?? 0);
$reset_target = $_POST['reset_target'] ?? null;
$value = $_POST['value'] ?? null;

if (!$user_id) {
    echo json_encode(["status" => "error", "message" => "Giriş yapılmamış."]);
    exit;
}
if (!$action || !$process_id) {
    echo json_encode(["status" => "error", "message" => "Eksik parametre."]);
    exit;
}

// Aktif uçuşu al
$stmt = $conn->prepare("SELECT id FROM flights WHERE user_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$flight = $stmt->get_result()->fetch_assoc();
$flight_id = $flight['id'] ?? null;

if (!$flight_id) {
    echo json_encode(["status" => "error", "message" => "Aktif uçuş bulunamadı."]);
    exit;
}

switch ($action) {
    case "mark_time":
        $stmt = $conn->prepare("REPLACE INTO flight_processes (flight_id, process_type_id, value_datetime) VALUES (?, ?, CURRENT_TIMESTAMP)");
        $stmt->bind_param("ii", $flight_id, $process_id);
        $stmt->execute();
        echo json_encode(["status" => "success", "message" => "Zaman işaretlendi"]);
        break;

    case "reset_single":
        $stmt = $conn->prepare("UPDATE flight_processes SET value_datetime = NULL WHERE flight_id = ? AND process_type_id = ?");
        $stmt->bind_param("ii", $flight_id, $process_id);
        $stmt->execute();
        echo json_encode(["status" => "success", "message" => "Zaman sıfırlandı"]);
        break;

    case "start":
        $stmt = $conn->prepare("REPLACE INTO flight_processes (flight_id, process_type_id, value_enum, start_time) VALUES (?, ?, 'started', CURRENT_TIMESTAMP)");
        $stmt->bind_param("ii", $flight_id, $process_id);
        $stmt->execute();
        echo json_encode(["status" => "success", "message" => "Başlatıldı", "new_status" => "started"]);
        break;

    case "finish":
        $stmt = $conn->prepare("UPDATE flight_processes SET value_enum = 'finished', finish_time = CURRENT_TIMESTAMP WHERE flight_id = ? AND process_type_id = ?");
        $stmt->bind_param("ii", $flight_id, $process_id);
        $stmt->execute();
        echo json_encode(["status" => "success", "message" => "Tamamlandı", "new_status" => "finished"]);
        break;

    case "not_used":
        $stmt = $conn->prepare("REPLACE INTO flight_processes (flight_id, process_type_id, value_enum) VALUES (?, ?, 'not_used')");
        $stmt->bind_param("ii", $flight_id, $process_id);
        $stmt->execute();
        echo json_encode(["status" => "success", "message" => "İşaretlendi", "new_status" => "not_used"]);
        break;

    case "reset":
        if ($reset_target === "start") {
            $stmt = $conn->prepare("UPDATE flight_processes SET value_enum = NULL, start_time = NULL WHERE flight_id = ? AND process_type_id = ?");
        } elseif ($reset_target === "finish") {
            $stmt = $conn->prepare("UPDATE flight_processes SET value_enum = 'started', finish_time = NULL WHERE flight_id = ? AND process_type_id = ?");
        } elseif ($reset_target === "not_used") {
            $stmt = $conn->prepare("UPDATE flight_processes SET value_enum = NULL WHERE flight_id = ? AND process_type_id = ?");
        } else {
            echo json_encode(["status" => "error", "message" => "Geçersiz reset türü."]);
            exit;
        }
        $stmt->bind_param("ii", $flight_id, $process_id);
        $stmt->execute();
case "reset":
    $new_status = null;

    if ($reset_target === "start") {
        $stmt = $conn->prepare("UPDATE flight_processes SET value_enum = NULL, start_time = NULL WHERE flight_id = ? AND process_type_id = ?");
        $new_status = "not_started";
    } elseif ($reset_target === "finish") {
        $stmt = $conn->prepare("UPDATE flight_processes SET value_enum = 'started', finish_time = NULL WHERE flight_id = ? AND process_type_id = ?");
        $new_status = "started";
    } elseif ($reset_target === "not_used") {
        $stmt = $conn->prepare("UPDATE flight_processes SET value_enum = NULL WHERE flight_id = ? AND process_type_id = ?");
        $new_status = "not_started";
    } else {
        echo json_encode(["status" => "error", "message" => "Geçersiz reset türü."]);
        exit;
    }

    $stmt->bind_param("ii", $flight_id, $process_id);
    $stmt->execute();

    echo json_encode([
        "status" => "success",
        "message" => "Alan sıfırlandı",
        "new_status" => $new_status
    ]);
    break;


    case "save_text_input":
        $stmt = $conn->prepare("REPLACE INTO flight_processes (flight_id, process_type_id, value_text) VALUES (?, ?, ?)");
        $stmt->bind_param("iis", $flight_id, $process_id, $value);
        $stmt->execute();
        echo json_encode(["status" => "success", "message" => "Metin kaydedildi"]);
        break;

    default:
        echo json_encode(["status" => "error", "message" => "Geçersiz işlem."]);
        break;
}
