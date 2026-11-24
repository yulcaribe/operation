<?php
require_once __DIR__ . '/beta_debug.php';
// Normal sayfa işlemleri
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

$stmt = $conn->prepare("SELECT id FROM flights WHERE user_id = ? AND status = 'active' LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    header("Location: active_flight.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $arrival_date = $_POST['arrival_date'] ?? null;
    $scheduled_arrival = $_POST['scheduled_arrival'] ?? null;
    $arrival_date_time = ($arrival_date && $scheduled_arrival) ? date('Y-m-d H:i:s', strtotime("$arrival_date $scheduled_arrival")) : null;

    $departure_date = $_POST['departure_date'] ?? null;
    $scheduled_departure = $_POST['scheduled_departure'] ?? null;
    $departure_date_time = ($departure_date && $scheduled_departure) ? date('Y-m-d H:i:s', strtotime("$departure_date $scheduled_departure")) : null;

$fields = [
    'user_id' => $_SESSION['user_id'],
    'flight_type' => $_POST['flight_type'] ?? '',
    'tail_number' => $_POST['tail_number'] ?? null,
    'arrival_flight_number' => $_POST['arrival_flight_number'] ?? null,
    'departure_flight_number' => $_POST['departure_flight_number'] ?? null,
    'parkposition' => $_POST['parkposition'] ?? null,
    'arrival_date_time' => $arrival_date_time,
    'departure_date_time' => $departure_date_time,
    'arrivaldest' => $_POST['arrivaldest'] ?? null,
    'departuredest' => $_POST['departuredest'] ?? null,
    'nationality' => $_POST['nationality'] ?? null,
    'company_name' => $_POST['company_name'] ?? null,
    'iata_code' => $_POST['iata_code'] ?? null,
    'icao_code' => $_POST['icao_code'] ?? null, 
    'representative' => $_POST['representative'] ?? null,
    'representative_code' => $_POST['representative_code'] ?? null,
    'aircraft_type' => $_POST['aircraft_type'] ?? null,
    'mtow' => $_POST['mtow'] ?? null,
     'pax_figure' => $_POST['pax_figure'] ?? null
];


    $columns = implode(", ", array_keys($fields));
    $placeholders = implode(", ", array_fill(0, count($fields), '?'));
    $types = str_repeat('s', count($fields));
    $values = array_values($fields);

    $stmt = $conn->prepare("INSERT INTO flights ($columns) VALUES ($placeholders)");
    $stmt->bind_param($types, ...$values);

    if ($stmt->execute()) {
        header("Location: active_flight.php");
        exit();
    } else {
        echo "Bir hata oluştu: " . $stmt->error;
    }
}
?>


<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="utf-8" />
  <title>Yeni Uçuş | Operation</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="Flight entry screen" />
  <link rel="shortcut icon" href="assets/images/favicon.ico">
  <link href="assets/css/vendor.min.css" rel="stylesheet" />
  <link href="assets/css/icons.min.css" rel="stylesheet" />
  <link href="assets/css/app.min.css" rel="stylesheet" />
  <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
  <script src="assets/js/config.js"></script>
</head>
<body>
<header class="topbar">
<?php include 'topbar.php'; ?>
</header>
<div class="page-content">
  <div class="container-xxl">
    <div class="row">
      <div class="card">
        <div class="card-body">
          <form method="POST" id="flightForm">
            <input type="hidden" name="flight_type" id="flight_type_input">
            <div class="row g-2">
              <div class="col-lg-4">
                <button type="button" class="btn btn-primary w-100" data-flight-type="arrival">Arrival</button>
              </div>
              <div class="col-lg-4">
                <button type="button" class="btn btn-primary w-100" data-flight-type="departure">Departure</button>
              </div>
              <div class="col-lg-4">
                <button type="button" class="btn btn-primary w-100" data-flight-type="turnaround">Turn-Around</button>
              </div>
            </div>
            <div id="dynamicFields" class="mt-4" style="display:none;">
                <div class="mb-3">
  <label class="form-label">Şirket Adı</label>
  <select name="company_name" class="form-control" id="company_name">
    <option value="">Seçiniz</option>
  </select>

  <!-- Arka plan gizli inputlar -->
            <input type="hidden" name="representative" id="representative">
            <input type="hidden" name="representative_code" id="representative_code">
            <input type="hidden" name="iata_code" id="iata_code">
            <input type="hidden" name="icao_code" id="icao_code">
            <input type="hidden" name="aircraft_type" id="aircraft_type">
            <input type="hidden" name="mtow" id="mtow">
            <input type="hidden" name="pax_figure" id="pax_figure">

