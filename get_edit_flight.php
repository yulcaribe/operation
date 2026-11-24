<?php
require_once __DIR__ . '/beta_debug.php';
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo "Oturum süresi doldu. Lütfen tekrar giriş yapın.";
    exit;
}

$user_id = $_SESSION['user_id'];

function safe($val) {
    return htmlspecialchars($val ?? '', ENT_QUOTES, 'UTF-8');
}

// === SİLME ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
    $id = intval($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmt = $conn->prepare("DELETE FROM flights WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $id, $user_id);
        echo $stmt->execute() ? "Uçuş silindi." : "Silme başarısız: " . $conn->error;
    } else {
        http_response_code(400);
        echo "Geçersiz uçuş ID.";
    }
    exit;
}

// === GÜNCELLEME ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = intval($_POST['id'] ?? 0);
    $tail = $_POST['tail_number'] ?? '';
    $arrival = $_POST['arrival_flight_number'] ?? '';
    $departure = $_POST['departure_flight_number'] ?? '';
    $parking = $_POST['parkposition'] ?? '';
    $arrival_date = $_POST['arrival_date'] ?? '';
    $arrival_time = $_POST['scheduled_arrival'] ?? '';
    $departure_date = $_POST['departure_date'] ?? '';
    $departure_time = $_POST['scheduled_departure'] ?? '';
    $arrival_datetime = ($arrival_date && $arrival_time) ? "$arrival_date $arrival_time:00" : null;
    $departure_datetime = ($departure_date && $departure_time) ? "$departure_date $departure_time:00" : null;

    $check = $conn->prepare("SELECT id FROM flights WHERE id = ? AND user_id = ?");
    $check->bind_param("ii", $id, $user_id);
    $check->execute();
    if ($check->get_result()->num_rows === 0) {
        http_response_code(403);
        echo "Bu uçuş size ait değil.";
        exit;
    }

    $stmt = $conn->prepare("UPDATE flights SET tail_number=?, arrival_flight_number=?, departure_flight_number=?, parkposition=?, arrival_date_time=?, departure_date_time=? WHERE id=?");
    $stmt->bind_param("ssssssi", $tail, $arrival, $departure, $parking, $arrival_datetime, $departure_datetime, $id);
    echo $stmt->execute() ? "Uçuş bilgisi güncellendi." : "Güncelleme başarısız: " . $conn->error;
    exit;
}

// === GÖRÜNTÜLEME ===
$id = intval($_GET['id'] ?? 0);
$edit = isset($_GET['edit']);

