<?php
require_once __DIR__ . '/../beta_debug.php';
require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Yeni Excel dosyası oluştur
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Hücrelere veri yaz
$sheet->setCellValue('A1', 'Sefer No');
$sheet->setCellValue('B1', 'Tarih');
$sheet->setCellValue('A2', 'SF1234');
$sheet->setCellValue('B2', date('Y-m-d'));

// Dosya adını oluştur
$filename = 'sefer_raporu_' . date('Ymd_His') . '.xlsx';

// Sunucuya kaydet
$writer = new Xlsx($spreadsheet);
$writer->save($filename);

echo "✅ Excel dosyası oluşturuldu: <strong>$filename</strong>";
?>
