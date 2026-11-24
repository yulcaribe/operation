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
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $type = strtolower(trim((string)($_POST['flight_type'] ?? '')));
        if (!in_array($type, ['arrival', 'departure', 'turnaround'], true)) throw new RuntimeException('Uçuş türünü seçin.');
        $arrival = trim((string)($_POST['arrival_date'] ?? '')) && trim((string)($_POST['scheduled_arrival'] ?? ''))
            ? trim($_POST['arrival_date']) . ' ' . trim($_POST['scheduled_arrival']) . ':00' : null;
        $departure = trim((string)($_POST['departure_date'] ?? '')) && trim((string)($_POST['scheduled_departure'] ?? ''))
            ? trim($_POST['departure_date']) . ' ' . trim($_POST['scheduled_departure']) . ':00' : null;
        $mtow = trim((string)($_POST['mtow'] ?? ''));
        $mtow = is_numeric($mtow) ? $mtow : null;
        $fields = [
            'user_id' => $user_id, 'flight_type' => $type,
            'company_name' => trim((string)($_POST['company_name'] ?? '')) ?: null,
            'iata_code' => strtoupper(trim((string)($_POST['iata_code'] ?? ''))) ?: null,
            'icao_code' => strtoupper(trim((string)($_POST['icao_code'] ?? ''))) ?: null,
            'tail_number' => strtoupper(trim((string)($_POST['tail_number'] ?? ''))) ?: null,
            'arrival_flight_number' => trim((string)($_POST['arrival_flight_number'] ?? '')) ?: null,
            'departure_flight_number' => trim((string)($_POST['departure_flight_number'] ?? '')) ?: null,
            'stand' => trim((string)($_POST['parkposition'] ?? '')) ?: null,
            'scheduled_arrival_at' => $arrival, 'scheduled_departure_at' => $departure,
            'arrival_destination' => strtoupper(trim((string)($_POST['arrivaldest'] ?? ''))) ?: null,
            'departure_destination' => strtoupper(trim((string)($_POST['departuredest'] ?? ''))) ?: null,
            'nationality' => trim((string)($_POST['nationality'] ?? '')) ?: null,
            'representative' => trim((string)($_POST['representative'] ?? '')) ?: null,
            'representative_code' => trim((string)($_POST['representative_code'] ?? '')) ?: null,
            'aircraft_type' => trim((string)($_POST['aircraft_type'] ?? '')) ?: null,
            'mtow' => $mtow, 'pax_figure' => trim((string)($_POST['pax_figure'] ?? '')) ?: null,
            'status' => 'active', 'source' => 'manual'
        ];
        $columns = implode(', ', array_keys($fields));
        $marks = implode(', ', array_fill(0, count($fields), '?'));
        $stmt = $conn->prepare("INSERT INTO flights ($columns) VALUES ($marks)");
        $values = array_values($fields);
        $stmt->bind_param(str_repeat('s', count($fields)), ...$values);
        $stmt->execute();
        $newId = (int)$conn->insert_id;
        $_SESSION['active_flight_id'] = $newId;
        header('Location: active_flight.php?flight_id=' . $newId);
        exit;
    } catch (Throwable $e) {
        error_log('[Operation BETA] add_flight: ' . $e->getMessage());
        $error = $e instanceof RuntimeException ? $e->getMessage() : 'Uçuş kaydı sırasında hata oluştu.';
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
        <div class="card-body"><?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, "UTF-8") ?></div><?php endif; ?>
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
  <input type="text" name="company_name" class="form-control" id="company_name" placeholder="İstediğiniz havayolu / şirket" autocomplete="off">

  <!-- Arka plan gizli inputlar -->
            <input type="hidden" name="representative" id="representative">
            <input type="hidden" name="representative_code" id="representative_code">
            <div class="row"><div class="col-md-6 mb-3"><label class="form-label">IATA Kodu (isteğe bağlı)</label><input type="text" name="iata_code" id="iata_code" class="form-control" maxlength="3"></div><div class="col-md-6 mb-3"><label class="form-label">ICAO Kodu (isteğe bağlı)</label><input type="text" name="icao_code" id="icao_code" class="form-control" maxlength="4"></div></div>
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
                <input type="text" name="nationality" class="form-control" id="nationality_select" placeholder="İsteğe bağlı">
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






<script src="assets/js/vendor.js"></script>
<script src="assets/js/app.js"></script>
</body>
</html>
