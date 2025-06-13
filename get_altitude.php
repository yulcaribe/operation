<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

$userId = $_SESSION['user_id'];
require_once 'db.php';

// Aktif uçuşu çek
$sql = "SELECT tail_number FROM flights WHERE user_id = ? AND status = 'active' LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $userId);
$stmt->execute();
$stmt->bind_result($tailNumber);
$stmt->fetch();
$stmt->close();

if (!$tailNumber) {
    echo json_encode(["error" => "No active flight found"]);
    exit;
}

// API'den veri al
$url = "https://opendata.adsb.fi/api/v2/registration/" . urlencode($tailNumber);
$response = @file_get_contents($url);

if (!$response) {
    echo json_encode(["error" => "API request failed"]);
    exit;
}

$data = json_decode($response, true);

if (!isset($data['ac'][0])) {
    echo json_encode(["error" => "Aircraft data not found"]);
    exit;
}

$ac = $data['ac'][0];

// Verileri JSON olarak döndür
echo json_encode([
    "tail_number" => $tailNumber,
    "altitude_ft" => $ac['alt_baro'] ?? null,
    "latitude" => $ac['lat'] ?? null,
    "longitude" => $ac['lon'] ?? null,
    "heading" => $ac['track'] ?? ($ac['true_heading'] ?? ($ac['mag_heading'] ?? null))
]);
?>