<?php
require_once __DIR__ . '/../beta_debug.php';
ini_set('session.cookie_domain', '.yulcaribe.com');
session_start();

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\RichText\RichText;

// Login kontrol
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

// En son aktif uçuşu al
$stmt = $conn->prepare("SELECT * FROM flights WHERE user_id = ? AND status = 'active' ORDER BY created_at DESC LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("Aktif sefer bulunamadı.");
}

$flight = $result->fetch_assoc();

// Excel şablonunu yükle
$templateFile = __DIR__ . '/gumruk_dilekce.xlsx';
$spreadsheet = IOFactory::load($templateFile);
$sheet = $spreadsheet->getActiveSheet();

// Uçuş türü
$flightType = $flight['flight_type'] ?? '';


// -------------------------------
//  ÜST BLOK → ARRIVAL
// -------------------------------
if (in_array($flightType, ['arrival', 'turnaround'])) {
    $rich = new RichText();

    // Firma ismi (bold, 18 pt)
    $boldCompany = $rich->createTextRun(($flight['company_name'] ?? '') . ' ');
    $boldCompany->getFont()->setBold(true)->setSize(18);

    // "geliş" kelimesi (bold, 18 pt)
    $boldWord = $rich->createTextRun('geliş');
    $boldWord->getFont()->setBold(true)->setSize(18);

    // Kalan metin (normal, 18 pt)
    $normalText = $rich->createTextRun(' uçağının gümrük işlemleri hk.');
    $normalText->getFont()->setSize(18);

    $sheet->getCell('C10')->setValue($rich);
}


// -------------------------------
//  ALT BLOK → DEPARTURE
// -------------------------------
if (in_array($flightType, ['departure', 'turnaround'])) {
    $rich = new RichText();

    // Firma ismi (bold, 18 pt)
    $boldCompany = $rich->createTextRun(($flight['company_name'] ?? '') . ' ');
    $boldCompany->getFont()->setBold(true)->setSize(18);

    // "gidiş" kelimesi (bold, 18 pt)
    $boldWord = $rich->createTextRun('gidiş');
    $boldWord->getFont()->setBold(true)->setSize(18);

    // Kalan metin (normal, 18 pt)
    $normalText = $rich->createTextRun(' uçağının gümrük işlemleri hk.');
    $normalText->getFont()->setSize(18);

    $sheet->getCell('C32')->setValue($rich);
}



// ARRIVAL DILEKCE

if (in_array($flightType, ['arrival', 'turnaround']) && !empty($flight['arrival_date_time'])) {
    $arrivalDateTime = new DateTime($flight['arrival_date_time']);

    // 23:30 sonrası ertesi gün
    if ((int)$arrivalDateTime->format('H') >= 23 && (int)$arrivalDateTime->format('i') >= 30) {
        $arrivalDateTime->modify('+1 day');
    }

    $formattedDate = $arrivalDateTime->format('d / m / Y');
    $flightNumber = ($flight['iata_code'] ?? '') . ($flight['arrival_flight_number'] ?? '');
    $tail = $flight['tail_number'] ?? '';
    $arrivalDest = $flight['arrivaldest'] ?? '';

    $rich = new RichText();

    // Bold: tarih
    $rich->createTextRun($formattedDate)->getFont()->setBold(true)->setSize(18);

    // Normal
    $rich->createTextRun(' tarihinde ')->getFont()->setSize(18);

    // Bold: sefer no
    $rich->createTextRun($flightNumber)->getFont()->setBold(true)->setSize(18);

    // Normal
    $rich->createTextRun(' sefer sayılı ')->getFont()->setSize(18);

    // Bold: tail number
    $rich->createTextRun($tail)->getFont()->setBold(true)->setSize(18);

    // Normal
    $rich->createTextRun(' isimli uçak saat              da ')->getFont()->setSize(18);

    // Bold: arrivaldest + GELECEKTİR
    $rich->createTextRun($arrivalDest . ' GELECEKTİR.')->getFont()->setBold(true)->setSize(18);

    // Normal: kapanış
    $rich->createTextRun(' Yer hizmetleri tarafımızdan yapılacaktır. Gümrük işlemlerinin tarafınızdan yapılması için gereğini,')->getFont()->setSize(18);

    $sheet->getCell('b14')->setValue($rich);
}



//DEPARTURE DILEKCE
// DEPARTURE DILEKCE

if (in_array($flightType, ['departure', 'turnaround']) && !empty($flight['departure_date_time'])) {
    $departureDateTime = new DateTime($flight['departure_date_time']);

    // 23:30 sonrası ertesi gün
    if ((int)$departureDateTime->format('H') >= 23 && (int)$departureDateTime->format('i') >= 30) {
        $departureDateTime->modify('+1 day');
    }

    $formattedDate = $departureDateTime->format('d / m / Y');
    $flightNumber = ($flight['iata_code'] ?? '') . ($flight['departure_flight_number'] ?? '');
    $tail = $flight['tail_number'] ?? '';
    $departureDest = $flight['departuredest'] ?? '';

    $rich = new RichText();

    // Bold: tarih
    $rich->createTextRun($formattedDate)->getFont()->setBold(true)->setSize(18);

    // Normal
    $rich->createTextRun(' tarihinde ')->getFont()->setSize(18);

    // Bold: sefer no
    $rich->createTextRun($flightNumber)->getFont()->setBold(true)->setSize(18);

    // Normal
    $rich->createTextRun(' sefer sayılı ')->getFont()->setSize(18);

    // Bold: tail number
    $rich->createTextRun($tail)->getFont()->setBold(true)->setSize(18);

    // Normal
    $rich->createTextRun(' isimli uçak saat              da ')->getFont()->setSize(18);

    // Bold: destinasyon + GİDECEKTİR
    $rich->createTextRun($departureDest . ' GİDECEKTİR.')->getFont()->setBold(true)->setSize(18);

    // Normal: kapanış
    $rich->createTextRun(' Yer hizmetleri tarafımızdan yapılacaktır. Gümrük işlemlerinin tarafınızdan yapılması için gereğini,')->getFont()->setSize(18);

    $sheet->getCell('B36')->setValue($rich);
}




// ARRIVAL - Kullanıcı Bilgileri
if (in_array($flightType, ['arrival', 'turnaround'])) {
    $fullName = ($users['first_name'] ?? '') . ' ' . ($users['last_name'] ?? '') . ' ' . ($users['tgs_sicil_no'] ?? '');
    $position = $users['pozisyon'] ?? '';

    $sheet->setCellValue('J19', $fullName);
    $sheet->setCellValue('J20', $position);
}

// DEPARTURE - Kullanıcı Bilgileri
if (in_array($flightType, ['departure', 'turnaround'])) {
    $fullName = ($users['first_name'] ?? '') . ' ' . ($users['last_name'] ?? '') . ' ' . ($users['tgs_sicil_no'] ?? '');
    $position = $users['pozisyon'] ?? '';

    $sheet->setCellValue('J41', $fullName);
    $sheet->setCellValue('J42', $position);
}



// -------------------------------
//  DOSYA ÇIKTI
// -------------------------------
if (ob_get_length()) ob_end_clean();
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="gumruk_dilekce_doldurulmus.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
