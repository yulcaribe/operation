<?php
include 'db.php';
$pageName = 'active-operations.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$columns = [];
$selected_columns = [];
$preferences = [];
$select_fields = [];
$joins = [];

$sql = "
    SELECT cm.column_key, cm.column_label, 
           COALESCE(pc.width, cm.default_width) AS width, 
           pc.visible,
           cm.source_expression, cm.join_info
    FROM columns_master cm
    LEFT JOIN page_columns pc 
        ON cm.column_key = pc.column_key AND pc.page_name = ?
    ORDER BY pc.order_index ASC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $pageName);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $columns[$row['column_key']] = $row['column_label'];
    if ($row['visible']) {
        $selected_columns[] = $row['column_key'];
        $preferences['columns'][$row['column_key']]['width'] = $row['width'];
        $select_fields[] = !empty($row['source_expression'])
            ? $row['source_expression'] . " AS `{$row['column_key']}`"
            : "f.{$row['column_key']}";

        if (!empty($row['join_info'])) {
            $parts = preg_split('/(?<=\))\s+JOIN/i', $row['join_info']);
            foreach ($parts as $i => $join_part) {
                if ($i > 0) $join_part = 'JOIN ' . $join_part;
                if (!in_array($join_part, $joins)) {
                    $joins[] = $join_part;
                }
            }
        }
    }
}

$select_sql = "SELECT " . implode(", ", $select_fields) . " FROM flights f ";
if (!empty($joins)) {
    $select_sql .= " " . implode(" ", $joins);
}
$select_sql .= " ORDER BY f.departure_date_time DESC";

$flights = $conn->query($select_sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <title>Operations Monitor</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="shortcut icon" href="assets/images/favicon.ico">
    <link href="assets/css/vendor.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/css/icons.min.css" rel="stylesheet" type="text/css" />
    <link href="assets/css/app.min.css" rel="stylesheet" type="text/css" />
    <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
    <script src="assets/js/config.js"></script>
    <style>
        th, td { font-size: 12px; padding: 4px 8px; }
        .fs-6 { font-size: 1rem !important; }
        th { resize: horizontal; overflow: auto; }
    </style>
</head>
<body>
<div class="wrapper">
    <header class="topbar"><?php include 'topbar.php'; ?></header>
    <?php include 'sidebar.php'; ?>
    <div class="page-content">
        <div class="container-xxl">
            <div class="row">
                <div class="col-xl-12">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h4 class="card-title">Operations Monitor</h4>
                        </div>
                        <div class="card-body p-0">
                            <div id="filterArea" class="mb-3 d-flex gap-2 flex-wrap"></div>

                            <div class="table-responsive">
                                <table class="table table-hover mb-0 align-middle" id="flightTable">
                                    <thead class="bg-light-subtle">
                                        <tr>
                                            <?php foreach ($columns as $key => $label): 
                                                if (!in_array($key, $selected_columns)) continue;
                                                $width = $preferences['columns'][$key]['width'] ?? 'auto';
                                                echo "<th style='width:{$width}'>{$label}</th>";
                                            endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($row = $flights->fetch_assoc()): ?>
                                        <tr>
                                            <?php foreach ($selected_columns as $key): ?>
                                                <td><?= htmlspecialchars($row[$key] ?? '-') ?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
window.pageName = 'active-operations.php';
</script>
<script src="assets/js/vendor.js"></script>
<script src="assets/js/app.js"></script>
<script src="assets/js/filters.js"></script>
<script>
window.addEventListener('DOMContentLoaded', () => {
  if (typeof loadFilters === 'function') {
    loadFilters();
  } else {
    console.error('loadFilters fonksiyonu tanè©≈mlè©≈ deè´ªil');
  }
});
</script>
</body>
</html>
