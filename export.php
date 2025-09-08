<?php
session_start();
if (!isset($_SESSION['employee_email'])) {
    header("HTTP/1.1 403 Forbidden"); exit;
}
$mysqli = new mysqli("localhost","root","","qmit_system");
if ($mysqli->connect_error) die("DB error");

$filter = $_GET['filter'] ?? '';
$search = $mysqli->real_escape_string($_GET['search'] ?? '');

$whereClauses = [];
$params = [];
if ($search) {
  $whereClauses[] = "(operator_full_name LIKE '%$search%' OR email LIKE '%$search%' OR operator_id LIKE '%$search%')";
}
if ($filter !== '') {
  if (in_array($filter, ['accepted','pending','rejected'])) {
    $whereClauses[] = "status='{$mysqli->real_escape_string($filter)}'";
  } elseif ($filter === 'working') {
    $whereClauses[] = "work_status='working'";
  } elseif ($filter === 'nonworking' || $filter === 'not working') {
    $whereClauses[] = "work_status='not working'";
  }
}

$where = $whereClauses ? "WHERE " . implode(" AND ", $whereClauses) : "";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=operator_export.csv');

$out = fopen('php://output', 'w');

// get columns
$colsRes = $mysqli->query("SELECT * FROM operatordoc $where LIMIT 1");
if ($colsRes && $colsRes->num_rows) {
  $headers = array_keys($colsRes->fetch_assoc());
  fputcsv($out, $headers);
}

// rows
$res = $mysqli->query("SELECT * FROM operatordoc $where ORDER BY created_at DESC");
while ($r = $res->fetch_assoc()) fputcsv($out, $r);

fclose($out);
exit;
