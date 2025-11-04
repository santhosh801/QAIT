<?php 
// update_status.php
declare(strict_types=1); // MUST be first

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

session_start();
header('Content-Type: application/json; charset=utf-8');
function norm_state(string $s): string {
  $s = strtolower(trim($s));
  $map = [
    'accept' => 'accepted',
    'accepted' => 'accepted',
    'pending' => 'pending',
    'receive' => 'received',
    'received' => 'received',
    'replace' => 'replacement',
    'replaced' => 'replacement',
    'replacement' => 'replacement',
    'not-received' => 'not-received',
    'not_received' => 'not-received',
    'none' => 'received', // optional fallback
  ];
  return $map[$s] ?? 'received';
}

if (!isset($_SESSION['employee_email'])) {
  http_response_code(401);
  echo json_encode(['success' => false, 'message' => 'Not authenticated']);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['success' => false, 'message' => 'Use POST']);
  exit;
}

require_once __DIR__ . '/db_conn.php';
if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
  http_response_code(500);
  echo json_encode(['success' => false, 'message' => 'Database connection failed']);
  exit;
}
$mysqli->set_charset('utf8mb4');

/* ---------------------------------------------------------
   Common: Parse JSON or POST body
--------------------------------------------------------- */
$body = $_POST;
if (!$body) {
  $raw = file_get_contents('php://input');
  $json = $raw ? json_decode($raw, true) : null;
  if (is_array($json)) $body = $json;
}

/* ---------------------------------------------------------
   BRANCH 1: Document-level update (doc_key + state)
--------------------------------------------------------- */
/* ---------------------------------------------------------
   BRANCH 1: Document-level update (doc_key + state)
--------------------------------------------------------- */
if (isset($body['doc_key']) && isset($body['state']) && isset($body['operator_id'])) {

  $operator_id = trim((string)$body['operator_id']);
  $doc_key     = trim((string)$body['doc_key']);
  $state       = strtolower(trim((string)$body['state']));

  $allowedStates = ['received','accept','pending','replacement','not-received'];
  if (!in_array($state, $allowedStates, true)) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>'Invalid document state']); exit;
  }

  $table = 'operator_documents'; // <-- UPDATE to your real table

  try {
    // Insert/Update
    $sql1 = "
      INSERT INTO {$table} (operator_id, doc_key, state, updated_at)
      VALUES (?, ?, ?, NOW())
      ON DUPLICATE KEY UPDATE state = VALUES(state), updated_at = NOW()
    ";
    if (!$stmt = $mysqli->prepare($sql1)) {
      http_response_code(500);
      echo json_encode(['success'=>false,'message'=>'Prepare failed (insert)', 'sql'=>$sql1, 'error'=>$mysqli->error]); exit;
    }
    $stmt->bind_param('sss', $operator_id, $doc_key, $state);
    if (!$stmt->execute()) {
      http_response_code(500);
      echo json_encode(['success'=>false,'message'=>'Execute failed (insert)', 'error'=>$stmt->error]); exit;
    }
    $stmt->close();

    // Counts
    $counts = ['accepted'=>0,'pending'=>0,'received'=>0,'not-received'=>0,'replacement'=>0];
    $sql2 = "SELECT state, COUNT(*) AS c FROM {$table} WHERE operator_id = ? GROUP BY state";
    if (!$stmt = $mysqli->prepare($sql2)) {
      http_response_code(500);
      echo json_encode(['success'=>false,'message'=>'Prepare failed (counts)', 'sql'=>$sql2, 'error'=>$mysqli->error]); exit;
    }
    $stmt->bind_param('s', $operator_id);
    if (!$stmt->execute()) {
      http_response_code(500);
      echo json_encode(['success'=>false,'message'=>'Execute failed (counts)', 'error'=>$stmt->error]); exit;
    }
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
      $s = $row['state'];
      if (isset($counts[$s])) $counts[$s] = (int)$row['c'];
    }
    $stmt->close();

    echo json_encode([
      'success'=>true,
      'type'=>'document',
      'message'=>'Document state updated',
      'state'=>$state,
      'doc_key'=>$doc_key,
      'counts'=>$counts
    ]);
    exit;

  } catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Document update failed (exception)', 'error'=>$e->getMessage()]);
    exit;
  }
}


/* ---------------------------------------------------------
   BRANCH 2: Operator-level update (existing logic)
--------------------------------------------------------- */

$id          = isset($body['id']) ? (int)$body['id'] : 0;
$operator_id = isset($body['operator_id']) ? trim((string)$body['operator_id']) : '';
$status      = isset($body['status']) ? strtolower(trim((string)$body['status'])) : null;
$work_status = isset($body['work_status']) ? strtolower(trim((string)$body['work_status'])) : null;

if ($id <= 0) {
  http_response_code(400);
  echo json_encode(['success' => false, 'message' => 'Invalid or missing id']);
  exit;
}

if ($work_status === 'not_working') $work_status = 'not working';

$allowedStatus = ['accepted', 'pending', 'rejected'];
$allowedWork   = ['working', 'not working'];

if ($status !== null && !in_array($status, $allowedStatus, true)) {
  http_response_code(400);
  echo json_encode(['success' => false, 'message' => 'Invalid status']);
  exit;
}
if ($work_status !== null && !in_array($work_status, $allowedWork, true)) {
  http_response_code(400);
  echo json_encode(['success' => false, 'message' => 'Invalid work_status']);
  exit;
}

$table = 'operatordoc'; // original operator status table

try {
  if ($status !== null) {
    $stmt = $mysqli->prepare("UPDATE {$table} SET status = ? WHERE id = ?");
    $stmt->bind_param('si', $status, $id);
    $stmt->execute();
    $stmt->close();
  }

  if ($work_status !== null) {
    $stmt = $mysqli->prepare("UPDATE {$table} SET work_status = ? WHERE id = ?");
    $stmt->bind_param('si', $work_status, $id);
    $stmt->execute();
    $stmt->close();
  }

  $stmt = $mysqli->prepare("SELECT status, work_status FROM {$table} WHERE id = ?");
  $stmt->bind_param('i', $id);
  $stmt->execute();
  $res = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  echo json_encode([
    'success' => true,
    'type'=>'operator',
    'message' => 'OK',
    'status' => $res['status'] ?? $status,
    'work_status' => $res['work_status'] ?? $work_status
  ]);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    'success' => false,
    'message' => 'Update failed',
    'error' => $e->getMessage()
  ]);
}
if (isset($_GET['ping'])) {
  echo json_encode(['success'=>true,'pong'=>true,'session'=>isset($_SESSION['employee_email'])]);
  exit;
}
