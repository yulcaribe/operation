<?php
require_once __DIR__ . '/beta_debug.php';
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Uçuş tamamlama işlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['flight_id'])) {
    $flight_id = intval($_POST['flight_id']);

    $stmt = $conn->prepare("UPDATE flights SET status = 'completed' WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $flight_id, $user_id);

    if ($stmt->execute()) {
        header("Location: dashboard.php?completed=1");
        exit();
    } else {
        echo "Bir hata oluştu: " . $stmt->error;
    }
}

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
$icao_code = $flight['icao_code'] ?? '-';
$tail_number = $flight['tail_number'] ?? '-';
$park_position = $flight['parkposition'] ?? '-';
$arrival_no = $flight['arrival_flight_number'] ?? '-';
$departure_no = $flight['departure_flight_number'] ?? '-';
$arrivaldestination = $flight['arrivaldest'] ?? '-';
$departuredest = $flight['departuredest'] ?? '-';

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
   

          <div class="mt-3">
            <h4 class="fw-bold">İRTİFA: <span id="altitude">Yükleniyor...</span></h4>
          </div>
          <div class="mt-2"><button type="button" class="btn btn-warning w-100" data-bs-toggle="modal" data-bs-target="#thresholdModal">Takip Et</button></div>

          <div class="mt-2"><button type="button" class="btn btn-outline-info w-100" data-bs-toggle="modal" data-bs-target="#mapModal">Konumu Gör</button></div>
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

<!-- Modal: Bildirim Eşiği Ayarla -->
<div class="modal fade" id="thresholdModal" tabindex="-1" aria-labelledby="thresholdModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="thresholdModalLabel">İrtifa Bildirimleri</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
      </div>
      <div class="modal-body">
        <div id="thresholdList">
          <div class="input-group mb-2">
            <input type="number" class="form-control threshold-input" value="20000" min="1000" step="1000" placeholder="İrtifa (ft)">
          </div>
        </div>
        <button type="button" class="btn btn-outline-secondary w-100 mb-3" onclick="addThresholdInput()">+ Yeni Eşik Ekle</button>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary w-100" onclick="saveAllThresholds()" data-bs-dismiss="modal">
          Takibi Başlat
        </button>
      </div>
    </div>
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
<div class="p-3 bg-light mb-3 rounded">
    <a href="flight_services.php" class="btn btn-outline-dark">Hizmetler ve Evraklar</a>
</div>







<script>
  let lastAltitudeSent = null;
  let altitudeNotificationEnabled = false;
  let altitudeThresholds = [];
  let notifiedThresholds = new Set();

  const swReadyPromise = navigator.serviceWorker.ready;

  function sendPushNotification(data) {
    swReadyPromise.then(swReg => {
      if (swReg.active) {
        swReg.active.postMessage(data);
      }
    });
  }

  window.addThresholdInput = function () {
    const list = document.getElementById('thresholdList');
    const group = document.createElement('div');
    group.className = 'input-group mb-2';
    group.innerHTML = `
      <input type="number" class="form-control threshold-input" min="100" step="100" placeholder="İrtifa (ft)">
    `;
    list.appendChild(group);
  }

  window.saveAllThresholds = function () {
    altitudeThresholds = [];
    notifiedThresholds.clear();

    document.querySelectorAll('#thresholdList .threshold-input').forEach(input => {
      const ft = parseInt(input.value);
      if (!isNaN(ft) && ft > 0) {
        altitudeThresholds.push(ft);
      }
    });

    if (altitudeThresholds.length > 0) {
      Notification.requestPermission().then(permission => {
        if (permission === "granted") {
          altitudeNotificationEnabled = true;

          new Notification("İrtifa Takibi Başladı", {
            body: "Eşik sayısı: " + altitudeThresholds.length,
            tag: "altitude-tracking-init",
            renotify: true,
            requireInteraction: true,
            icon: "assets/images/plane.png"
          });
        } else {
          alert("Bildirim izni verilmedi.");
        }
      });
    }
  }

  function fetchAltitude() {
    fetch('get_altitude.php')
      .then(response => response.json())
      .then(data => {
        const display = document.getElementById('altitude');
        if (data.altitude_ft !== undefined) {
          const altitude = parseInt(data.altitude_ft);
          display.textContent = altitude + ' ft';

          if (altitudeNotificationEnabled && Notification.permission === "granted") {

            // 1. Eşik kontrolü - her durumda bildir, sadece ilki sesli
            altitudeThresholds.forEach(threshold => {
              const alreadyNotified = notifiedThresholds.has(threshold);
              if (altitude < threshold) {
                sendPushNotification({
                  type: 'push',
                  title: "Düşük İrtifa Uyarısı",
                  body: `İrtifa ${altitude} ft → eşik: ${threshold} ft`,
                  tag: `altitude-alert-${threshold}`,
                  renotify: false,
                  requireInteraction: false,
                  silent: alreadyNotified // ilk değilse sessiz
                });
                notifiedThresholds.add(threshold);
              }
            });

            // 2. Takip bildirimi (sessiz) - her 15sn bir gitmeli
            sendPushNotification({
              type: 'push',
              title: "İrtifa Takibi",
              body: `Mevcut irtifa: ${altitude} ft`,
              tag: 'altitude-status',
              renotify: false,
              requireInteraction: false,
              silent: alreadyNotified // ilk değilse sessiz
            });

            // 3. Altitude değerini güncelle
            lastAltitudeSent = altitude;
          }

        } else {
          display.textContent = data.error || 'Veri alınamadı';
        }
      })
      .catch(() => {
        document.getElementById('altitude').textContent = 'Hata oluştu';
      });
  }

  setInterval(fetchAltitude, 15000);
  window.onload = fetchAltitude;
</script>

<script>
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/sw.js')
      .then(() => {
        console.log("✅ Service Worker başarıyla kayıt edildi.");
      })
      .catch(err => {
        console.error("❌ Service Worker kaydı başarısız:", err);
      });
  }
</script>

<script src="assets/js/vendor.js"></script>
<script src="assets/js/app.js"></script>

<!-- Leaflet JS -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<script src="assets/js/components/flight-map.js"></script>


</body>
</html>
