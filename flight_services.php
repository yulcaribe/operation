<?php
require_once __DIR__ . '/beta_debug.php';
if (session_status() === PHP_SESSION_NONE) session_start();
require_once "db.php";
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
  header("Location: login.php");
  exit();
}
$stmt = $conn->prepare("SELECT * FROM flights WHERE user_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$flight = $stmt->get_result()->fetch_assoc();
if (!$flight) {
  die("Aktif uçuş bulunamadı.");
}
$hizmetler = [
  'wheelchair' => 'Wheelchair',
  'cobus'      => 'Cobus',
  'followme'   => 'Follow‑Me',
  'stairs'     => 'Stairs'
];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <title>Hizmet & Evraklar | Operation</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="assets/css/vendor.min.css" rel="stylesheet">
  <link href="assets/css/icons.min.css" rel="stylesheet">
  <link href="assets/css/app.min.css" rel="stylesheet">
  <script src="assets/js/config.js"></script>
</head>
<body>
<header class="topbar">
      <?php include 'topbar.php'; ?>
    </header>

  <div class="container my-4">
    <h4 class="mb-4">
      Aktif Uçuş: <?= htmlspecialchars($flight['iata_code'] . $flight['arrival_flight_number']) ?>
      / <?= htmlspecialchars($flight['iata_code'] . $flight['departure_flight_number']) ?>
      - <?= htmlspecialchars($flight['tail_number']) ?>
    </h4>

    <div class="row gy-3">
      <?php foreach ($hizmetler as $key => $label): ?>
        <div class="col-12 col-sm-6 col-md-4">
          <div class="card h-100">
            <div class="card-body d-flex flex-column justify-content-between">
              <h5 class="card-title"><?= $label ?></h5>
              <div class="mt-3">
                <button class="btn btn-outline-primary w-100 mb-2" onclick="incrementService('<?= $key ?>')">EKLE</button>
                <button class="btn btn-outline-danger w-100" onclick="decrementService('<?= $key ?>')">SİL</button>
              </div>
              <div class="mt-2 text-center">
                <span class="badge bg-secondary" id="<?= $key ?>-count">0</span>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="mt-4">
      <h5>Evraklar</h5>
      <a href="/printer/print_kapak.php" class="btn btn-outline-secondary me-2">Kapak Formu</a>
      <a href="/printer/gumruk_dilekce.php" class="btn btn-outline-secondary me-2">Gümrük Dilekçesi</a>
      <a href="#" class="btn btn-outline-secondary me-2 disabled">Servis Formu (Hazırlanıyor)</a>
    </div>
  </div>

  <script>
    const counters = { wheelchair:0, cobus:0, followme:0, stairs:0 };
    function incrementService(type) {
      counters[type] += 1;
      document.getElementById(type+'-count').innerText = counters[type];
    }
    function decrementService(type) {
      if (counters[type] > 0) {
        counters[type] -= 1;
        document.getElementById(type+'-count').innerText = counters[type];
      }
    }
  </script>

  <script src="assets/js/vendor.js"></script>
  <script src="assets/js/app.js"></script>
</body>
</html>
