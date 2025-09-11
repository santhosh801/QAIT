<?php
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 0);

session_start();
if (!isset($_SESSION['employee_email'])) {
    echo json_encode(['success'=>false, 'message'=>'Not authenticated']);
    exit;
}

$mysqli = new mysqli("localhost","root","","qmit_system");
if ($mysqli->connect_error) {
    echo json_encode(['success'=>false,'message'=>'DB connection error: '.$mysqli->connect_error]);
    exit;
}
$mysqli->set_charset('utf8mb4');

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$notes = '';
if (isset($_POST['review_notes'])) $notes = $_POST['review_notes'];
elseif (isset($_POST['notes'])) $notes = $_POST['notes'];
$notes = is_string($notes) ? trim($notes) : $notes;
$notes = mb_substr($notes, 0, 4000, 'UTF-8'); // tune length to your DB (TEXT / MEDIUMTEXT)

// try JSON body too
if ($id === 0) {
    $raw = file_get_contents('php://input');
    if ($raw) {
        $p = json_decode($raw, true);
        if (is_array($p)) {
            if (isset($p['id'])) $id = (int)$p['id'];
            if (isset($p['review_notes'])) $notes = $p['review_notes'];
        }
    }
}

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
    $affected = $stmt->affected_rows;
    if ($affected === 0) {
        echo json_encode(['success'=>true,'message'=>'No change (0 rows affected). Either same value or id not found.','affected_rows'=>0]);
    } else {
        echo json_encode(['success'=>true,'message'=>'Review saved','affected_rows'=>$affected]);
    }
} else {
    echo json_encode(['success'=>false,'message'=>'Execute failed: '.$stmt->error]);
}
$stmt->close();
$mysqli->close();
