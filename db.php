<?php
$servername = "localhost";
$username = "yulcari1_ops";
$password = "37725292Ops.";
$dbname = "yulcari1_ops";

// MySQL bağlantısını oluştur
$conn = new mysqli($servername, $username, $password, $dbname);

// Bağlantıyı kontrol et
if ($conn->connect_error) {
    die("Bağlantı hatası: " . $conn->connect_error);
}

// Türkçe karakterler için charset ayarı
$conn->set_charset("utf8mb4");
?>
