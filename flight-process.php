<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "db.php";
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    die("Giriş yapılmamış.");
}

$conn->query("SET sql_mode = ''");

$stmt = $conn->prepare("SELECT * FROM flights WHERE user_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$flight = $stmt->get_result()->fetch_assoc();

if (!$flight) {
    die("Aktif uçuş yok.");
}

$flight_id = $flight['id'];
$flight_type_id = (int)($flight['flight_type_id'] ?? 0);

$proc_query = $conn->query("
SELECT 
    pt.id,
    pt.name,
    pt.input_type,
    pt.icon,
    fp.value_enum,
    fp.start_time,
    fp.finish_time,
    fp.value_datetime,
    fp.value_text
FROM flight_type_process_map m
JOIN process_types pt ON m.process_type_id = pt.id
LEFT JOIN flight_processes fp ON fp.flight_id = $flight_id AND fp.process_type_id = pt.id
WHERE m.flight_type_id = $flight_type_id
ORDER BY m.order_no ASC
");

if (!$proc_query) {
    die("SQL HATASI: " . $conn->error . " | Flight Type ID: " . $flight_type_id);
}

$processes = $proc_query->fetch_all(MYSQLI_ASSOC);
?>

<div class="process-container">
  <?php foreach ($processes as $p): 
    $status = $p['value_enum'] ?? 'not_started';
    $start_time = $p['start_time'] ?? null;
    $finish_time = $p['finish_time'] ?? null;
    $id = $p['id'];
    $surec = $p['name'];
    $input_type = $p['input_type'];
  ?>
    <div class="mb-4" data-status="<?= $status ?>">
      <h5 class="mb-2 text-uppercase"><?= strtoupper($surec) ?></h5>

      <?php if ($input_type === 'enum'): ?>
        <button class="btn btn-outline-primary me-2 mb-2"
                data-id="<?= $id ?>" data-action="start"
                onclick="handleProcess(this)" <?= $status !== 'not_started' ? 'disabled' : '' ?>>
          <?= $start_time ? date('H:i', strtotime($start_time)) : 'START' ?>
        </button>

        <button class="btn btn-outline-success me-2 mb-2"
                data-id="<?= $id ?>" data-action="finish"
                onclick="handleProcess(this)" <?= $status !== 'started' ? 'disabled' : '' ?>>
          <?= $finish_time ? date('H:i', strtotime($finish_time)) : 'FINISH' ?>
        </button>

        <button class="btn btn-outline-secondary me-2 mb-2"
                data-id="<?= $id ?>" data-action="not_used"
                onclick="handleProcess(this)" <?= $status !== 'not_started' ? 'disabled' : '' ?>>
          <?= $status === 'not_used' ? 'X' : 'NOT USED' ?>
        </button>

        <button class="btn btn-outline-danger mb-1 reset-button"
                onclick="toggleResetOptions(this)" <?= $status === 'not_started' ? 'disabled' : '' ?>>RESET</button>
        <div class="reset-menu" style="display: none;">
          <button class="btn btn-sm btn-outline-dark" data-id="<?= $id ?>" data-target="start" onclick="applyReset(this)">Start</button>
          <button class="btn btn-sm btn-outline-dark" data-id="<?= $id ?>" data-target="finish" onclick="applyReset(this)">Finish</button>
          <button class="btn btn-sm btn-outline-dark not-used-reset" data-id="<?= $id ?>" data-target="not_used" onclick="applyReset(this)">Not Used</button>
        </div>

      <?php elseif ($input_type === 'datetime'): ?>
        <button class="btn btn-outline-primary me-2 mb-2"
                onclick="submitSingleButton(this)" data-id="<?= $id ?>"
                <?= $p['value_datetime'] ? 'disabled' : '' ?>>
          <?= $p['value_datetime'] ? date('H:i', strtotime($p['value_datetime'])) : strtoupper($surec) ?>
        </button>
        <button class="btn btn-outline-danger me-2 mb-2"
                onclick="resetSingleButton('<?= $id ?>')">RESET</button>

      <?php elseif ($input_type === 'text'): ?>
        <textarea id="text_input_<?= $id ?>" class="form-control mb-2"><?= htmlspecialchars($p['value_text'] ?? '') ?></textarea>
        <button class="btn btn-outline-info" onclick="submitTextInput(<?= $id ?>)">KAYDET</button>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>




<script>
function handleProcess(button) {
  const action = button.dataset.action;
  const processId = button.dataset.id;
  const parent = button.parentElement;

  fetch("process_handler.php", {
    method: "POST",
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      action: action,
      process_id: processId
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
      if (action === "finish") parent.dataset.status = 'finished';
      if (action === "not_used") parent.dataset.status = 'not_used';

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
  const processId = button.dataset.id;
  const target = button.dataset.target;
  const parent = button.closest(".mb-4");

    if (!confirm(`${target.toUpperCase()} alanını sıfırlamak istediğinize emin misin?`)) return;

  fetch("process_handler.php", {
    method: "POST",
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      action: "reset",
      process_id: processId,
      reset_target: target
    })
  })
  .then(res => res.json())
  .then(data => {
  if (data.status === "success") {
    parent.dataset.status = data.new_status || 'not_started';

    const startBtn = parent.querySelector("button[data-action='start']");
    const finishBtn = parent.querySelector("button[data-action='finish']");
    const notUsedBtn = parent.querySelector("button[data-action='not_used']");
    const resetBtn = parent.querySelector(".reset-button");

    if (target === "start" && startBtn) {
      startBtn.disabled = false;
      startBtn.textContent = "START";
      if (finishBtn) {
        finishBtn.disabled = true;
        finishBtn.textContent = "FINISH";
      }
      if (notUsedBtn) {
        notUsedBtn.disabled = false;
        notUsedBtn.textContent = "NOT USED";
      }
    }

    if (target === "finish" && finishBtn) {
      finishBtn.disabled = false;
      finishBtn.textContent = "FINISH";
    }

    if (target === "not_used" && notUsedBtn) {
      notUsedBtn.disabled = false;
      notUsedBtn.textContent = "NOT USED";
    }
    if (target === "not_used" && notUsedBtn) {
  notUsedBtn.disabled = false;
  notUsedBtn.textContent = "NOT USED";
  if (startBtn) {
    startBtn.disabled = false;
    startBtn.textContent = "START";
  }
  if (finishBtn) {
    finishBtn.disabled = true;
    finishBtn.textContent = "FINISH";
  }
}


    if (resetBtn) resetBtn.disabled = false;

    const menu = button.closest(".reset-menu");
    if (menu) menu.style.display = "none";
    

    updateResetButtons(parent);
  } else {
    alert("Hata: " + data.message);
  }
});

}

//reset kurallaarı
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
  const processId = button.dataset.id;

  fetch("process_handler.php", {
    method: "POST",
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ action: "mark_time", process_id: processId })
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



function resetSingleButton(processId) {
  if (!confirm("Sıfırlamak istediğinize emin misiniz?")) return;

  fetch("process_handler.php", {
    method: "POST",
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({ action: "reset_single", process_id: processId })
  })
  .then(res => res.json())
  .then(data => {
    if (data.status === "success") {
      const button = document.querySelector(`button[data-id="${processId}"]`);
      if (button) {
        button.textContent = "START";
        button.disabled = false;
      }
    } else {
      alert("Hata: " + data.message);
    }
  });
}


function submitTextInput(processId) {
  const textarea = document.getElementById('text_input_' + processId);
  const value = textarea.value;

  fetch("process_handler.php", {
    method: "POST",
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      action: "save_text_input",
      process_id: processId,
      value: value
    })
  })
  .then(res => res.json())
  .then(data => {
    alert(data.message);
  });
}
</script>
</body>
</html>
