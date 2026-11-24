<?php
require_once __DIR__ . '/beta_debug.php';
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/operation_functions.php';
$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!$user_id) { http_response_code(401); exit('Oturum açılmalı.'); }
function safe($value): string { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8'); }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $conn->prepare("DELETE FROM flights WHERE id = ? AND user_id = ?");
    $stmt->bind_param('ii', $id, $user_id);
    $stmt->execute();
    echo $stmt->affected_rows ? 'Uçuş silindi.' : 'Uçuş bulunamadı.';
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $check = $conn->prepare("SELECT id FROM flights WHERE id = ? AND user_id = ? AND deleted_at IS NULL");
    $check->bind_param('ii', $id, $user_id);
    $check->execute();
    if (!$check->get_result()->fetch_assoc()) { http_response_code(403); exit('Bu uçuş size ait değil.'); }
    $tail = trim((string)($_POST['tail_number'] ?? ''));
    $arrival = trim((string)($_POST['arrival_flight_number'] ?? ''));
    $departure = trim((string)($_POST['departure_flight_number'] ?? ''));
    $parking = trim((string)($_POST['parkposition'] ?? ''));
    $arrivalDate = trim((string)($_POST['arrival_date'] ?? ''));
    $arrivalTime = trim((string)($_POST['scheduled_arrival'] ?? ''));
    $departureDate = trim((string)($_POST['departure_date'] ?? ''));
    $departureTime = trim((string)($_POST['scheduled_departure'] ?? ''));
    $arrivalAt = $arrivalDate !== '' && $arrivalTime !== '' ? $arrivalDate . ' ' . $arrivalTime . ':00' : null;
    $departureAt = $departureDate !== '' && $departureTime !== '' ? $departureDate . ' ' . $departureTime . ':00' : null;
    $stmt = $conn->prepare("UPDATE flights SET tail_number = ?, arrival_flight_number = ?, departure_flight_number = ?, stand = ?, scheduled_arrival_at = ?, scheduled_departure_at = ? WHERE id = ? AND user_id = ?");
    $stmt->bind_param('ssssssii', $tail, $arrival, $departure, $parking, $arrivalAt, $departureAt, $id, $user_id);
    $stmt->execute();
    echo 'Uçuş bilgisi güncellendi.';
    exit;
}
$id = (int)($_GET['id'] ?? 0);
$edit = isset($_GET['edit']);
$stmt = $conn->prepare("SELECT * FROM flights WHERE id = ? AND user_id = ? AND deleted_at IS NULL");
$stmt->bind_param('ii', $id, $user_id);
$stmt->execute();
$flight = $stmt->get_result()->fetch_assoc();
if (!$flight) { http_response_code(404); exit('Uçuş bulunamadı.'); }
$flight = op_legacy_flight($conn, $flight);
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
    $processes = [];
    $defs = op_process_keys();
    if ($type === 'arrival' || $type === 'turnaround') {
        foreach ($defs['arrival'] as $key) $processes[] = ['arrival', $key];
    }
    if ($type === 'departure' || $type === 'turnaround') {
        foreach ($defs['departure'] as $key) $processes[] = ['departure', $key];
    }
    foreach ($processes as [$phase, $proc]):
        $row = $flight['_process_rows'][$phase][$proc] ?? [];
        $start = $row['started_at'] ?? null;
        $end = $row['finished_at'] ?? null;
        $time = $row['value_datetime'] ?? null;
    ?>
      <tr>
        <td><?= safe(ucwords(str_replace('_', ' ', $proc)) . ($type === 'turnaround' ? ' (' . ucfirst($phase) . ')' : '')) ?></td>
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
