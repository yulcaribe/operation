<?php
require_once __DIR__ . '/beta_debug.php';
session_start();
require_once __DIR__ . '/db.php';
$register_message = null;
$register_success = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if ($username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
        $register_message = 'Kullanıcı adı, geçerli e-posta ve en az 8 karakterli şifre gerekli.';
    } else {
        try {
            $stmt = $conn->prepare("INSERT INTO users (username, email, password, first_name, last_name, status, must_change_password) VALUES (?, ?, ?, ?, '', 'active', 0)");
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt->bind_param('ssss', $username, $email, $hash, $username);
            $stmt->execute();
            $register_success = true;
            $register_message = 'Hesabınız oluşturuldu. Giriş yapabilirsiniz.';
        } catch (mysqli_sql_exception $e) {
            $register_message = (int)$e->getCode() === 1062 ? 'Kullanıcı adı veya e-posta zaten kayıtlı.' : 'Kayıt sırasında hata oluştu.';
            error_log('[Operation BETA] register: ' . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="tr" class="h-100">
<head>
  <meta charset="utf-8" />
  <title>Kayıt Ol | Operation</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <link href="assets/css/vendor.min.css" rel="stylesheet" type="text/css" />
  <link href="assets/css/icons.min.css" rel="stylesheet" type="text/css" />
  <link href="assets/css/app.min.css" rel="stylesheet" type="text/css" />
  <script src="assets/js/config.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>

<body class="h-100">
  <div class="d-flex flex-column h-100 justify-content-center align-items-center p-3">
    <div class="col-md-4 col-sm-8 col-10">
      <h2 class="text-center mb-4">Kayıt Ol</h2>

      <form action="" method="POST">
        <div class="mb-3">
          <label for="username" class="form-label">Kullanıcı Adı</label>
          <input type="text" name="username" id="username" class="form-control" required>
        </div>
        <div class="mb-3">
          <label for="email" class="form-label">E-posta</label>
          <input type="email" name="email" id="email" class="form-control" required>
        </div>
        <div class="mb-3">
          <label for="password" class="form-label">Şifre</label>
          <input type="password" name="password" id="password" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-primary w-100">Kayıt Ol</button>
      </form>
      <p class="mt-3 text-center">Zaten hesabınız var mı? <a href="login.php">Giriş Yap</a></p>
    </div>
  </div>

  <?php if ($register_message !== null): ?>
    <script>
      Swal.fire({
        icon: "<?= $register_success ? 'success' : 'error' ?>",
        title: "<?= $register_success ? 'Başarılı' : 'Hata' ?>",
        text: <?= json_encode($register_message, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
      });
    </script>
  <?php endif; ?>
</body>
</html>