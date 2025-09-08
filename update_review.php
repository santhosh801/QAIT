<?php
// update_review.php
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
$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
if (!$id) { echo json_encode(['success'=>false,'message'=>'Missing id']); exit; }

$mysqli = new mysqli("localhost","root","","qmit_system");
if ($mysqli->connect_error) { echo json_encode(['success'=>false,'message'=>'DB error']); exit; }

$stmt = $mysqli->prepare("UPDATE operatordoc SET review_notes = ? WHERE id = ?");
if (!$stmt) { echo json_encode(['success'=>false,'message'=>$mysqli->error]); exit; }
$stmt->bind_param('si', $notes, $id);
if ($stmt->execute()) {
    echo json_encode(['success'=>true,'message'=>'Review saved']);
} else {
    echo json_encode(['success'=>false,'message'=>'Save failed']);
}
$stmt->close();
$mysqli->close();
