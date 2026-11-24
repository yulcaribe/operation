<?php
require_once __DIR__ . '/beta_debug.php';
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/operation_functions.php';
$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!$user_id) { header('Location: login.php'); exit; }
$requestedId = (int)($_POST['flight_id'] ?? $_GET['flight_id'] ?? $_SESSION['active_flight_id'] ?? 0);
$check = $conn->prepare("SELECT id FROM users WHERE id = ? AND status = 'active' AND deleted_at IS NULL");
$check->bind_param('i', $user_id);
$check->execute();
if (!$check->get_result()->fetch_assoc()) { session_destroy(); header('Location: login.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $requestedId > 0) {
    $stmt = $conn->prepare("UPDATE flights SET status = 'completed' WHERE id = ? AND user_id = ? AND status = 'active' AND deleted_at IS NULL");
    $stmt->bind_param('ii', $requestedId, $user_id);
    $stmt->execute();
    unset($_SESSION['active_flight_id']);
    header('Location: dashboard.php?completed=1');
    exit;
}
$flight = op_current_flight($conn, $user_id, $requestedId);
if (!$flight) { unset($_SESSION['active_flight_id']); header('Location: dashboard.php'); exit; }
$_SESSION['active_flight_id'] = (int)$flight['id'];
$flight = op_legacy_flight($conn, $flight);
$flight_type = strtoupper($flight['flight_type']);
$icao_code = htmlspecialchars($flight['icao_code'] ?: '-', ENT_QUOTES, 'UTF-8');
$tail_number = htmlspecialchars($flight['tail_number'] ?: '-', ENT_QUOTES, 'UTF-8');
$park_position = htmlspecialchars($flight['parkposition'] ?: '-', ENT_QUOTES, 'UTF-8');
$arrival_no = htmlspecialchars($flight['arrival_flight_number'] ?: '-', ENT_QUOTES, 'UTF-8');
$departure_no = htmlspecialchars($flight['departure_flight_number'] ?: '-', ENT_QUOTES, 'UTF-8');
$arrivaldestination = htmlspecialchars($flight['arrivaldest'] ?: '-', ENT_QUOTES, 'UTF-8');
$departuredest = htmlspecialchars($flight['departuredest'] ?: '-', ENT_QUOTES, 'UTF-8');
$arrival_date = $flight['arrival_date_time'] ? date('d.m.Y', strtotime($flight['arrival_date_time'])) : '-';
$arrival_time = $flight['arrival_date_time'] ? date('H:i', strtotime($flight['arrival_date_time'])) : '-';
$departure_date = $flight['departure_date_time'] ? date('d.m.Y', strtotime($flight['departure_date_time'])) : '-';
$departure_time = $flight['departure_date_time'] ? date('H:i', strtotime($flight['departure_date_time'])) : '-';
?>



<!DOCTYPE html>
<html lang="tr">

<head>
	
  <meta charset="utf-8" />
  <title>Active Flight | Operation</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="Flight entry screen" />
  <link rel="shortcut icon" href="assets/images/favicon.ico">
  <link href="assets/css/vendor.min.css" rel="stylesheet" />
  <link href="assets/css/icons.min.css" rel="stylesheet" />
  <link href="assets/css/app.min.css" rel="stylesheet" />
<!-- Boxicons CDN -->
  
<script src="assets/js/config.js"></script>
</head>

<body>	
  <header class="topbar">
      <?php include 'topbar.php'; ?>
    </header>

   <div class="page-content">
  <div class="container-xl">
    <div class="row">
      <div class="card">
        <div class="card-body">
          <div class="mt-3">
  <h5>Uçuş Türü: <?php echo $flight_type; ?></h5>
  <h5>ICAO: <?php echo $icao_code; ?></h5>
  <h5>Kuyruk No: <?php echo $tail_number; ?></h5>
  <h5>Park Pozisyonu: <?php echo $park_position; ?></h5>

  <?php if ($flight_type === 'ARRIVAL'): ?>
    <h5>Arrival Number: <?php echo $arrival_no; ?></h5>
    <h5>Arrival Dest.: <?php echo $arrivaldestination; ?></h5>
    <h5>Arrival Date: <?php echo $arrival_date; ?></h5>
    <h5>Scheduled Arrival: <?php echo $arrival_time; ?></h5>
    
  <?php elseif ($flight_type === 'DEPARTURE'): ?>
    <h5>Departure Number: <?php echo $departure_no; ?></h5>
    <h5>Departure Dest.: <?php echo $departuredest; ?></h5>
    <h5>Departure Date: <?php echo $departure_date; ?></h5>
    <h5>Scheduled Departure: <?php echo $departure_time; ?></h5>

  <?php elseif ($flight_type === 'TURNAROUND'): ?>
    <h5>Arrival Number: <?php echo $arrival_no; ?></h5>
    <h5>Arrival Dest.: <?php echo $arrivaldestination; ?></h5>
    <h5>Arrival Date: <?php echo $arrival_date; ?></h5>
    <h5>Scheduled Arrival: <?php echo $arrival_time; ?></h5>
    <h5>Departure Number: <?php echo $departure_no; ?></h5>
    <h5>Departure Dest.: <?php echo $departuredest; ?></h5>
    <h5>Departure Date: <?php echo $departure_date; ?></h5>
    <h5>Scheduled Departure: <?php echo $departure_time; ?></h5>
  <?php endif; ?>
</div>
   

          </div>
</div>
 <div class="card">
            <div class="card-body">
              <h4 class="card-title">SÜREÇ TAKİBİ</h4>
            </div>
            <div class="card-body">
             <?php
$flight_type = strtolower($flight['flight_type'] ?? '');

switch ($flight_type) {
    case 'arrival':
        include 'arrival-process.php';
        break;
    case 'departure':
        include 'departure-process.php';
        break;
    case 'turnaround':
        include 'arrival-process.php';
        include 'departure-process.php';
        break;
    default:
        echo "<p class='text-danger'>Süreç bulunamadı: Uçuş türü tanımsız.</p>";
}
?>

            </div>
          </div>

<div class="p-3 bg-light mb-3 rounded">
  <form method="POST">
    <input type="hidden" name="flight_id" value="<?php echo $flight['id']; ?>">
    <button type="submit" class="btn btn-outline-primary w-100">SÜRECİ TAMAMLA</button>
  </form>
</div>








</div></div></div>
<script src="assets/js/vendor.js"></script>
<script src="assets/js/app.js"></script>
</body></html>
