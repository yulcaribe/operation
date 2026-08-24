<?php
$servername = "localhost";
$username = "yulcari1_ops";
$password = "37725292Ops..";
$dbname = "yulcari1_ops";

// MySQL bağlantısını oluştur
mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli($servername, $username, $password, $dbname);

// Bağlantıyı kontrol et
if ($conn->connect_error) {
    throw new DatabaseException('Veritabanı bağlantısı kurulamadı.');
}

// Türkiye saat dilimini ayarla (MySQL için)
$conn->query("SET time_zone = '+03:00'");
?>
