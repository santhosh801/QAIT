<?php
// update_status.php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['employee_email'])) {
    http_response_code(401);
    echo json_encode(['success'=>false,'message'=>'Not authenticated']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success'=>false,'message'=>'Method not allowed']);
    exit;
}

$mysqli = new mysqli('localhost','root','','qmit_system');
if ($mysqli->connect_errno) { echo json_encode(['success'=>false,'message'=>'DB error']); exit; }

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$status = isset($_POST['status']) ? trim($_POST['status']) : '';

if (!$id || !in_array($status, ['accepted','pending','rejected','non'])) {
    echo json_encode(['success'=>false,'message'=>'Invalid params or status']);
    exit;
}

// update
$stmt = $mysqli->prepare("UPDATE operatordoc SET status = ?, last_modified_at = NOW() WHERE id = ?");
if (!$stmt) { echo json_encode(['success'=>false,'message'=>'DB prepare failed']); exit; }
$stmt->bind_param('si', $status, $id);
$ok = $stmt->execute();
$stmt->close();

echo json_encode(['success'=>(bool)$ok,'message'=>$ok ? 'Status updated' : 'Failed to update']);
exit;
