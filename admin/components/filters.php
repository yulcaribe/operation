<?php
include '../db.php';
header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$page = $_GET['page'] ?? '';
$column = $_GET['column'] ?? '';
$filters = json_decode($_GET['filters'] ?? '{}', true);

if ($action === 'get_filters') {
    $sql = "SELECT column_key, filter_label 
            FROM filters_master fm 
            JOIN filter_pages fp ON fm.id = fp.filter_id 
            WHERE fp.page_name = ? AND fm.active = 1
            ORDER BY fm.order_index";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $page);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) $out[] = $row;
    echo json_encode($out);
    exit;
}

if ($action === 'get_filter_options') {
    $sql = "SELECT source_expression, join_info
            FROM filters_master fm 
            JOIN filter_pages fp ON fm.id = fp.filter_id 
            WHERE fp.page_name = ? AND fm.column_key = ? AND fm.active = 1 LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $page, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $src = $row['source_expression'];
    $join = $row['join_info'];
    $q = "SELECT DISTINCT $src FROM flights f " . ($join ? " $join " : "") .
         "WHERE $src IS NOT NULL AND $src != '' ORDER BY $src";
    $res = $conn->query($q);
    $out = [];
    while ($r = $res->fetch_row()) $out[] = $r[0];
    echo json_encode($out);
    exit;
}

if ($action === 'apply_filters') {
    $sql = "SELECT source_expression, join_info, column_key 
            FROM filters_master fm
            JOIN filter_pages fp ON fm.id = fp.filter_id
            WHERE fp.page_name = ? AND fm.active = 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $page);
    $stmt->execute();
    $res = $stmt->get_result();

    $select = $joins = $where = [];
    while ($row = $res->fetch_assoc()) {
        $col = $row['column_key'];
        $src = $row['source_expression'];
        $select[] = "$src AS `$col`";
        if ($row['join_info']) $joins[] = $row['join_info'];
        if (!empty($filters[$col])) {
            $val = $conn->real_escape_string($filters[$col]);
            $where[] = "$src = '$val'";
        }
    }

    $sql = "SELECT " . implode(",", $select) . " FROM flights f " . implode(" ", $joins);
    if ($where) $sql .= " WHERE " . implode(" AND ", $where);
    $sql .= " ORDER BY f.departure_date_time DESC";

    $rows = $conn->query($sql);
    $out = [];
    while ($r = $rows->fetch_assoc()) $out[] = $r;
    echo json_encode($out);
    exit;
}

echo json_encode(['error' => 'Invalid action']);
