<?php
require_once __DIR__ . '/beta_debug.php';
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Kullanıcı kontrolü + mail + admin onayı
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

// Uçuş sayıları
$total_flights = $conn->query("SELECT COUNT(*) AS total FROM flights WHERE user_id = $user_id")->fetch_assoc()['total'];
$total_arrivals = $conn->query("SELECT COUNT(*) AS total FROM flights WHERE user_id = $user_id AND flight_type = 'arrival'")->fetch_assoc()['total'];
$total_departures = $conn->query("SELECT COUNT(*) AS total FROM flights WHERE user_id = $user_id AND flight_type = 'departure'")->fetch_assoc()['total'];
$total_turnarounds = $conn->query("SELECT COUNT(*) AS total FROM flights WHERE user_id = $user_id AND flight_type = 'turnaround'")->fetch_assoc()['total'];


// Tamamlanmış uçuşları çek
$stmt = $conn->prepare("
  SELECT id, tail_number, arrival_flight_number, departure_flight_number, 
         arrival_date_time, departure_date_time, parkposition, flight_type, iata_code
  FROM flights
  WHERE user_id = ? AND status = 'completed'
  ORDER BY arrival_date_time DESC
");

$stmt->bind_param("i", $user_id);
$stmt->execute();
$flights = $stmt->get_result();

?>


<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <title>Flight History</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link href="assets/css/vendor.min.css" rel="stylesheet" />
  <link href="assets/css/icons.min.css" rel="stylesheet" />
  <link href="assets/css/app.min.css" rel="stylesheet" />
  <script src="assets/js/config.js"></script>
</head>
<body>
<header class="topbar">
<?php include 'topbar.php'; ?>
</header>
	

<div class="page-content">
  <div class="container-xl">
    <div class="row">
      <div class="col-md-3">
        <div class="card">
          <div class="card-body">
            <h5 class="mb-0">Toplam Uçuş</h5>
            <p class="text-muted fw-medium fs-22 mb-0"><?= $total_flights ?></p>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card">
          <div class="card-body">
            <h5 class="mb-0">Arrivals</h5>
            <p class="text-muted fw-medium fs-22 mb-0"><?= $total_arrivals ?></p>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card">
          <div class="card-body">
            <h5 class="mb-0">Departures</h5>
            <p class="text-muted fw-medium fs-22 mb-0"><?= $total_departures ?></p>
          </div>
        </div>
      </div>
      <div class="col-md-3">
        <div class="card">
          <div class="card-body">
            <h5 class="mb-0">Turnarounds</h5>
            <p class="text-muted fw-medium fs-22 mb-0"><?= $total_turnarounds ?></p>
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="d-flex card-header justify-content-between align-items-center">
        <h4 class="card-title">Flight List</h4>
      </div>
      <div class="table-responsive">
        <table class="table align-middle table-hover table-centered mb-0">
          <thead class="bg-light-subtle">
            <tr>
              <th>TAIL</th>
              <th>ARRIVAL FLIGHT NUMBER</th>
              <th>DEPARTURE FLIGHT NUMBER</th>
              <th>STA</th>
              <th>STD</th>
              <th>PARK POS</th>
              <th>ACTION</th>
            </tr>
          </thead>
          <tbody>
<?php while($flight = $flights->fetch_assoc()): ?>
<?php
  $arrival_date = $flight['arrival_date_time'] ? date('d.m.Y', strtotime($flight['arrival_date_time'])) : '-';
  $arrival_time = $flight['arrival_date_time'] ? date('H:i', strtotime($flight['arrival_date_time'])) : '-';
  $departure_date = $flight['departure_date_time'] ? date('d.m.Y', strtotime($flight['departure_date_time'])) : '-';
  $departure_time = $flight['departure_date_time'] ? date('H:i', strtotime($flight['departure_date_time'])) : '-';
?>

<tr>
  <td><?= htmlspecialchars($flight['tail_number'] ?? '-') ?></td>

  <td>
<?= !empty($flight['arrival_flight_number']) ? htmlspecialchars(($flight['iata_code'] ?? '') . $flight['arrival_flight_number']) : '-' ?>
  </td>

  <td>
    <?= !empty($flight['departure_flight_number']) ? htmlspecialchars(($flight['iata_code'] ?? '') . $flight['departure_flight_number']) : '-' ?>
  </td>

  <td>
    <?= !empty($flight['arrival_date_time']) ? $arrival_date . ' ' . $arrival_time : '-' ?>
  </td>

  <td>
    <?= !empty($flight['departure_date_time']) ? $departure_date . ' ' . $departure_time : '-' ?>
  </td>

  <td><?= htmlspecialchars($flight['parkposition'] ?? '-') ?></td>

  <td>
    <div class="d-flex gap-2">
      <button class="btn btn-light btn-sm" data-id="<?= $flight['id'] ?>" onclick="showFlight(this)">
        <iconify-icon icon="solar:eye-broken" class="align-middle fs-18"></iconify-icon>
      </button>
      <button class="btn btn-soft-primary btn-sm" data-id="<?= $flight['id'] ?>" onclick="editFlight(this)">
        <iconify-icon icon="solar:pen-2-broken" class="align-middle fs-18"></iconify-icon>
      </button>
    </div>
  </td>
</tr>

<?php endwhile; ?>
</tbody>
        </table>
      </div>
    </div>
  </div>
</div>
	
<!-- get flight -->
<div class="modal fade" id="flightModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Flight Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="flightDetails"></div>
    </div>
  </div>
</div>
<!-- get flight -->
<script src="assets/js/vendor.js"></script>
<script src="assets/js/app.js"></script>
<script>
function showFlight(btn) {
  const id = btn.getAttribute('data-id');
  fetch('get_edit_flight.php?id=' + id)
    .then(res => res.text())
    .then(html => {
      document.getElementById('flightDetails').innerHTML = html;
      new bootstrap.Modal(document.getElementById('flightModal')).show();
    });
}

function editFlight(btn) {
  const id = btn.getAttribute('data-id');
  fetch('get_edit_flight.php?id=' + id + '&edit=1')
    .then(res => res.text())
    .then(html => {
      document.getElementById('flightDetails').innerHTML = html;
      new bootstrap.Modal(document.getElementById('flightModal')).show();

      const form = document.getElementById('editFlightForm');
      if (form) {
        form.addEventListener('submit', function(e) {
          e.preventDefault();
          const formData = new FormData(this);
          fetch('get_edit_flight.php', {
            method: 'POST',
            body: formData
          })
          .then(res => res.text())
          .then(msg => {
            alert(msg);
            location.reload();
          })
          .catch(err => {
            console.error('Error:', err);
            alert('Bir hata oluştu.');
          });
        });
      }
    });
}

function deleteFlight(btn) {
  const id = btn.getAttribute('data-id');
  if (confirm('Silmek istediğinize emin misiniz?')) {
    fetch('get_edit_flight.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: 'id=' + id + '&delete=1'
    })
    .then(res => res.text())
    .then(msg => {
      alert(msg);
      location.reload();
    })
    .catch(err => {
      console.error('Silme hatası:', err);
      alert('Silme sırasında bir hata oluştu.');
    });
  }
}
function copyFlightInfo() {
  const modalContent = document.getElementById('flightDetails');

  if (!modalContent) {
    alert("Modal içeriği bulunamadı!");
    return;
  }

  const listItems = modalContent.querySelectorAll('.list-group-item');
  const processRows = modalContent.querySelectorAll('table.table tbody tr');

  let infoText = Array.from(listItems)
    .map(el => el.innerText.trim())
    .join('\n');

  let processText = Array.from(processRows)
    .map(row => {
      const cells = row.querySelectorAll('td');
      return cells.length === 2 ? `${cells[0].innerText.trim()}: ${cells[1].innerText.trim()}` : '';
    })
    .join('\n');

  const fullText = infoText + '\n\nSüreç Bilgileri:\n' + processText;

  navigator.clipboard.writeText(fullText)
    .then(() => alert("Tüm bilgiler panoya kopyalandı!"))
    .catch(err => alert("Kopyalama başarısız: " + err));
}

</script>	
</body>
</html>