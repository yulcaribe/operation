<?php
require_once '../db.php';

$columns = ['id', 'first_name', 'last_name', 'username', 'email', 'user_type', 'admin_approval'];
$result = $conn->query("SHOW COLUMNS FROM users");
$existing_cols = [];

while ($row = $result->fetch_assoc()) {
    $existing_cols[] = $row['Field'];
}

$final_cols = array_intersect($columns, $existing_cols);
if (count($final_cols) === 0) {
    echo "<tr><td colspan='7'>Kullanıcı bilgileri alınamadı.</td></tr>";
} else {
    // Sayfalama ayarları
    $limit = 10;
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $offset = ($page - 1) * $limit;

    // Toplam kullanıcı sayısı
    $total_result = $conn->query("SELECT COUNT(*) as count FROM users");
    $total_rows = $total_result->fetch_assoc()['count'] ?? 0;
    $total_pages = ceil($total_rows / $limit);

    // Verileri çek
    $query = "SELECT " . implode(',', $final_cols) . " FROM users LIMIT $limit OFFSET $offset";
    $users = $conn->query($query);

    while ($user = $users->fetch_assoc()):
        // ...
        // Burada satır yapısı aynı kalır (önceki mesajdaki <tr> kısmı)
    endwhile;
}
?>

<!DOCTYPE html>
<html lang="tr">

<head>
  <meta charset="utf-8" />
  <title>Kullanıcı Listesi | Admin Panel</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="shortcut icon" href="../assets/images/favicon.ico">
  <link href="../assets/css/vendor.min.css" rel="stylesheet" type="text/css" />
  <link href="../assets/css/icons.min.css" rel="stylesheet" type="text/css" />
  <link href="../assets/css/app.min.css" rel="stylesheet" type="text/css" />
  <script src="../assets/js/config.js"></script>
</head>

<body>
  <div class="wrapper">

    <!-- Topbar -->
    <header class="topbar">
      <?php include 'topbar.php'; ?>
    </header>

    <!-- Sidebar -->
    <?php include 'sidebar.php'; ?>

    <!-- Page Content -->
	<div class="page-content">
  <div class="container-xxl">

    <div class="card overflow-hidden">
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table align-middle mb-0 table-hover table-centered">
            <thead class="bg-light-subtle">
              <tr>
                <th>İsim Soyisim</th>
                <th>Kullanıcı Adı</th>
                <th>E-Posta</th>
                <th>Rol</th>
                <th>Kurum</th>
				<th>Onay Durumu</th>
                <th>İşlem</th>                
              </tr>
            </thead>
            <tbody>
              <?php
              require_once '../db.php';

              $columns = ['id', 'first_name', 'last_name', 'username', 'email', 'user_type', 'admin_approval'];
              $result = $conn->query("SHOW COLUMNS FROM users");

              $existing_cols = [];
              while ($row = $result->fetch_assoc()) {
                  $existing_cols[] = $row['Field'];
              }

              $final_cols = array_intersect($columns, $existing_cols);
              if (count($final_cols) === 0) {
                  echo "<tr><td colspan='7'>Kullanıcı bilgileri alınamadı.</td></tr>";
              } else {
                  $limit = 10;
                  $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
                  $offset = ($page - 1) * $limit;
                  $total_result = $conn->query("SELECT COUNT(*) as count FROM users");
                  $total_rows = $total_result->fetch_assoc()['count'] ?? 0;
                  $total_pages = ceil($total_rows / $limit);

                  $query = "SELECT " . implode(',', $final_cols) . " FROM users LIMIT $limit OFFSET $offset";
                  $users = $conn->query($query);

                  while ($user = $users->fetch_assoc()):
                      $first_name = $user['first_name'] ?? null;
                      $last_name = $user['last_name'] ?? null;
                      $username = $user['username'] ?? null;
                      $email = $user['email'] ?? null;
                      $user_type = $user['user_type'] ?? null;
                      $admin_approval = $user['admin_approval'] ?? null;
                      $user_id = $user['id'] ?? 0;
              ?>
                <tr>
  <td><?= htmlspecialchars(trim(($first_name ?? '') . ' ' . ($last_name ?? ''))) ?: 'Bilinmiyor' ?></td>
  <td><?= htmlspecialchars($username ?? '') ?: 'Bilinmiyor' ?></td>
  <td><?= htmlspecialchars($email ?? '') ?: 'Bilinmiyor' ?></td>
  <td><?= htmlspecialchars($user_type ?? '') ?: 'Bilinmiyor' ?></td>
  <td><?= 'Bilinmiyor' ?></td>
  <td>
    <?php
      if ($admin_approval === 1 || $admin_approval === '1') {
          echo '<span class="badge bg-success">Onaylı</span>';
      } elseif ($admin_approval === 0 || $admin_approval === '0') {
          echo '<span class="badge bg-warning text-dark">Bekliyor</span>';
      } elseif (is_null($admin_approval)) {
          echo '<span class="badge bg-secondary">Bilinmiyor</span>';
      } else {
          echo '<span class="badge bg-danger">Hatalı Veri</span>';
      }
    ?>
  </td>
  <td>
    <div class="d-flex gap-2">
      <a href="user_view.php?id=<?= (int)$user_id ?>" class="btn btn-light btn-sm">
        <iconify-icon icon="solar:eye-broken" class="fs-18"></iconify-icon>
      </a>
    </div>
  </td>
</tr>

              <?php endwhile;
              }
              ?>
            </tbody>
          </table>
        </div>

        <!-- Sayfalama -->
<div class="row g-0 align-items-center justify-content-between text-center text-sm-start p-3 border-top">
  <div class="col-sm">
    <div class="text-muted">
      Showing <span class="fw-semibold"><?= min($limit, $total_rows - $offset) ?></span> of <span class="fw-semibold"><?= $total_rows ?></span> Results
    </div>
  </div>
  <div class="col-sm-auto mt-3 mt-sm-0">
    <nav aria-label="Page navigation example">
      <ul class="pagination mb-0">
        <!-- Previous -->
        <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
          <a class="page-link" href="<?= $page > 1 ? '?page=' . ($page - 1) : 'javascript:void(0);' ?>" aria-label="Previous">
            <span aria-hidden="true">&laquo;</span>
          </a>
        </li>

        <!-- Sayfa numaraları -->
        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
          <li class="page-item <?= ($i === $page) ? 'active' : '' ?>">
            <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
          </li>
        <?php endfor; ?>

        <!-- Next -->
        <li class="page-item <?= ($page >= $total_pages) ? 'disabled' : '' ?>">
          <a class="page-link" href="<?= $page < $total_pages ? '?page=' . ($page + 1) : 'javascript:void(0);' ?>" aria-label="Next">
            <span aria-hidden="true">&raquo;</span>
          </a>
        </li>
      </ul>
    </nav>
  </div>
</div>


      </div>
    </div>

    <!-- Footer -->
    <footer class="footer text-center">
      <div class="container-fluid">
        <div class="row">
          <div class="col-12">
            <script>document.write(new Date().getFullYear())</script> &copy; Operation Admin
          </div>
        </div>
      </div>
    </footer>

  </div>
</div>


  <script src="../assets/js/vendor.js"></script>
  <script src="../assets/js/app.js"></script>
</body>

</html>
