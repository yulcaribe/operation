<?php
require_once __DIR__ . '/beta_debug.php';
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once "db.php";

$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    die("Kullanıcı oturumu yok.");
}
$conn->query("SET sql_mode = ''");
$stmt = $conn->prepare("SELECT * FROM flights WHERE user_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$flight = $stmt->get_result()->fetch_assoc();

if (!$flight) {
    die("Aktif uçuş bulunamadı.");
}

$arrival_processes = ['doorsopening', 'gpu', 'unloading', 'deboarding', 'arrivalcrewcar'];

$arrival_buttons = [
    'gpu' => ['start', 'finish', 'not_used'],
    'unloading' => ['start', 'finish', 'not_used'],
    'deboarding' => ['start', 'finish', 'not_used'],
    'doorsopening' => ['opened', 'reset'],
    'arrivalcrewcar' => ['arrived', 'reset']
];

$touchdown_time = $flight['touchdown'] ? date('H:i', strtotime($flight['touchdown'])) : '';
$touchdown_date = $flight['touchdown'] ? date('Y-m-d', strtotime($flight['touchdown'])) : date('Y-m-d');
$onblock_date = $flight['onblock'] ? date('Y-m-d', strtotime($flight['onblock'])) : date('Y-m-d');
$onblock_time = $flight['onblock'] ? date('H:i', strtotime($flight['onblock'])) : '';
$remaining_fuel = $flight['remainingfuel'] ?? '';
$arrival_note = $flight['notes'] ?? '';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <title>Turnaround Process</title>
  <link href="assets/css/app.min.css" rel="stylesheet" />
  <style>
    .reset-menu {
      display: none;
      margin-top: 5px;
    }
    .reset-menu button {
      margin-right: 5px;
    }
  </style>
</head>
<body>
<div class="container-xl">
  <h3 class="mb-4">Arrival Process</h3>
  <?php foreach ($arrival_processes as $surec): 
    $status = $flight[$surec . '_status'] ?? 'not_started';
    $start_time = $flight[$surec . '_start_time'] ?? null;
    $end_time = $flight[$surec . '_end_time'] ?? null;
  ?>
<div class="mb-4" data-status="<?= $status ?>">
      <h5 class="mb-2 text-uppercase"><?= strtoupper($surec) ?></h5>
      <?php if (!in_array($surec, ['doorsopening', 'arrivalcrewcar'])): ?>
        <button class="btn btn-outline-primary me-2 mb-2" data-type="<?= $surec ?>" data-action="start" onclick="handleProcess(this)" <?= $status !== 'not_started' ? 'disabled' : '' ?>><?= $start_time ? date('H:i', strtotime($start_time)) : 'START' ?></button>
        <button class="btn btn-outline-success me-2 mb-2" data-type="<?= $surec ?>" data-action="finish" onclick="handleProcess(this)" <?= $status !== 'started' ? 'disabled' : '' ?>><?= $end_time ? date('H:i', strtotime($end_time)) : 'FINISH' ?></button>
        <button class="btn btn-outline-secondary me-2 mb-2" data-type="<?= $surec ?>" data-action="not_used" onclick="handleProcess(this)" <?= $status !== 'not_started' ? 'disabled' : '' ?>><?= $status === 'not_used' ? 'X' : 'NOT USED' ?></button>
        <button class="btn btn-outline-danger mb-1 reset-button" onclick="toggleResetOptions(this)" <?= $status === 'not_started' ? 'disabled' : '' ?>>RESET</button>
        <div class="reset-menu">
          <button class="btn btn-sm btn-outline-dark" data-type="<?= $surec ?>" data-target="start" onclick="applyReset(this)" <?= $end_time ? 'disabled' : '' ?>>Start</button>
          <button class="btn btn-sm btn-outline-dark" data-type="<?= $surec ?>" data-target="finish" onclick="applyReset(this)">Finish</button>
          <button class="btn btn-sm btn-outline-dark not-used-reset" data-type="<?= $surec ?>" data-target="not_used" onclick="applyReset(this)">Not Used</button>
        </div>
      <?php else: ?>
        <button class="btn btn-outline-primary me-2 mb-2" onclick="submitSingleButton(this)" data-type="<?= $surec ?>" data-action="mark_time" <?= $flight[$surec] ? 'disabled' : '' ?>><?= $flight[$surec] ? date('H:i', strtotime($flight[$surec])) : strtoupper($surec) ?></button>
        <button class="btn btn-outline-danger me-2 mb-2" onclick="resetSingleButton('<?= $surec ?>')">RESET</button>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>

<div class="mb-4">
  <h5 class="mb-2 text-uppercase">TOUCHDOWN / ONBLOCK / REMAINING FUEL</h5>

  <!-- Touchdown alanı -->
  <div class="p-3 mb-2 border border-light rounded" style="border-style: dashed;">
    <label class="form-label">Touchdown</label>
    <div class="row g-2">
      <div class="col-md-6">
        <input type="time" id="touchdown_input" class="form-control" value="<?= $touchdown_time ?>">
      </div>
      <div class="col-md-6">
        <input type="date" id="touchdown_date" class="form-control" value="<?= $touchdown_date ?>">
      </div>
    </div>
  </div>

  <!-- Onblock alanı -->
  <div class="p-3 mb-2 border border-light rounded" style="border-style: dashed;">
    <label class="form-label">Onblock</label>
    <div class="row g-2">
      <div class="col-md-6">
        <input type="time" id="onblock_input" class="form-control" value="<?= $onblock_time ?>">
      </div>
      <div class="col-md-6">
        <input type="date" id="onblock_date" class="form-control" value="<?= $onblock_date ?>">
      </div>
    </div>
  </div>

  <!-- Remaining fuel -->
  <div class="row g-2 mb-3">
    <div class="col-md-6">
      <input type="text" id="remainingfuel_input" class="form-control" placeholder="Remaining Fuel" value="<?= $remaining_fuel ?>">
    </div>
  </div>

  <div class="mt-3">
    <textarea id="arrival_note_input" class="form-control" rows="4" placeholder="Arrival Note"><?= $arrival_note ?></textarea>
  </div>

  <div class="mt-2">
    <button class="btn btn-success" onclick="submitArrivalData()">KAYDET</button>
  </div>
</div>

<script>
function handleProcess(button) {
  const action = button.dataset.action;
  const processType = button.dataset.type;
  const parent = button.parentElement;

  fetch("process_handler.php", {
    method: "POST",
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      action: action,
      process_type: processType
    })
  })
  .then(res => res.json())
  .then(data => {
    if (data.status === "success") {
      const now = new Date();
      const timeStr = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
      button.textContent = timeStr;
      const buttons = parent.querySelectorAll("button");
      buttons.forEach(btn => {
        const btnAction = btn.dataset.action;
        if (btnAction === "start" || btnAction === "finish" || btnAction === "not_used") {
          btn.disabled = true;
        }
      });
      if (action === "start") {
        const finishBtn = parent.querySelector("button[data-action='finish']");
        if (finishBtn) finishBtn.disabled = false;
        parent.dataset.status = 'started';
      }
      if (action === "finish") {
        parent.dataset.status = 'finished';
      }
      if (action === "not_used") {
        parent.dataset.status = 'not_used';
      }
      const resetBtn = parent.querySelector(".reset-button");
      if (resetBtn) resetBtn.disabled = false;
      updateResetButtons(parent);
    } else {
      alert("Hata: " + data.message);
    }
  });
}