$stmt = $conn->prepare("SELECT * FROM flights WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $id, $user_id);
$stmt->execute();
$result = $stmt->get_result();
$flight = $result->fetch_assoc();

if (!$flight) {
    echo "Uçuş bulunamadı.";
    exit;
}

$type = strtolower($flight['flight_type']);
$arrival_date = $flight['arrival_date_time'] ? date('Y-m-d', strtotime($flight['arrival_date_time'])) : '';
$arrival_time = $flight['arrival_date_time'] ? date('H:i', strtotime($flight['arrival_date_time'])) : '';
$departure_date = $flight['departure_date_time'] ? date('Y-m-d', strtotime($flight['departure_date_time'])) : '';
$departure_time = $flight['departure_date_time'] ? date('H:i', strtotime($flight['departure_date_time'])) : '';
?>

<?php if ($edit): ?>
<form id="editFlightForm">
  <input type="hidden" name="id" value="<?= $flight['id'] ?>">

  <div class="row">
    <div class="col-md-6 mb-3">
      <label>Tail Number</label>
      <input type="text" name="tail_number" class="form-control" value="<?= safe($flight['tail_number']) ?>">
    </div>
    <div class="col-md-6 mb-3">
      <label>Parking Position</label>
      <input type="text" name="parkposition" class="form-control" value="<?= safe($flight['parkposition']) ?>">
    </div>
  </div>

  <?php if ($type !== 'departure'): ?>
  <h6 class="mt-3">Arrival Bilgileri</h6>
  <div class="row">
    <div class="col-md-4 mb-3">
      <label>Flight No</label>
      <input type="text" name="arrival_flight_number" class="form-control" value="<?= safe($flight['arrival_flight_number']) ?>">
    </div>
    <div class="col-md-4 mb-3">
      <label>Date</label>
      <input type="date" name="arrival_date" class="form-control" value="<?= safe($arrival_date) ?>">
    </div>
    <div class="col-md-4 mb-3">
      <label>Time</label>
      <input type="time" name="scheduled_arrival" class="form-control" value="<?= safe($arrival_time) ?>">
    </div>
  </div>
  <?php endif; ?>

  <?php if ($type !== 'arrival'): ?>
  <h6 class="mt-3">Departure Bilgileri</h6>
  <div class="row">
    <div class="col-md-4 mb-3">
      <label>Flight No</label>
      <input type="text" name="departure_flight_number" class="form-control" value="<?= safe($flight['departure_flight_number']) ?>">
    </div>
    <div class="col-md-4 mb-3">
      <label>Date</label>
      <input type="date" name="departure_date" class="form-control" value="<?= safe($departure_date) ?>">
    </div>
    <div class="col-md-4 mb-3">
      <label>Time</label>
      <input type="time" name="scheduled_departure" class="form-control" value="<?= safe($departure_time) ?>">
    </div>
  </div>
  <?php endif; ?>

  <div class="d-flex justify-content-between mt-3">
    <button type="button" class="btn btn-danger" data-id="<?= $flight['id'] ?>" onclick="deleteFlight(this)">Sil</button>
    <button type="submit" class="btn btn-primary">Güncelle</button>
  </div>
</form>

<?php else: ?>
<div class="mb-3">
  <ul class="list-group">
    <li class="list-group-item"><strong>Tail:</strong> <?= safe($flight['tail_number']) ?></li>
    <li class="list-group-item"><strong>Park Pos:</strong> <?= safe($flight['parkposition']) ?></li>
    <?php if ($type !== 'departure'): ?>
      <li class="list-group-item"><strong>Arrival:</strong> <?= safe($flight['arrival_flight_number']) ?></li>
      <li class="list-group-item"><strong>STA:</strong> <?= "$arrival_date $arrival_time" ?></li>
    <?php endif; ?>
    <?php if ($type !== 'arrival'): ?>
      <li class="list-group-item"><strong>Departure:</strong> <?= safe($flight['departure_flight_number']) ?></li>
      <li class="list-group-item"><strong>STD:</strong> <?= "$departure_date $departure_time" ?></li>
    <?php endif; ?>
  </ul>
</div>

<button class="btn btn-outline-primary w-100 mb-3" onclick="copyFlightInfo()">KOPYALA</button>

<h6 class="mb-2">Süreç Bilgileri</h6>
<table class="table table-sm table-bordered">
  <thead class="table-light"><tr><th>Süreç</th><th>Zaman(lar)</th></tr></thead>
  <tbody>
    <?php
    $processes = match($type) {
        'arrival' => ['doorsopening','gpu','unloading','deboarding','arrivalcrewcar'],
        'departure' => ['crew','fueling','catering','loading','preboarding','boarding','firstpax','doorclosed','pushback','offblock'],
        'turnaround' => ['doorsopening','gpu','unloading','deboarding','arrivalcrewcar','crew','fueling','catering','preboarding','boarding','firstpax','doorclosed','pushback','offblock'],
        default => []
    };

    foreach ($processes as $proc):
        $start = $flight[$proc.'_start_time'] ?? null;
        $end = $flight[$proc.'_end_time'] ?? null;
        $time = $flight[$proc] ?? null;
    ?>
      <tr>
        <td><?= ucwords(str_replace('_', ' ', $proc)) ?></td>
        <td>
          <?php if ($start || $end): ?>
            <?= $start ? "Start: ".date('H:i', strtotime($start)) : '' ?>
            <?= $end ? " | Finish: ".date('H:i', strtotime($end)) : '' ?>
          <?php elseif ($time): ?>
            <?= date('H:i', strtotime($time)) ?>
          <?php else: ?>
            -
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