</div>

              <div id="arrivalField" class="mb-3" style="display:none;">
                <label class="form-label">Arrival Sefer No / Lütfen IATA veya ICAO kodu yazmayınız.</label>
                <input type="text" name="arrival_flight_number" class="form-control" id="arrival_flight_number">
              </div>
              <div id="departureField" class="mb-3" style="display:none;">
                <label class="form-label">Departure Sefer / No Lütfen IATA veya ICAO kodu yazmayınız.</label>
                <input type="text" name="departure_flight_number" class="form-control" id="departure_flight_number">
              </div>
              <div class="mb-3">
                <label class="form-label">Kuyruk Numarası</label>
                <input type="text" name="tail_number" class="form-control" id="registration">
              </div>

              <div class="mb-3">
                <label class="form-label">Milliyet</label>
                <select name="nationality" class="form-control" id="nationality_select">
                  <option value="">Seçiniz</option>
                </select>
              </div>
              <div id="parkField" class="mb-3" style="display:none;">
                <label class="form-label">Park Pozisyonu</label>
                <input type="text" name="parkposition" class="form-control">
              </div>
              <div id="flightDateField" class="mb-3" style="display:none;">
                <label class="form-label">Arrival Date</label>
                <input type="date" name="arrival_date" id="arrival_date_input" class="form-control">
              </div>
              <div id="schArrivalField" class="mb-3" style="display:none;">
                <label class="form-label">Scheduled Arrival</label>
                <input type="time" name="scheduled_arrival" class="form-control" id="scheduled_arrival">
              </div>
              <div id="departureDateField" class="mb-3" style="display:none;">
                <label class="form-label">Departure Date</label>
                <input type="date" name="departure_date" id="departure_date_input" class="form-control">
              </div>
              <div id="schDepartureField" class="mb-3" style="display:none;">
                <label class="form-label">Scheduled Departure</label>
                <input type="time" name="scheduled_departure" class="form-control" id="scheduled_departure">
              </div>
              <div id="arrivalDestField" class="mb-3" style="display:none;">
                <label class="form-label">Arrival Destination</label>
                <input type="text" name="arrivaldest" class="form-control" id="origin_airport">
              </div>
              <div id="departureDestField" class="mb-3" style="display:none;">
                <label class="form-label">Departure Destination</label>
                <input type="text" name="departuredest" class="form-control" id="destination_airport">
              </div>
              <div class="text-end">
                <button type="submit" class="btn btn-success">Kaydet</button>
              </div>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
  let nationalityData = [];

  // JSON'dan milliyet verisini al ve dropdown'u doldur
  fetch('/jsondata/nationality.json')
    .then(res => res.json())
    .then(data => {
      nationalityData = data;
      const select = document.getElementById('nationality_select');

      data.forEach(nation => {
        const display = `${nation.country} - ${nation.icao_marks[0]}`;
        const option = document.createElement('option');
        option.value = display;
        option.textContent = display;
        select.appendChild(option);
      });
    });

  // Kuyruk numarası girildiğinde otomatik milliyet seç
  document.addEventListener('DOMContentLoaded', () => {
    const tailInput = document.getElementById('registration');
    const select = document.getElementById('nationality_select');

    tailInput.addEventListener('blur', function () {
      const tail = this.value.trim().toUpperCase();
      if (!tail || nationalityData.length === 0) return;

      const mark = tail.split('-')[0];

      const match = nationalityData.find(nation =>
        nation.icao_marks.some(code => mark === code)
      );

      if (match) {
        const value = `${match.country} - ${match.icao_marks[0]}`;
        select.value = value;
      }
    });
  });
</script>

<script>
  const buttons = document.querySelectorAll('[data-flight-type]');
  const flightInput = document.getElementById('flight_type_input');
  const dynamicFields = document.getElementById('dynamicFields');
  const fields = {
    arrival: ["arrivalField", "parkField", "schArrivalField", "arrivalDestField", "flightDateField"],
    departure: ["departureField", "parkField", "schDepartureField", "departureDestField", "departureDateField"],
    turnaround: ["arrivalField", "departureField", "parkField", "schArrivalField", "schDepartureField", "arrivalDestField", "departureDestField", "flightDateField", "departureDateField"]
  };

  buttons.forEach(button => {
    button.addEventListener('click', () => {
      const type = button.getAttribute('data-flight-type');
      flightInput.value = type;
      dynamicFields.style.display = 'block';
      Object.values(fields).flat().forEach(id => document.getElementById(id).style.display = 'none');
      fields[type].forEach(id => document.getElementById(id).style.display = 'block');
    });
  });

  document.addEventListener("DOMContentLoaded", function () {
    const today = new Date().toISOString().split('T')[0];
    const arrivalInput = document.getElementById("arrival_date_input");
    const departureInput = document.getElementById("departure_date_input");
    if (arrivalInput) arrivalInput.value = today;
    if (departureInput) departureInput.value = today;
  });
</script>
<script>
  let companyData = [];

  // JSON'dan şirket verilerini al ve dropdown
  fetch('/jsondata/company_name.json')
    .then(res => res.json())
    .then(data => {
      companyData = data;
      const select = document.getElementById('company_name');

      data.forEach(company => {
        const option = document.createElement('option');
        option.value = company.company;
        option.textContent = company.company;
        option.dataset.iata = company.iata;
        option.dataset.icao = company.icao;
        option.dataset.representative = company.representative;
        option.dataset.repcode = company.representative_code;
        select.appendChild(option);
      });
    });

  // Şirket seçildiğinde gizli inputlara ilgili bilgileri yaz
  document.getElementById('company_name').addEventListener('change', function () {
    const selected = this.options[this.selectedIndex];
    document.getElementById('iata_code').value = selected.dataset.iata || '';
    document.getElementById('icao_code').value = selected.dataset.icao || '';
    document.getElementById('representative').value = selected.dataset.representative || '';
    document.getElementById('representative_code').value = selected.dataset.repcode || '';
  });
</script>

<script>

// JSON'dan uçak verilerini alma
let aircraftData = [];

fetch('/jsondata/aircraft_data.json')
  .then(res => res.json())
  .then(data => {
    aircraftData = data;
  });

// Kuyruk numarası girilince kontrol et
document.getElementById('registration').addEventListener('blur', function () {
  const tail = this.value.trim().toUpperCase();

  const match = aircraftData.find(ac => ac.tail.toUpperCase() === tail);

  if (match) {
    document.getElementById('aircraft_type').value = match.aircraft_type || '';
    document.getElementById('mtow').value = match.mtow || '';
    document.getElementById('pax_figure').value = match.pax_figure || '';
  }
});
</script>



<script src="assets/js/vendor.js"></script>
<script src="assets/js/app.js"></script>
</body>
</html>
