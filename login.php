<?php
// Geçici login hata kaydi: site disinda, sifre kaydetmeden.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', '/home/yulcari1/operation-login-errors.log');
error_reporting(E_ALL);

set_exception_handler(static function (Throwable $e): void {
    error_log(sprintf('[Operation login][EXCEPTION] %s: %s (%s:%d)', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
    http_response_code(500);
    // Gecici tanilama: PHP hatasini F12 > Console'da goster.
    $debug = [
        'type' => get_class($e),
        'message' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
        'code' => $e->getCode(),
    ];
    $json = json_encode($debug, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
    $safe = htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="tr"><head><meta charset="UTF-8"><title>Login PHP Hatasi</title></head><body>';
    echo '<h3>Login PHP hatasi (gecici debug)</h3><pre>' . $safe . '</pre>';
    echo '<p>F12 &gt; Console bolumunde tur, dosya ve satir numarasina bak.</p>';
    echo '<script>console.error("Operation login PHP hatasi:", ' . $json . ');</script>';
    echo '</body></html>';
});

register_shutdown_function(static function (): void {
    $lastError = error_get_last();
    if ($lastError !== null && in_array($lastError['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log(sprintf('[Operation login][FATAL] %s (%s:%d)', $lastError['message'], $lastError['file'], $lastError['line']));
    }
});

error_log('[Operation login] Request started; method=' . ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN'));

session_start();
require_once 'db.php';

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    $stmt = $conn->prepare("SELECT id, password, status FROM users WHERE username = ? AND deleted_at IS NULL");
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
        } elseif ($user['status'] !== 'active') {
            echo "⚠️ Hesabınız aktif değil.";
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
          <a href="register.php" class="fw-bold text-decoration-underline">Üye Ol</a>
        </p>
      </div>
    </div>
  </div>

  <!-- JS Dosyaları -->
  <script src="assets/js/vendor.js"></script>
  <script src="assets/js/app.js"></script>
</body>
</html>
