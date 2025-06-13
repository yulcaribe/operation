<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$username = htmlspecialchars($_SESSION['username'] ?? '');

// Aktif uçuş kontrolü
$stmt = $conn->prepare("SELECT id FROM flights WHERE user_id = ? AND status = 'active' LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$has_active_flight = ($result->num_rows > 0);
$redirect_url = $has_active_flight ? "active_flight.php" : "add_flight.php";

// Mesajlar
if (isset($_GET['completed']) && $_GET['completed'] == '1') {
    $main_message = "Uçuş başarıyla tamamlanmıştır.";
    $sub_message = "Geçmiş uçuşlarım sayfasından görebilirsiniz. Şimdi yeni uçuş ekleme sayfasına yönlendiriliyorsunuz.";
    $redirect_url = "add_flight.php";
} else {
    $main_message = $has_active_flight ? "Aktif uçuşunuz bulunmaktadır." : "Aktif uçuşunuz bulunmamaktadır.";
    $sub_message = $has_active_flight ? "Aktif uçuş sayfasına yönlendiriliyorsunuz..." : "Yeni bir uçuş eklemek için yönlendiriliyorsunuz...";
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="utf-8" />
  <title>Yönlendiriliyorsunuz... | Operation</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link href="assets/css/vendor.min.css" rel="stylesheet" />
  <link href="assets/css/icons.min.css" rel="stylesheet" />
  <link href="assets/css/app.min.css" rel="stylesheet" />
  <meta http-equiv="refresh" content="2;url=<?php echo $redirect_url; ?>">
</head>
<body class="d-flex justify-content-center align-items-center vh-100">
  <div class="text-center">
    <div class="spinner-border text-primary mb-4" role="status">
      <span class="visually-hidden">Yükleniyor...</span>
    </div>
    <h2 class="fw-semibold"><?php echo $main_message; ?></h2>
    <p class="text-muted"><?php echo $sub_message; ?></p>
    <small class="text-muted">Eğer otomatik yönlendirme gerçekleşmezse <a href="<?php echo $redirect_url; ?>">buraya tıklayın</a>.</small>
  </div>
  <script src="assets/js/vendor.js"></script>
  <script src="assets/js/app.js"></script>
</body>
</html>
