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
$conn->query("SET SESSION sql_mode = ''");
$stmt = $conn->prepare("SELECT * FROM flights WHERE user_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$flight = $stmt->get_result()->fetch_assoc();


if (!$flight) {
    die("Aktif uçuş bulunamadı.");
}

$departure_processes = ['gpu', 'crew', 'fueling', 'catering', 'loading', 'preboarding', 'boarding', 'firstpax', 'doorclosed', 'pushback', 'offblock'];

$departure_buttons = [
    'gpu' => ['start', 'finish', 'not_used'],
    'crew' => ['opened', 'reset'],
    'preboarding' => ['opened', 'reset'],
	'boarding' => ['start', 'finish', 'not_used'],
    'firstpax' => ['opened', 'reset'],
    'pushback' => ['opened', 'reset'],
    'offblock' => ['opened', 'reset'],
	'doorsclosed' => ['opened', 'reset'],
    'fueling' => ['start', 'finish', 'not_used'],
    'catering' => ['start', 'finish', 'not_used'],
    'loading' => ['start', 'finish', 'not_used']
];

$block_fuel = $flight['blockfuel'] ?? '';
$ctot_time = $flight['ctot'] ? date('H:i', strtotime($flight['ctot'])) : '';
$final_pax = $flight['final_pax'] ?? '';
$departure_note = $flight['departure_note'] ?? '';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <title>Turnaround Process</title>
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link href="assets/css/vendor.min.css" rel="stylesheet" />
  <link href="assets/css/icons.min.css" rel="stylesheet" />
  <link href="assets/css/app.min.css" rel="stylesheet" />
  <script src="assets/js/config.js"></script>
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
 

  <h3 class="mb-4">Departure Process</h3>
  <?php foreach ($departure_processes as $surec): 
    $status = $flight[$surec . '_status'] ?? 'not_started';
    $start_time = $flight[$surec . '_start_time'] ?? null;
    $end_time = $flight[$surec . '_end_time'] ?? null;
  ?>
<div class="mb-4" data-status="<?= $status ?>">
      <h5 class="mb-2 text-uppercase"><?= strtoupper($surec) ?></h5>
      <?php if (!in_array($surec, ['crew', 'preboarding', 'firstpax', 'doorclosed', 'pushback', 'offblock',])): ?>
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
    <h5 class="mb-2 text-uppercase">BLOCK FUEL / CTOT / FINAL PAX</h5>
    <div class="row g-2">
      <div class="col-md-3">
        <input type="number" id="blockfuel_input" class="form-control" placeholder="Block Fuel" value="<?= $block_fuel ?>">
      </div>
      <div class="col-md-3">
        <input type="time" id="ctot_input" class="form-control" value="<?= $ctot_time ?>">
      </div>
      <div class="col-md-3">
        <input type="text" id="final_pax_input" class="form-control" placeholder="Final Pax" value="<?= $final_pax ?>">
      </div>
    </div>
    <div class="mt-3">
      <textarea id="departure_note_input" class="form-control" rows="4" placeholder="Departure Note"><?= $departure_note ?></textarea>
    </div>
    <div class="mt-2">
      <button class="btn btn-success" onclick="submitDepartureData()">KAYDET</button>
    </div>
  </div>
<button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#ldmModal"> LDM Oluştur</button>  
<!-- Buton -->
<button type="button" class="btn btn-warning" data-toggle="modal" data-target="#delayCalculatorModal">
  Gecikme Hesaplayıcı
</button>

 <!-- LDM Creator Modal -->

  <div class="modal fade" id="ldmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">LDM Creator</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">

        <div class="row g-2 mb-2">
          <div class="col-md-6">
            <label>Flight No</label><input type="text" id="ldm_flightno" class="form-control" readonly value="<?= ($flight['iata_code'] ?? '') . ($flight['departure_flight_number'] ?? '') ?>">
          </div>
          <div class="col-md-6">
            <label>Registration</label>
            <input type="text" id="ldm_reg" class="form-control" readonly value="<?= isset($flight['tail_number']) ? str_replace('-', '', $flight['tail_number']) : '' ?>">
          </div>
        </div>

        <div class="row g-2 mb-2">
          <div class="col-md-6">
  <label>STD Date</label>
  <input type="text" id="ldm_date" class="form-control" readonly value="<?= isset($flight['departure_date_time']) ? date('d', strtotime($flight['departure_date_time'])) : '' ?>">
</div>

          <div class="col-md-6">
            <label>Destination</label>
            <input type="text" id="ldm_dest" class="form-control" readonly value="<?= $flight['departuredest'] ?? '' ?>">
          </div>
        </div>

        <div class="row g-2 mb-2">
          <div class="col-md-3"><label>Male</label><input type="number" id="ldm_male" class="form-control" value="0" oninput="updateTotals()"></div>
          <div class="col-md-3"><label>Female</label><input type="number" id="ldm_female" class="form-control" value="0" oninput="updateTotals()"></div>
          <div class="col-md-3"><label>Child</label><input type="number" id="ldm_child" class="form-control" value="0" oninput="updateTotals()"></div>
          <div class="col-md-3"><label>Infant</label><input type="number" id="ldm_infant" class="form-control" value="0"></div>
        </div>

        <div class="mb-2">
          <label>Toplam PAX (INF hariç)</label>
          <input type="text" id="pax_total" class="form-control" readonly>
        </div>

        <div class="mb-2">
          <label>Kompartmanlara Yük Dağılımı (kg)</label>
          <div class="row g-2">
            <div class="col"><input type="number" id="comp1" class="form-control" value="0" oninput="updateTotals()"></div>
            <div class="col"><input type="number" id="comp2" class="form-control" value="0" oninput="updateTotals()"></div>
            <div class="col"><input type="number" id="comp3" class="form-control" value="0" oninput="updateTotals()"></div>
            <div class="col"><input type="number" id="comp4" class="form-control" value="0" oninput="updateTotals()"></div>
            <div class="col"><input type="number" id="comp5" class="form-control" value="0" oninput="updateTotals()"></div>
          </div>
        </div>

        <div class="mb-2">
          <label>Toplam Kompartman Yükü (T):</label>
          <input type="text" id="comp_total" class="form-control" readonly>
        </div>

        <div class="row g-2 mb-2">
          <div class="col"><label>Baggage</label><input type="number" id="baggage" class="form-control" value="0" oninput="updateTotals()"></div>
          <div class="col"><label>Cargo</label><input type="number" id="cargo" class="form-control" value="0" oninput="updateTotals()"></div>
          <div class="col"><label>Mail</label><input type="number" id="mail" class="form-control" value="0" oninput="updateTotals()"></div>
        </div>

        <div class="mb-2">
          <label>Toplam Yük (B+C+M)</label>
          <input type="text" id="bcm_total" class="form-control" readonly>
        </div>

        <div class="mb-2">
          <label>PAD Count</label>
          <input type="number" id="ldm_pad" class="form-control" value="0">
        </div>

        <div class="mb-2">
          <label>Seat Config</label>
          <input type="number" id="ldm_seatconfig" class="form-control" value="189">
        </div>

        <div class="mb-2">
          <label>Crew Count</label>
          <input type="text" id="ldm_crew" class="form-control" value="2/4">
        </div>

        <div class="mb-2">
          <label>SI</label>
          <input type="text" id="ldm_si" class="form-control" value="">
        </div>

        <div class="mb-3">
          <label>Generated LDM</label>
          <textarea id="ldm_output" class="form-control" rows="5" readonly></textarea>
        </div>

      </div>
      <div class="modal-footer">
        <button class="btn btn-primary" onclick="generateLDM()">Oluştur</button>
        <button class="btn btn-primary" onclick="copyLDM()">Kopyala</button>
      </div>
    </div>
  </div>
</div>

<!-- LDM Creator Modal END -->

</div>
<script src="assets/js/vendor.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

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
  const touchdown = document.getElementById('touchdown_input').value;
  const onblock = document.getElementById('onblock_input').value;
  const remainingfuel = document.getElementById('remainingfuel_input').value;
  const arrivalNote = document.getElementById('arrival_note_input').value;

  fetch("process_handler.php", {
    method: "POST",
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      action: "save_arrival_data",
      touchdown: touchdown,
      onblock: onblock,
      remainingfuel: remainingfuel,
      arrival_note: arrivalNote
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
<script>
function updateTotals() {
  const male = parseInt(document.getElementById('ldm_male').value) || 0;
  const female = parseInt(document.getElementById('ldm_female').value) || 0;
  const child = parseInt(document.getElementById('ldm_child').value) || 0;
  const paxTotal = male + female + child;
  document.getElementById('pax_total').value = paxTotal;

  const compIds = ['comp1', 'comp2', 'comp3', 'comp4', 'comp5'];
  let compTotal = 0;
  compIds.forEach(id => {
    compTotal += parseInt(document.getElementById(id).value) || 0;
  });
  document.getElementById('comp_total').value = compTotal;

  const baggage = parseInt(document.getElementById('baggage').value) || 0;
  const cargo = parseInt(document.getElementById('cargo').value) || 0;
  const mail = parseInt(document.getElementById('mail').value) || 0;
  const bcmTotal = baggage + cargo + mail;
  document.getElementById('bcm_total').value = bcmTotal;
}

function generateLDM() {
  const flightNo = document.getElementById('ldm_flightno').value;
  const date = document.getElementById('ldm_date').value;
  const reg = document.getElementById('ldm_reg').value;
  const seatConfig = document.getElementById('ldm_seatconfig').value;
  const crew = document.getElementById('ldm_crew').value;
  const dest = document.getElementById('ldm_dest').value;

  const male = parseInt(document.getElementById('ldm_male').value) || 0;
  const female = parseInt(document.getElementById('ldm_female').value) || 0;
  const child = parseInt(document.getElementById('ldm_child').value) || 0;
  const infant = parseInt(document.getElementById('ldm_infant').value) || 0;
  const paxCount = male + female + child;

  const baggage = parseInt(document.getElementById('baggage').value) || 0;
  const cargo = parseInt(document.getElementById('cargo').value) || 0;
  const mail = parseInt(document.getElementById('mail').value) || 0;
  const pad = parseInt(document.getElementById('ldm_pad').value) || 0;

  const si = document.getElementById('ldm_si').value;

  // T ve kompartman dizilimi
  const compValues = [];
  let totalWeight = 0;
  for (let i = 1; i <= 5; i++) {
    const val = parseInt(document.getElementById('comp' + i).value) || 0;
    totalWeight += val;
    if (val > 0) {
      compValues.push(`${i}/${val}`);
    }
  }

  const compartmentPart = `T${totalWeight}.` + compValues.join('.') + '.';

  const line1 = `LDM\n${flightNo}/${date}.${reg}.Y${seatConfig}.${crew}`;
  const line2 = `-${dest}.${String(male).padStart(2, '0')}/${String(female).padStart(2, '0')}/${String(child).padStart(2, '0')}/${String(infant).padStart(2, '0')}.${compartmentPart}PAX/${paxCount}.PAD/${pad}.B/${baggage}.C/${cargo}.M/${mail}`;
  const line3 = `SI\n${si}`;

  document.getElementById('ldm_output').value = `${line1}\n${line2}\n${line3}`;
}


function copyLDM() {
  const text = document.getElementById('ldm_output').value;
  navigator.clipboard.writeText(text).then(() => {
    alert("LDM başarıyla kopyalandı!");
  });
}


</script>


</body>
</html>