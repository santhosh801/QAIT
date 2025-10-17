<?php
// get_resubmission_for_operator.php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['employee_email'])) {
    http_response_code(401);
    echo json_encode(['success'=>false,'message'=>'Unauthorized']);
    exit;
}

$op = isset($_GET['op']) ? (int)$_GET['op'] : 0;
if ($op <= 0) {
    echo json_encode(['success'=>false,'message'=>'Invalid operator']);
    exit;
}

$mysqli = new mysqli('localhost','root','','qmit_system');
if ($mysqli->connect_errno) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'DB connect failed']);
    exit;
}
$mysqli->set_charset('utf8mb4');

// Get the most recent resubmission request for this operator (any status)
$stmt = $mysqli->prepare("
  SELECT id, token, docs_json, status, expires_at, created_at
  FROM resubmission_requests
  WHERE operator_id = ?
  ORDER BY created_at DESC
  LIMIT 1
");
if (!$stmt) {
    echo json_encode(['success'=>false,'message'=>'DB prepare failed']);
    exit;
}
$stmt->bind_param('i', $op);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    echo json_encode(['success'=>false,'message'=>'No resubmission request found']);
    exit;
}

// count requested docs
$docs = json_decode($row['docs_json'] ?? '[]', true);
$doc_count = is_array($docs) ? count($docs) : 0;

// check whether corresponding files exist in operatordoc or resubmission_uploads
$has_files = false;
if ($doc_count > 0) {
    // check operatordoc columns for presence
    $placeholders = [];
    $colsToCheck = [];
    foreach ($docs as $d) {
        // basic validation for column name
        if (!preg_match('/^[a-z0-9_]+$/i', $d)) continue;
        $colsToCheck[] = $d;
    }
    if (count($colsToCheck)) {
        // build SELECT dynamically
        $selectCols = implode(', ', array_map(function($c){ return "`$c`"; }, $colsToCheck));
        $q = $mysqli->prepare("SELECT $selectCols FROM operatordoc WHERE id = ? LIMIT 1");
        if ($q) {
            $q->bind_param('i', $op);
            $q->execute();
            $r = $q->get_result();
            if ($r && $r->num_rows) {
                $rrow = $r->fetch_assoc();
                foreach ($colsToCheck as $c) {
                    if (!empty($rrow[$c])) { $has_files = true; break; }
                }
            }
            $q->close();
        }
    }
}

// respond with the request (note: may be 'open' or 'completed')
echo json_encode([
    'success' => true,
    'request_id' => (int)$row['id'],
    'token' => $row['token'] ?? '',
    'status' => $row['status'] ?? '',
    'doc_count' => $doc_count,
    'has_files' => (bool)$has_files,
    'expires_at' => $row['expires_at'] ?? null,
    'created_at' => $row['created_at'] ?? null
]);
exit;
