<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.html");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['flight_id'])) {
    $flight_id = intval($_POST['flight_id']);
    $user_id = $_SESSION['user_id'];

    // Uçuşu tamamla
    $stmt = $conn->prepare("UPDATE flights SET status = 'completed' WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $flight_id, $user_id);

    if ($stmt->execute()) {
        header("Location: dashboard.php");
        exit();
    } else {
        echo "Bir hata oluştu: " . $stmt->error;
    }
} else {
    echo "Geçersiz istek.";
}
