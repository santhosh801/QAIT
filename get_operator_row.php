<?php
// get_operator_row.php
header('Content-Type: application/json; charset=utf-8');
session_start();

// basic auth guard (same logic as main app)
if (!isset($_SESSION['employee_email'])) {
    http_response_code(401);
    echo json_encode(['error'=>'unauthorized']);
    exit;
}

if (!isset($_GET['id']) || !ctype_digit($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['error'=>'missing id']);
    exit;
}

$id = (int)$_GET['id'];

// DB connection (match your main file)
$mysqli = new mysqli("localhost", "root", "", "qmit_system");
if ($mysqli->connect_error) {
    http_response_code(500);
    echo json_encode(['error'=>'db connect failed']);
    exit;
}

// fetch the operator row by id
$stmt = $mysqli->prepare("SELECT * FROM operatordoc WHERE id = ?");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['error'=>'prepare failed']);
    exit;
}
$stmt->bind_param('i', $id);
$stmt->execute();
$res = $stmt->get_result();
$row = $res ? $res->fetch_assoc() : null;

if (!$row) {
    http_response_code(404);
    echo json_encode(['error'=>'not found']);
    exit;
}

// safe: convert null to empty string
foreach ($row as $k => $v) { if (is_null($v)) $row[$k] = ''; }

// respond with JSON
echo json_encode(['success' => true, 'data' => $row], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
