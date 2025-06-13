<?php
session_start();
require_once 'db.php';
require_once '/home/yulcarib/phpmailer/src/PHPMailer.php';
require_once '/home/yulcarib/phpmailer/src/SMTP.php';
require_once '/home/yulcarib/phpmailer/src/Exception.php';
require_once '/home/yulcarib/phpmailer/mail_config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $email = trim($_POST['email']);

    if ($username === '' || $password === '' || $email === '') {
        die("Kullanıcı adı, şifre ve e-posta boş olamaz.");
    }

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $activation_code = md5(uniqid());
    $mail_activation = 0;
    $admin_approval = 0;

    $stmt = $conn->prepare("INSERT INTO users (username, email, password, activation_code, mail_activation, admin_approval) VALUES (?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        die("Veritabanı hatası: " . $conn->error);
    }

    $stmt->bind_param("ssssii", $username, $email, $hashedPassword, $activation_code, $mail_activation, $admin_approval);

    if ($stmt->execute()) {
        $mail = new PHPMailer(true);

        // Karakter seti ve kodlama
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';

        try {
           // $mail->SMTPDebug = 2;
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
            echo "Kayıt başarılı! Aktivasyon e-postası gönderildi.";
        } catch (Exception $e) {
            echo "Kayıt başarılı fakat e-posta gönderilemedi: {$mail->ErrorInfo}";
        }
    } else {
        echo "Kayıt başarısız: Kullanıcı adı veya e-posta zaten kayıtlı olabilir.";
    }
}
?>