<?php
session_start();
require_once 'db.php';
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

function calculate_otp($scheduled_time) {
    if (!$scheduled_time) return '-';
    $now = time();
    $sched = strtotime($scheduled_time);
    $diff = $sched - $now;
    $minutes = round($diff / 60);
    if ($minutes > 0) return "{$minutes} min";
    if ($minutes < 0) return abs($minutes) . " min delay";
    return "On Time";
}

$userId = $_SESSION['user_id'] ?? 1;
$pageName = 'operations-monitor.php';
$tableName = 'operations-monitor';

$columns = [];
$colQuery = $conn->prepare("SELECT column_key, column_label FROM table_columns WHERE page_name = ? AND table_name = ? ORDER BY ordering ASC");
$colQuery->bind_param("ss", $pageName, $tableName);
$colQuery->execute();
$colResult = $colQuery->get_result();
while ($col = $colResult->fetch_assoc()) {
    $columns[$col['column_key']] = $col['column_label'];
}

$prefStmt = $conn->prepare("SELECT preferences FROM user_table_preferences WHERE user_id = ? AND page_name = ? AND table_name = ?");
$prefStmt->bind_param("iss", $userId, $pageName, $tableName);
$prefStmt->execute();
$prefResult = $prefStmt->get_result()->fetch_assoc();
$preferences = $prefResult ? json_decode($prefResult['preferences'], true) : [];

$filters = $preferences['filters'] ?? [];
foreach ($_GET as $key => $val) {
    if (is_array($val)) {
        $filters[$key] = $val;
    }
}

$selected_columns = $_GET['cols'] ?? array_filter(array_keys($columns), function ($col) use ($preferences) {
    return !isset($preferences['columns'][$col]['visible']) || $preferences['columns'][$col]['visible'];
});

$where = ["f.status = 'active'"];
$params = [];
$types = '';

if (!empty($filters['icao'])) {
    $placeholders = implode(',', array_fill(0, count($filters['icao']), '?'));
    $where[] = "f.icao_code IN ($placeholders)";
    $params = array_merge($params, $filters['icao']);
    $types .= str_repeat('s', count($filters['icao']));
}
if (!empty($filters['ftype'])) {
    $placeholders = implode(',', array_fill(0, count($filters['ftype']), '?'));
    $where[] = "ft.name IN ($placeholders)";
    $params = array_merge($params, $filters['ftype']);
    $types .= str_repeat('s', count($filters['ftype']));
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "SELECT f.*, u.first_name, u.last_name, ft.name AS flight_type_name
        FROM flights f
        LEFT JOIN flight_types ft ON f.flight_type_id = ft.id
        LEFT JOIN flight_assignments fa ON fa.flight_id = f.id
        LEFT JOIN users u ON fa.user_id = u.id AND u.user_type = 'operation'
        $whereClause
        ORDER BY f.departure_date_time ASC";

$query = $conn->prepare($sql);
if ($params) $query->bind_param($types, ...$params);
$query->execute();
$flights = $query->get_result();

require_once 'components/filter-bar.php';
?><!DOCTYPE html>
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
        .dropdown-menu label { display: block; padding: 0.25rem 1rem; }
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
                        <div class="d-flex card-header justify-content-between align-items-center">
                            <h4 class="card-title">Operations Monitor</h4>
                            <?php renderFilterBar($conn, $pageName, $tableName, $filters, $columns, $selected_columns); ?>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table align-middle mb-0 table-hover table-centered" id="flightTable">
                                    <thead class="bg-light-subtle">
                                        <tr>
                                            <?php foreach ($columns as $key => $label): if (!in_array($key, $selected_columns)) continue;
                                                $width = $preferences['columns'][$key]['width'] ?? 'auto';
                                                echo "<th data-col='{$key}' style='width: {$width}'>{$label}</th>";
                                            endforeach; ?>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($row = $flights->fetch_assoc()): ?>
                                            <tr>
                                                <?php foreach ($columns as $key => $label): if (!in_array($key, $selected_columns)) continue;
                                                    switch ($key) {
                                                        case 'otp':
                                                            echo "<td>" . htmlspecialchars(calculate_otp($row['departure_date_time'])) . "</td>";
                                                            break;
                                                        case 'coordinator':
                                                            $coord = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: '-';
                                                            echo "<td>" . htmlspecialchars($coord) . "</td>";
                                                            break;
                                                        case 'arrival_date_time':
                                                        case 'departure_date_time':
                                                        case 'estimated_arrival_date_time':
                                                        case 'estimated_departure_date_time':
                                                            echo "<td>" . ($row[$key] ? date('H:i', strtotime($row[$key])) : '-') . "</td>";
                                                            break;
                                                        case 'arrival_flight_number':
                                                        case 'departure_flight_number':
                                                            echo "<td>" . htmlspecialchars(($row['iata_code'] ?? '') . ($row[$key] ?? '-')) . "</td>";
                                                            break;
                                                        case 'actions':
                                                            echo '<td><a href="#" class="btn btn-soft-primary btn-sm"><i class="bx bx-edit"></i></a></td>';
                                                            break;
                                                        default:
                                                            echo "<td>" . htmlspecialchars($row[$key] ?? '-') . "</td>";
                                                    }
                                                endforeach; ?>
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
<script src="assets/js/vendor.js"></script>
<script src="assets/js/app.js"></script>
<script src="assets/js/table-preferences.js"></script>
<script>
initTablePreferences({ pageName: 'operations-monitor.php' });
</script>
</body>
</html>