function toggleResetOptions(button) {
  if (button.disabled) return;
  const menu = button.nextElementSibling;
  menu.style.display = menu.style.display === "block" ? "none" : "block";
}

function applyReset(button) {
  const processType = button.dataset.type;
  const target = button.dataset.target;
  const parent = button.closest(".mb-4");

  if (!confirm(`${target.toUpperCase()} alanını sıfırlamak istediğinize emin misin?`)) return;

  fetch("process_handler.php", {
    method: "POST",
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      action: "reset",
      process_type: processType,
      reset_target: target
    })
  })
  .then(res => res.json())
  .then(data => {
    if (data.status === "success") {
      parent.dataset.status = data.new_status || 'active';
      updateResetButtons(parent);
      location.reload();
    } else {
      alert("Hata: " + data.message);
    }
  });
}

function updateResetButtons(parent) {
  const status = parent.dataset.status;
  const resetMenu = parent.querySelector(".reset-menu");
  if (!resetMenu) return;

  const resetStart = resetMenu.querySelector("button[data-target='start']");
  const resetFinish = resetMenu.querySelector("button[data-target='finish']");
  const resetNotUsed = resetMenu.querySelector("button[data-target='not_used']");

  if (status === 'started') {
    resetStart.disabled = false;
    resetFinish.disabled = true;
    resetNotUsed.disabled = true;
  } else if (status === 'finished') {
    resetStart.disabled = true;
    resetFinish.disabled = false;
    resetNotUsed.disabled = true;
  } else if (status === 'not_used') {
    resetStart.disabled = true;
    resetFinish.disabled = true;
    resetNotUsed.disabled = false;
  } 
}

