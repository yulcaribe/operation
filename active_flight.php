<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

$user_check = $conn->prepare("SELECT id, mail_activation, admin_approval FROM users WHERE id = ?");
$user_check->bind_param("i", $user_id);
$user_check->execute();
$user_result = $user_check->get_result();

if ($user_result->num_rows === 0) {
    session_destroy();
    header("Location: login.php");
    exit();
}

$user = $user_result->fetch_assoc();

if ((int)$user['mail_activation'] !== 1 || (int)$user['admin_approval'] !== 1) {
    session_destroy();
    header("Location: login.php");
    exit();
}

$stmt = $conn->prepare("SELECT * FROM flights WHERE user_id = ? AND status = 'active' ORDER BY created_at DESC LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: add_flight.php");
    exit();
}

$flight = $result->fetch_assoc();

$flight_type = strtoupper($flight['flight_type']);
$tail_number = $flight['tail_number'] ?? '-';
$arrival_no = $flight['arrival_flight_number'] ?? '-';
$arrivaldestination = $flight['arrivaldest'] ?? '-';
$scheduled_arrival = $flight['scheduled_arrival'] ?? '-';
$departure_no = $flight['departure_flight_number'] ?? '-';
$departuredest = $flight['departuredest'] ?? '-';
$scheduled_departure = $flight['scheduled_departure'] ?? '-';
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
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />  
<script src="assets/js/config.js"></script>
</head>

<body>	
  <header>
    <header class="topbar">
      <?php include 'topbar.php'; ?>
    </header>

    <div class="page-content">
      <div class="container-xl">
        <div class="row">
          <div class="card">
            <div class="card-body">
              <div class="mt-3">
                <h4 class="fw-bold topbar-button pe-none text-uppercase mb-0">UÇUŞ BİLGİSİ</h4>
              </div>
              <div class="mt-3">
                <h5>Uçuş Türü: <?php echo $flight_type; ?></h5>
                <h5>Kuyruk No: <?php echo $tail_number; ?></h5>
                <h5>Arrival Number: <?php echo $arrival_no; ?></h5>
                <h5>Arrival Dest.: <?php echo $arrivaldestination; ?></h5>
                <h5>STA: <?php echo $scheduled_arrival; ?></h5>
                <h5>Departure Number: <?php echo $departure_no; ?></h5>
                <h5>Departure Dest.: <?php echo $departuredest; ?></h5>
                <h5>STD: <?php echo $scheduled_departure; ?></h5>
              </div>
              <div class="mt-3">
                <h4 class="fw-bold">İRTİFA: <span id="altitude">Yükleniyor...</span></h4>
              </div>
              <div class="mt-2">
                <button type="button" class="btn btn-outline-info w-100" data-bs-toggle="modal" data-bs-target="#mapModal">
                  Konumu Gör
                </button>
              </div>
            </div>
          </div>
          <div class="card">
            <div class="card-body">
              <h4 class="card-title">SÜREÇ TAKİBİ</h4>
            </div>
            <div class="card-body">
              <?php
              $flight_type = $flight['flight_type'] ?? '';
              switch ($flight_type) {
                case 'arrival':
                    include 'flight-process.php';
                    break;
                case 'departure':
                    include 'flight-process.php';
                    break;
                case 'turnaround':
                    include 'flight-process.php';
                    break;
                default:
                    echo "<p class='text-danger'>Süreç bulunamadı: Uçuş türü tanımsız.</p>";
              }
              ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="p-3 bg-light mb-3 rounded">
      <form method="POST" action="complete_flight.php">
        <input type="hidden" name="flight_id" value="<?php echo $flight['id']; ?>">
        <button type="submit" class="btn btn-outline-primary w-100">SÜRECİ TAMAMLA</button>
      </form>
    </div>

    <!-- Modal Harita -->
    <div class="modal fade" id="mapModal" tabindex="-1" aria-labelledby="mapModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Uçuş Konumu</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
          </div>
          <div class="modal-body">
            <div id="world-map-markers" style="height: 400px;"></div>
          </div>
        </div>
      </div>
    </div>

    <script>
      function fetchAltitude() {
        fetch('get_altitude.php')
          .then(response => response.json())
          .then(data => {
            const display = document.getElementById('altitude');
            if (data.altitude_ft !== undefined) {
              display.textContent = data.altitude_ft + ' ft';
            } else {
              display.textContent = data.error || 'Veri alınamadı';
            }
            window.currentLat = data.latitude;
            window.currentLon = data.longitude;
          })
          .catch(error => {
            document.getElementById('altitude').textContent = 'Hata oluştu';
          });
      }

      setInterval(fetchAltitude, 15000);
      window.onload = fetchAltitude;
    </script>


<script src="assets/js/vendor.js"></script>
<script src="assets/js/app.js"></script>

<!-- Leaflet JS -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script src="assets/js/components/flight-map.js"></script>


</body>
</html>
