<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
if (!isset($_SESSION['employee_email'])) {
    echo json_encode(['success'=>false, 'message'=>'Not authenticated']);
    exit;
}
$mysqli = new mysqli("localhost","root","","qmit_system");
if ($mysqli->connect_error) { echo json_encode(['success'=>false,'message'=>'DB error']); exit; }

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$status = isset($_POST['status']) ? $mysqli->real_escape_string($_POST['status']) : null;
$work_status = isset($_POST['work_status']) ? $mysqli->real_escape_string($_POST['work_status']) : null;

if (!$id) { echo json_encode(['success'=>false,'message'=>'Missing id']); exit; }

$fields = [];
$params = [];
$types = '';

if ($status !== null) {
  $fields[] = "status = ?";
  $types .= 's';
  $params[] = $status;
}
if ($work_status !== null) {
  $fields[] = "work_status = ?";
  $types .= 's';
  $params[] = $work_status;
}
if (empty($fields)) {
  echo json_encode(['success'=>false,'message'=>'Nothing to update']);
  exit;
}

$sql = "UPDATE operatordoc SET " . implode(', ', $fields) . " WHERE id = ?";
$stmt = $mysqli->prepare($sql);
if (!$stmt) { echo json_encode(['success'=>false,'message'=>'Prepare failed: '.$mysqli->error]); exit; }

// bind params dynamically
$types .= 'i';
$params[] = $id;
$stmt->bind_param($types, ...$params);
$ok = $stmt->execute();
if ($ok) {
  echo json_encode(['success'=>true, 'message'=>'Updated']);
} else {
  echo json_encode(['success'=>false, 'message'=>'Update failed: '.$stmt->error]);
}
$stmt->close();
$mysqli->close();