document.addEventListener("DOMContentLoaded", () => {
  document.querySelectorAll(".mb-4").forEach(parent => {
    updateResetButtons(parent);
  });
});

function submitSingleButton(button) {
  const type = button.dataset.type;

  fetch("process_handler.php", {
    method: "POST",
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ action: "mark_time", process_type: type })
  })
  .then(res => res.json())
  .then(data => {
    if (data.status === "success") {
      const now = new Date();
      const timeStr = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
      button.textContent = timeStr;
      button.disabled = true;

      const parent = button.parentElement;
      const resetBtn = parent.querySelector(".reset-button");
      if (resetBtn) resetBtn.disabled = false;
    } else {
      alert("Hata: " + data.message);
    }
  });
}

function resetSingleButton(type) {
  if (!confirm("Sıfırlamak istediğinize emin misiniz?")) return;
  fetch("process_handler.php", {
    method: "POST",
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ action: "reset_single", process_type: type })
  })
  .then(res => res.json())
  .then(data => location.reload());
}

function submitArrivalData() {
  const touchdown_time = document.getElementById('touchdown_input').value;
  const touchdown_date = document.getElementById('touchdown_date').value;
  const onblock_time = document.getElementById('onblock_input').value;
  const onblock_date = document.getElementById('onblock_date').value;
  const remainingfuel = document.getElementById('remainingfuel_input').value;
  const arrivalNote = document.getElementById('arrival_note_input').value;

  fetch("process_handler.php", {
    method: "POST",
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      action: "save_arrival_data",
      touchdown_time: touchdown_time,
      touchdown_date: touchdown_date,
      onblock_time: onblock_time,
      onblock_date: onblock_date,
      remainingfuel: remainingfuel,
      arrival_note: arrivalNote,
      flight_id: <?= $flight['id'] ?>
    })
  })
  .then(res => res.json())
  .then(data => alert(data.message));
}


function submitDepartureData() {
  const blockfuel = document.getElementById('blockfuel_input').value;
  const ctot = document.getElementById('ctot_input').value;
  const finalPax = document.getElementById('final_pax_input').value;
  const departureNote = document.getElementById('departure_note_input').value;

  fetch("process_handler.php", {
    method: "POST",
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      action: "save_departure_data",
      blockfuel: blockfuel,
      ctot: ctot,
      final_pax: finalPax,
      departure_note: departureNote
    })
  })
  .then(res => res.json())
  .then(data => alert(data.message));
}
</script>
