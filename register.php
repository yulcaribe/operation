<?php
require_once __DIR__ . '/beta_debug.php';
session_start();
require_once 'db.php';
require_once __DIR__ . '/../phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../phpmailer/src/SMTP.php';
require_once __DIR__ . '/../phpmailer/src/Exception.php';
require_once __DIR__ . '/../phpmailer/mail_config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$register_message = null;
$register_success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if ($username === '' || $password === '' || $email === '') {
        $register_message = "Kullanıcı adı, şifre ve e-posta boş olamaz.";
        $register_success = false;
    } else {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $register_message = "Bu e-posta adresi zaten kullanılıyor.";
            $register_success = false;
        } else {
            $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) {
                $register_message = "Bu kullanıcı adı zaten alınmış.";
                $register_success = false;
            } else {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $activation_code = md5(uniqid());
                $mail_activation = 0;
                $admin_approval = 0;

                $stmt = $conn->prepare("INSERT INTO users (username, email, password, activation_code, mail_activation, admin_approval) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssssii", $username, $email, $hashedPassword, $activation_code, $mail_activation, $admin_approval);

                if ($stmt->execute()) {
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
                        $mail->addAddress($email, $username);

                        $mail->isHTML(true);
                        $mail->Subject = 'Hesabınızı Aktifleştirin';
                        $activation_link = "https://yulcaribe.com/activate.php?email=" . urlencode($email) . "&code=" . $activation_code;
                        $mail->Body = "Merhaba $username,<br><br>Hesabınızı aktifleştirmek için <a href='$activation_link'>buraya tıklayın</a>.";

                        $mail->send();

                        $register_message = "Kayıt başarılı! Aktivasyon e-postası gönderildi.";
                        $register_success = true;
                    } catch (Exception $e) {
                        $register_message = "Kayıt başarılı fakat aktivasyon maili gönderilemedi.";
                        $register_success = false;
                    }
                } else {
                    $register_message = "Kayıt başarısız: Bir hata oluştu.";
                    $register_success = false;
                }
            }
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
        text: "<?= $register_message ?>"
      });
    </script>
  <?php endif; ?>
</body>
</html>