<?php
require_once __DIR__ . '/beta_debug.php';
session_start();
require_once __DIR__ . '/db.php';
$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!$user_id) { header('Location: login.php'); exit; }
$stmt = $conn->prepare("SELECT id FROM users WHERE id = ? AND status = 'active' AND deleted_at IS NULL");
$stmt->bind_param('i', $user_id);
$stmt->execute();
if (!$stmt->get_result()->fetch_assoc()) { session_destroy(); header('Location: login.php'); exit; }
$stmt = $conn->prepare("SELECT id, company_name, flight_type, arrival_flight_number, departure_flight_number, tail_number, stand, scheduled_arrival_at, scheduled_departure_at FROM flights WHERE user_id = ? AND status = 'active' AND deleted_at IS NULL ORDER BY created_at DESC, id DESC");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$flights = $stmt->get_result();
?>
<!DOCTYPE html><html lang="tr"><head><meta charset="UTF-8"><title>Uçuşlarım | Operation</title>
<meta name="viewport" content="width=device-width, initial-scale=1"><link rel="shortcut icon" href="assets/images/favicon.ico">
<link href="assets/css/vendor.min.css" rel="stylesheet"><link href="assets/css/icons.min.css" rel="stylesheet">
<link href="assets/css/app.min.css" rel="stylesheet"><script src="assets/js/config.js"></script></head>
<body><header class="topbar"><?php include 'topbar.php'; ?></header>
<div class="page-content"><div class="container-xl">
<div class="d-flex justify-content-between align-items-center mb-3">
<h4 class="mb-0">Aktif Uçuşlarım</h4><a href="add_flight.php" class="btn btn-primary"><i class="bx bx-plus"></i> Yeni Uçuş</a></div>
<?php if (isset($_GET['completed'])): ?><div class="alert alert-success">Uçuş tamamlandı.</div><?php endif; ?>
<?php if ($flights->num_rows === 0): ?>
<div class="card"><div class="card-body text-center py-5"><h5>Henüz aktif uçuşunuz yok.</h5>
<a href="add_flight.php" class="btn btn-success mt-2">Uçuş Ekle</a></div></div>
<?php else: ?><div class="row"><?php while ($f = $flights->fetch_assoc()): ?>
<div class="col-md-6 col-lg-4 mb-3"><div class="card h-100"><div class="card-body">
<h5><?= htmlspecialchars($f['company_name'] ?: 'Uçuş', ENT_QUOTES, 'UTF-8') ?></h5>
<p class="text-muted mb-2"><?= htmlspecialchars(strtoupper($f['flight_type']), ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($f['tail_number'] ?: '-', ENT_QUOTES, 'UTF-8') ?></p>
<p class="mb-1">Arrival: <?= htmlspecialchars($f['arrival_flight_number'] ?: '-', ENT_QUOTES, 'UTF-8') ?></p>
<p class="mb-1">Departure: <?= htmlspecialchars($f['departure_flight_number'] ?: '-', ENT_QUOTES, 'UTF-8') ?></p>
<p class="mb-3">Park: <?= htmlspecialchars($f['stand'] ?: '-', ENT_QUOTES, 'UTF-8') ?></p>
<a href="active_flight.php?flight_id=<?= (int)$f['id'] ?>" class="btn btn-outline-primary w-100">Uçuşu Aç</a>
</div></div></div><?php endwhile; ?></div><?php endif; ?>
<a href="flight_history.php" class="btn btn-outline-secondary">Uçuş Geçmişim</a>
</div></div><script src="assets/js/vendor.js"></script><script src="assets/js/app.js"></script>
</body></html>
