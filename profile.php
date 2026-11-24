<?php
require_once __DIR__ . '/beta_debug.php';
session_start();
require_once __DIR__ . '/db.php';
$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!$user_id) { header('Location: login.php'); exit; }
$message = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $first_name = trim((string)($_POST['first_name'] ?? ''));
    $last_name = trim((string)($_POST['last_name'] ?? ''));
    $tgs_sicil_no = trim((string)($_POST['tgs_sicil_no'] ?? ''));
    $pozisyon = trim((string)($_POST['pozisyon'] ?? ''));
    $current_password = (string)($_POST['current_password'] ?? '');
    $new_password = (string)($_POST['new_password'] ?? '');
    $stmt = $conn->prepare("SELECT password, must_change_password FROM users WHERE id = ? AND status = 'active' AND deleted_at IS NULL");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $credentials = $stmt->get_result()->fetch_assoc();
    if (!$credentials || !password_verify($current_password, $credentials['password'])) {
        $message = 'Geçerli şifreniz hatalı.';
    } elseif ($new_password !== '' && strlen($new_password) < 8) {
        $message = 'Yeni şifre en az 8 karakter olmalı.';
    } elseif ($new_password === '' && (int)$credentials['must_change_password'] === 1) {
        $message = 'Devam etmek için yeni şifre belirleyin.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Geçersiz e-posta adresi.';
    } else {
        $email = $email === '' ? null : $email;
        try {
            if ($new_password !== '') {
                $hash = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET email = ?, first_name = ?, last_name = ?, tgs_sicil_no = ?, pozisyon = ?, password = ?, must_change_password = 0 WHERE id = ?");
                $stmt->bind_param('ssssssi', $email, $first_name, $last_name, $tgs_sicil_no, $pozisyon, $hash, $user_id);
            } else {
                $stmt = $conn->prepare("UPDATE users SET email = ?, first_name = ?, last_name = ?, tgs_sicil_no = ?, pozisyon = ? WHERE id = ?");
                $stmt->bind_param('sssssi', $email, $first_name, $last_name, $tgs_sicil_no, $pozisyon, $user_id);
            }
            $stmt->execute();
            $message = 'Profil güncellendi.';
        } catch (mysqli_sql_exception $e) {
            error_log('[Operation BETA] profile: ' . $e->getMessage());
            $message = 'Profil güncellenemedi.';
        }
    }
}
$stmt = $conn->prepare("SELECT username, email, first_name, last_name, profile_picture, tgs_sicil_no, pozisyon, must_change_password FROM users WHERE id = ? AND deleted_at IS NULL");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
if (!$user) { session_destroy(); header('Location: login.php'); exit; }
$profile_picture = !empty($user['profile_picture']) && is_file(__DIR__ . '/' . $user['profile_picture'])
  ? $user['profile_picture'] : 'assets/images/users/dummypp.png';
$username = $user['username'];
$email = $user['email'] ?? '';
$first_name = $user['first_name'] ?? '';
$last_name = $user['last_name'] ?? '';
$tgs_sicil_no = $user['tgs_sicil_no'] ?? '';
$pozisyon = $user['pozisyon'] ?? '';
$showForm = (int)$user['must_change_password'] === 1 || isset($_GET['change_password']) || $message !== null;
?>

