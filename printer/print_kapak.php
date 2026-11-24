<?php
require_once __DIR__ . '/../beta_debug.php';
ini_set('session.cookie_domain', '.yulcaribe.com');
session_start();

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

if (!isset($_SESSION['user_id'])) {
    die("Giriş yapılmamış.");
}

$user_id = $_SESSION['user_id'];

// Kullanıcı bilgilerini çek
$stmtUser = $conn->prepare("SELECT first_name, last_name, tgs_sicil_no, pozisyon FROM users WHERE id = ?");
$stmtUser->bind_param("i", $user_id);
$stmtUser->execute();
$resultUser = $stmtUser->get_result();
$users = $resultUser->fetch_assoc();

// Uçuş bilgilerini çek
$stmt = $conn->prepare("SELECT * FROM flights WHERE user_id = ? AND status = 'active' ORDER BY created_at DESC LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("Aktif sefer bulunamadı.");
}

$flight = $result->fetch_assoc();

// Excel şablonunu yükle
$templateFile = __DIR__ . '/kapak.xlsx';
$spreadsheet = IOFactory::load($templateFile);
$sheet = $spreadsheet->getSheetByName('Sayfa1');
if (!$sheet) die("Sayfa bulunamadı: Sayfa1");

// Uçuş bilgilerini yaz
$sheet->setCellValue('F9', $flight['icao_code'] ?? '');
$sheet->setCellValue('P9', $flight['company_name'] ?? '');
$sheet->setCellValue('C22', $flight['tail_number'] ?? '');
$sheet->setCellValue('C11', $flight['nationality'] ?? '');
$sheet->setCellValue('F10', ($flight['representative_code'] ?? '') . ($flight['representative'] ?? ''));
$sheet->setCellValue('O12', ($flight['iata_code'] ?? '') . ($flight['arrival_flight_number'] ?? ''));
$sheet->setCellValue('V12', ($flight['iata_code'] ?? '') . ($flight['departure_flight_number'] ?? ''));
$sheet->setCellValue('W23', $flight['arrivaldest'] ?? '');
$sheet->setCellValue('P24', $flight['departuredest'] ?? '');
$sheet->setCellValue('P25', $flight['parkposition'] ?? '');
$sheet->setCellValue('W25', $flight['parkposition'] ?? '');

// Kullanıcı bilgileri
$sheet->setCellValue('C55', ($users['first_name'] ?? '') . ' ' . ($users['last_name'] ?? '') . ' ' . ($users['tgs_sicil_no'] ?? ''));
$sheet->setCellValue('C57', $users['pozisyon'] ?? '');

// Arrival tarih ve saat ayrıştırması
if (!empty($flight['arrival_date_time'])) {
    $arrivalDateTime = new DateTime($flight['arrival_date_time']);
    $sheet->setCellValue('C8', $arrivalDateTime->format('d/m/Y'));
    $sheet->setCellValue('O16', $arrivalDateTime->format('d/m/Y'));
    $sheet->setCellValue('O19', $arrivalDateTime->format('H:i'));
}

// Departure tarih ve saat ayrıştırması
if (!empty($flight['departure_date_time'])) {
    $departureDateTime = new DateTime($flight['departure_date_time']);
    $sheet->setCellValue('V16', $departureDateTime->format('d/m/Y'));
    $sheet->setCellValue('V19', $departureDateTime->format('H:i'));
}

// Excel dosyasını çıktı olarak gönder
if (ob_get_length()) ob_end_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="kapak_doldurulmus.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;