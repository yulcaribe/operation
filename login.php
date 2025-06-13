<?php
session_start();
require_once 'db.php';

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    $stmt = $conn->prepare("SELECT id, password, mail_activation, admin_approval FROM users WHERE username = ?");
    if (!$stmt) {
        die("Prepare failed: " . $conn->error);
    }

    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();

        if (!password_verify($password, $user['password'])) {
            echo "⚠️ Hatalı şifre.";
        } elseif ((int)$user['mail_activation'] !== 1) {
            echo "📧 E-posta adresinizi doğrulamanız gerekiyor.";
        } elseif ((int)$user['admin_approval'] !== 1) {
            echo "⏳ Hesabınız henüz admin tarafından onaylanmadı.";
        } else {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $username;

            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }

            header("Location: dashboard.php");
            exit();
        }
    } else {
        echo "⚠️ Kullanıcı bulunamadı.";
    }
}
?>

<!DOCTYPE html>
<html lang="tr" class="h-100">
<head>
  <meta charset="utf-8" />
  <title>Giriş Yap | Operation</title>
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
          <h2 class="fw-bold fs-24">Giriş Yap</h2>
        </div>

        <form action="login.php" method="POST">
          <div class="mb-3">
            <label class="form-label" for="username">Kullanıcı Adı</label>
            <input type="text" id="username" name="username" class="form-control" placeholder="Kullanıcı adınızı girin" required>
          </div>

          <div class="mb-3">
            <label class="form-label" for="password">Şifre</label>
            <input type="password" id="password" name="password" class="form-control" placeholder="Şifrenizi girin" required>
          </div>

          <div class="d-grid mb-3">
            <button class="btn btn-primary" type="submit">Giriş Yap</button>
          </div>
        </form>

        <p class="text-center">Hesabınız yok mu? 
          <a href="register.html" class="fw-bold text-decoration-underline">Üye Ol</a>
        </p>
      </div>
    </div>
  </div>

  <!-- JS Dosyaları -->
  <script src="assets/js/vendor.js"></script>
  <script src="assets/js/app.js"></script>
</body>
</html>