<!DOCTYPE html>
<html lang="tr">
<head>
     <meta charset="utf-8" />
     <title>Profile | Operation</title>
     <meta name="viewport" content="width=device-width, initial-scale=1.0">
     <meta name="description" content="Profile page" />
     <meta name="author" content="Techzaa" />
     <link rel="shortcut icon" href="assets/images/favicon.ico">
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
     <div class="container-xxl">
          <div class="row">
               <div class="col-xl-9 col-lg-8">
                    <div class="card overflow-hidden">
                         <div class="card-body">
                              <div class="bg-primary profile-bg rounded-top position-relative mx-n3 mt-n3">
                                   <img src="<?php echo $profile_picture; ?>" alt="" class="avatar-xl border border-light border-3 rounded-circle position-absolute top-100 start-0 translate-middle ms-5">
                              </div>
                              <div class="mt-5 d-flex flex-wrap align-items-center justify-content-between">
                                   <div>
                                        <h4 class="mb-1"><?php echo htmlspecialchars(($first_name ?? '') . ' ' . ($last_name ?? '')); ?></h4>
                                        <p class="mb-0 text-muted"><?php echo htmlspecialchars($username); ?></p>
                                        <p class="mb-0 text-warning fw-semibold"><?php echo htmlspecialchars($pozisyon); ?></p>
                                   </div>
                                   <div class="d-flex align-items-center gap-2 my-2 my-lg-0">
                                        <button id="editProfileBtn" class="btn btn-info"><i class='bx bx-settings'></i> Edit</button>
                                   </div>
                              </div>
                              <?php if ($message): ?>
                                <div class="alert alert-info mt-3"><?php echo $message; ?></div>
                              <?php endif; ?>
                              <?php if ((int)$user['must_change_password'] === 1): ?><div class="alert alert-warning mt-3">İlk girişinizde yeni bir şifre belirleyin.</div><?php endif; ?>
                              <form method="POST" id="editProfileForm" class="mt-4 <?= $showForm ? "" : "d-none" ?>">
                                  <div class="row">
                                      <div class="col-md-6 mb-3">
                                          <label for="email" class="form-label">E-posta</label>
                                          <input type="email" name="email" id="email" class="form-control" value="<?= htmlspecialchars($email) ?>">
                                      </div>
                                      <div class="col-md-6 mb-3">
                                          <label for="first_name" class="form-label">İsim</label>
                                          <input type="text" name="first_name" id="first_name" class="form-control" value="<?= htmlspecialchars($first_name ?? '') ?>">
                                      </div>
                                      <div class="col-md-6 mb-3">
                                          <label for="last_name" class="form-label">Soyisim</label>
                                          <input type="text" name="last_name" id="last_name" class="form-control" value="<?= htmlspecialchars($last_name ?? '') ?>">
                                      </div>
                                      <div class="col-md-6 mb-3">
                                          <label for="tgs_sicil_no" class="form-label">TGS Sicil No</label>
                                          <input type="text" name="tgs_sicil_no" id="tgs_sicil_no" class="form-control" value="<?= htmlspecialchars($tgs_sicil_no) ?>">
                                      </div>
                                      <div class="col-md-6 mb-3">
                                          <label for="pozisyon" class="form-label">Pozisyon</label>
                                          <input type="text" name="pozisyon" id="pozisyon" class="form-control" value="<?= htmlspecialchars($pozisyon) ?>">
                                      </div>
                                      <div class="col-md-6 mb-3">
                                          <label for="current_password" class="form-label">Sistem Şifresi (Güncelleme için)</label>
                                          <input type="password" name="current_password" id="current_password" class="form-control" required>
                                      </div>
                                      <div class="col-md-6 mb-3">
                                        <label class="form-label" for="new_password">Yeni Şifre (isteğe bağlı, en az 8 karakter)</label>
                                        <input type="password" name="new_password" id="new_password" class="form-control" minlength="8">
                                      </div>
                                  </div>
                                  <button type="submit" class="btn btn-success">Profili Güncelle</button>
                              </form>
                         </div>
                    </div>
               </div>

               <div class="col-xl-3 col-lg-4">
                    <div class="card">
                         <div class="card-header">
                              <h4 class="card-title">Personal Information</h4>
                         </div>
                         <div class="card-body">
                              <div class="mb-2">
                                   <div class="d-flex align-items-center gap-2">
                                        <div class="avatar-sm bg-light d-flex align-items-center justify-content-center rounded">
                                             <iconify-icon icon="solar:letter-bold-duotone" class="fs-20 text-secondary"></iconify-icon>
                                        </div>
                                        <p class="mb-0 fs-14">Email <span class="text-warning fw-semibold"><?php echo htmlspecialchars($email); ?></span></p>
                                   </div>
                              </div>
                              <div class="mb-2">
                                   <div class="d-flex align-items-center gap-2">
                                        <div class="avatar-sm bg-light d-flex align-items-center justify-content-center rounded">
                                             <iconify-icon icon="solar:user-bold-duotone" class="fs-20 text-secondary"></iconify-icon>
                                        </div>
                                        <p class="mb-0 fs-14">İsim <span class="text-warning fw-semibold"><?php echo htmlspecialchars($first_name ?? 'Belirtilmemiş'); ?></span></p>
                                   </div>
                              </div>
                              <div class="mb-2">
                                   <div class="d-flex align-items-center gap-2">
                                        <div class="avatar-sm bg-light d-flex align-items-center justify-content-center rounded">
                                             <iconify-icon icon="solar:user-rounded-bold-duotone" class="fs-20 text-secondary"></iconify-icon>
                                        </div>
                                        <p class="mb-0 fs-14">Soyisim <span class="text-warning fw-semibold"><?php echo htmlspecialchars($last_name ?? 'Belirtilmemiş'); ?></span></p>
                                   </div>
                              </div>
                              <div class="mb-2">
                                   <div class="d-flex align-items-center gap-2">
                                        <div class="avatar-sm bg-light d-flex align-items-center justify-content-center rounded">
                                             <iconify-icon icon="solar:barcode-bold-duotone" class="fs-20 text-secondary"></iconify-icon>
                                        </div>
                                        <p class="mb-0 fs-14">Sicil No <span class="text-warning fw-semibold"><?php echo htmlspecialchars($tgs_sicil_no ?: 'Belirtilmemiş'); ?></span></p>
                                   </div>
                              </div>
                              <div class="mb-2">
                                   <div class="d-flex align-items-center gap-2">
                                        <div class="avatar-sm bg-light d-flex align-items-center justify-content-center rounded">
                                             <iconify-icon icon="solar:briefcase-bold-duotone" class="fs-20 text-secondary"></iconify-icon>
                                        </div>
                                        <p class="mb-0 fs-14">Pozisyon <span class="text-warning fw-semibold"><?php echo htmlspecialchars($pozisyon ?: 'Belirtilmemiş'); ?></span></p>
                                   </div>
                              </div>
                         </div>
                    </div>
               </div>
          </div>
     </div>
</div>

<script src="assets/js/vendor.js"></script>
<script src="assets/js/app.js"></script>
<script>
    document.getElementById('editProfileBtn').addEventListener('click', function() {
        document.getElementById('editProfileForm').classList.remove('d-none');
        this.classList.add('d-none');
    });
</script>
</body>
</html>