<?php
$servername = "localhost";
$username = "yulcarib_ops";
$password = "37725292Ops..";
$dbname = "yulcarib_ops";

// MySQL bağlantısını oluştur
$conn = new mysqli($servername, $username, $password, $dbname);

// Bağlantıyı kontrol et
if ($conn->connect_error) {
    die("Bağlantı hatası: " . $conn->connect_error);
}

// Türkiye saat dilimini ayarla (MySQL için)
$conn->query("SET time_zone = '+03:00'");
?>
