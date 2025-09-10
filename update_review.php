<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
if (!isset($_SESSION['employee_email'])) {
    echo json_encode(['success'=>false, 'message'=>'Not authenticated']);
    exit;
}
$mysqli = new mysqli("localhost","root","","qmit_system");
if ($mysqli->connect_error) {
    echo json_encode(['success'=>false,'message'=>'DB connection error']);
    exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$notes = '';
if (isset($_POST['review_notes'])) $notes = $_POST['review_notes'];
elseif (isset($_POST['notes'])) $notes = $_POST['notes'];

if (!$id) {
    echo json_encode(['success'=>false,'message'=>'Missing id']);
    exit;
}

$stmt = $mysqli->prepare("UPDATE operatordoc SET review_notes = ? WHERE id = ?");
if (!$stmt) {
    echo json_encode(['success'=>false,'message'=>'Prepare failed: '.$mysqli->error]);
    exit;
}
$stmt->bind_param('si', $notes, $id);
$ok = $stmt->execute();
if ($ok) {
    echo json_encode(['success'=>true,'message'=>'Review saved']);
} else {
    echo json_encode(['success'=>false,'message'=>'Save failed: '.$stmt->error]);
}
$stmt->close();
$mysqli->close();
