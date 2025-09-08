<?php
// update_status.php
header('Content-Type: application/json');
session_start();
if (!isset($_SESSION['employee_email'])) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Unauthorized']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success'=>false,'message'=>'Invalid method']);
    exit;
}
$mysqli = new mysqli("localhost","root","","qmit_system");
if ($mysqli->connect_error) {
    echo json_encode(['success'=>false,'message'=>'DB connection failed']);
    exit;
}
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if (!$id) { echo json_encode(['success'=>false,'message'=>'Missing id']); exit; }

$updates = [];
$params = [];
$types = '';

if (isset($_POST['status'])) {
    $status = $_POST['status'];
    if (!in_array($status, ['accepted','pending','rejected'], true)) {
        echo json_encode(['success'=>false,'message'=>'Invalid status']); exit;
    }
    $updates[] = "status = ?";
    $params[] = $status;
    $types .= 's';
}
if (isset($_POST['work_status'])) {
    $work = $_POST['work_status'];
    if (!in_array($work, ['working','not working'], true)) {
        echo json_encode(['success'=>false,'message'=>'Invalid work_status']); exit;
    }
    $updates[] = "work_status = ?";
    $params[] = $work;
    $types .= 's';
}

if (empty($updates)) {
    echo json_encode(['success'=>false,'message'=>'Nothing to update']); exit;
}

$sql = "UPDATE operatordoc SET " . implode(", ", $updates) . " WHERE id = ?";
$params[] = $id;
$types .= 'i';

$stmt = $mysqli->prepare($sql);
if (!$stmt) { echo json_encode(['success'=>false,'message'=>$mysqli->error]); exit; }
$stmt->bind_param($types, ...$params);
if ($stmt->execute()) {
    echo json_encode(['success'=>true,'message'=>'Updated']);
} else {
    echo json_encode(['success'=>false,'message'=>'Update failed']);
}
$stmt->close();
$mysqli->close();
