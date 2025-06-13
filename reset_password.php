<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
date_default_timezone_set('Europe/Istanbul');
require_once 'db.php';
require_once dirname(__DIR__) . '/phpmailer/src/PHPMailer.php';
require_once dirname(__DIR__) . '/phpmailer/src/SMTP.php';
require_once dirname(__DIR__) . '/phpmailer/src/Exception.php';
require_once dirname(__DIR__) . '/phpmailer/mail_config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$message = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_GET['token'])) {
        // Yeni şifre belirleme
        $token = $_GET['token'];
        $new_password = trim($_POST['password'] ?? '');

        if ($new_password === '') {
            $message = "Şifre boş bırakılamaz.";
            $success = false;
        } else {
            $stmt = $conn->prepare("SELECT id FROM users WHERE reset_token = ? AND reset_expires > NOW()");
            $stmt->bind_param("s", $token);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 1) {
                $hashedPassword = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL WHERE reset_token = ?");
                $stmt->bind_param("ss", $hashedPassword, $token);
                $stmt->execute();

                $message = "Şifreniz başarıyla güncellendi.";
                $success = true;
            } else {
                $message = "Geçersiz veya süresi dolmuş bağlantı.";
                $success = false;
            }
        }
    } else {
        // Şifre sıfırlama bağlantısı gönderme
        $email = trim($_POST['email'] ?? '');

        if ($email === '') {
            $message = "E-posta boş bırakılamaz.";
            $success = false;
        } else {
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows > 0) {
                $token = bin2hex(random_bytes(16));
                $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

                $stmt = $conn->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE email = ?");
                $stmt->bind_param("sss", $token, $expires, $email);
                $stmt->execute();

                $mail = new PHPMailer(true);
                $mail->CharSet = 'UTF-8';
                $mail->Encoding = 'base64';

                try {
                    $mail->isSMTP();
                    $mail->Host = SMTP_HOST;
                    $mail->SMTPAuth = true;
                    $mail->Username = SMTP_USERNAME;
                    $mail->Password = SMTP_PASSWORD;
                    $mail->SMTPSecure = SMTP_SECURE;
                    $mail->Port = SMTP_PORT;

                    $mail->SMTPOptions = [
                        'ssl' => [
                            'verify_peer' => false,
                            'verify_peer_name' => false,
                            'allow_self_signed' => true
                        ]
                    ];

                    $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
                    $mail->addAddress($email);

                    $mail->isHTML(true);
                    $mail->Subject = 'Şifre Sıfırlama Bağlantısı';
                    $reset_link = "https://yulcaribe.com/reset_password.php?token=" . urlencode($token);
                    $mail->Body = "Şifrenizi sıfırlamak için <a href='$reset_link'>buraya tıklayın</a>. Bu bağlantı 1 saat geçerlidir.";

                    $mail->send();

                    $message = "Şifre sıfırlama bağlantısı e-posta adresinize gönderildi.";
                    $success = true;
                } catch (Exception $e) {
                    $message = "E-posta gönderilemedi. Lütfen tekrar deneyin.";
                    $success = false;
                }
            } else {
                $message = "Bu e-posta adresi sistemde kayıtlı değil.";
                $success = false;
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="tr" class="h-100">
<head>
  <meta charset="utf-8" />
  <title>Şifre Sıfırlama | Operation</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link href="assets/css/vendor.min.css" rel="stylesheet" />
  <link href="assets/css/icons.min.css" rel="stylesheet" />
  <link href="assets/css/app.min.css" rel="stylesheet" />
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body class="h-100">
  <div class="d-flex flex-column h-100 justify-content-center align-items-center p-3">
    <div class="col-md-4 col-sm-8 col-10">
      <h2 class="text-center mb-4">
        <?= isset($_GET['token']) ? 'Yeni Şifre Belirle' : 'Şifre Sıfırlama' ?>
      </h2>

      <form method="POST">
        <?php if (isset($_GET['token'])): ?>
          <div class="mb-3">
            <label for="password" class="form-label">Yeni Şifre</label>
            <input type="password" name="password" id="password" class="form-control" required>
          </div>
        <?php else: ?>
          <div class="mb-3">
            <label for="email" class="form-label">E-posta Adresiniz</label>
            <input type="email" name="email" id="email" class="form-control" required>
          </div>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary w-100">
          <?= isset($_GET['token']) ? 'Şifreyi Güncelle' : 'Gönder' ?>
        </button>
      </form>
      <p class="mt-3 text-center"><a href="login.php">Giriş Ekranına Dön</a></p>
    </div>
  </div>

  <?php if ($message !== null): ?>
    <script>
      Swal.fire({
        icon: "<?= $success ? 'success' : 'error' ?>",
        title: "<?= $success ? 'Başarılı' : 'Hata' ?>",
        text: "<?= $message ?>"
      });
    </script>
    
  <?php endif; ?>
</body>
</html>
