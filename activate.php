<?php
require_once 'db.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$activation_success = false;
$activation_message = "";

if (isset($_GET['email'], $_GET['code'])) {
    $email = $_GET['email'];
    $code = $_GET['code'];

    // Kullanıcıyı kontrol et
    $stmt = $conn->prepare("SELECT * FROM users WHERE email = ? AND activation_code = ? AND mail_activation = 0");
    $stmt->bind_param("ss", $email, $code);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        // Aktifleştir
        $update = $conn->prepare("UPDATE users SET mail_activation = 1, admin_approval = 1 WHERE email = ?");
        $update->bind_param("s", $email);
        $update->execute();

        $activation_success = true;
        $activation_message = "Hesabınız başarıyla aktifleştirildi.";
    } else {
        $activation_message = "Aktivasyon başarısız veya zaten yapılmış.";
    }
} else {
    $activation_message = "Geçersiz istek.";
}
?>

<!DOCTYPE html>
<html lang="tr" class="h-100">
<head>
  <meta charset="utf-8" />
  <title>Aktivasyon Sonucu | Operation</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />

  <!-- Stil Dosyaları -->
  <link href="assets/css/vendor.min.css" rel="stylesheet" type="text/css" />
  <link href="assets/css/icons.min.css" rel="stylesheet" type="text/css" />
  <link href="assets/css/app.min.css" rel="stylesheet" type="text/css" />
  <script src="assets/js/config.js"></script>
</head>

<body class="h-100">
  <div class="d-flex flex-column h-100 p-3">
    <div class="d-flex flex-column flex-grow-1 justify-content-center align-items-center">
      <div class="col-md-4 col-sm-8 col-10">
        <div class="text-center mb-4">
          <h2 class="fw-bold fs-24 <?= $activation_success ? 'text-success' : 'text-danger' ?>">
            <?= htmlspecialchars($activation_message) ?>
          </h2>
        </div>

        <div class="d-grid">
          <a href="login.php" class="btn btn-primary">Giriş Yap</a>
        </div>
      </div>
    </div>
  </div>

  <!-- JS Dosyaları -->
  <script src="assets/js/vendor.js"></script>
  <script src="assets/js/app.js"></script>
</body>
</html>