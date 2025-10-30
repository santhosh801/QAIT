<?php
// update_doc_review.php
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['employee_email'])) {
  http_response_code(401);
  echo json_encode(['success'=>false,'message'=>'Not authenticated']); exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit;
}

$mysqli = new mysqli('localhost','root','','qmit_system');
if ($mysqli->connect_errno) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'DB connection error']); exit; }
$mysqli->set_charset('utf8mb4');

/* ---- Input ---- */
$id          = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$operator_id = isset($_POST['operator_id']) ? trim($_POST['operator_id']) : '';
$doc_key     = isset($_POST['doc_key']) ? trim($_POST['doc_key']) : '';
$action      = strtolower(trim($_POST['action'] ?? $_POST['status'] ?? '')); // 'accept' | 'pending'

$allowedDocKeys = [
  'aadhar_file','pan_file','voter_file','ration_file','consent_file','gps_selfie_file',
  'police_verification_file','permanent_address_proof_file','parent_aadhar_file','nseit_cert_file',
  'self_declaration_file','non_disclosure_file','edu_10th_file','edu_12th_file','edu_college_file'
];
if (!$doc_key || !in_array($doc_key, $allowedDocKeys, true)) {
  echo json_encode(['success'=>false,'message'=>'Invalid doc_key']); exit;
}
if (!in_array($action, ['accept','accepted','pending'], true)) {
  echo json_encode(['success'=>false,'message'=>'Invalid action']); exit;
}
$action = ($action === 'accepted') ? 'accept' : $action;

/* ---- Resolve row by operator_id first ---- */
if ($operator_id && !$id) {
  $q = $mysqli->prepare("SELECT id FROM operatordoc WHERE TRIM(operator_id)=? LIMIT 1");
  $q->bind_param('s', $operator_id);
  $q->execute();
  $r = $q->get_result();
  if ($row = $r->fetch_assoc()) $id = (int)$row['id'];
  $q->close();
}
if ($id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid id/operator_id']); exit; }

/* ---- Ensure doc_status_json column exists (MySQL 8+: IF NOT EXISTS) ---- */
@$mysqli->query("ALTER TABLE operatordoc ADD COLUMN IF NOT EXISTS doc_status_json JSON NULL");

/* ---- Load row ---- */
$s = $mysqli->prepare("SELECT operator_id, " .
  implode(',', array_map(fn($c)=>$c, $allowedDocKeys)) . ", doc_status_json
  FROM operatordoc WHERE id=? LIMIT 1");
$s->bind_param('i', $id);
$s->execute();
$row = $s->get_result()->fetch_assoc();
$s->close();
if (!$row) { echo json_encode(['success'=>false,'message'=>'Row not found']); exit; }

/* ---- Update JSON map ---- */
$map = [];
if (!empty($row['doc_status_json'])) {
  $tmp = json_decode($row['doc_status_json'], true);
  if (is_array($tmp)) $map = $tmp;
}
$map[$doc_key] = ($action === 'accept') ? 'accepted' : 'pending';
$map_json = json_encode($map, JSON_UNESCAPED_UNICODE);

$u = $mysqli->prepare("UPDATE operatordoc SET doc_status_json=?, last_modified_at=NOW() WHERE id=?");
$u->bind_param('si', $map_json, $id);
$ok = $u->execute();
$u->close();

if (!$ok) { echo json_encode(['success'=>false,'message'=>'Save failed']); exit; }

/* ---- Recompute counts for UI ---- */
// ---- Recompute counts for UI ----
$received = 0;
foreach ($allowedDocKeys as $c) if (!empty($row[$c])) $received++;
$notReceived = count($allowedDocKeys) - $received;

$accepted = 0; $pending = 0;
foreach ($map as $st) {
  $v = strtolower((string)$st);
  if ($v === 'accepted' || $v === 'accept') $accepted++;
  if ($v === 'pending') $pending++;
}

// --- NEW: persist totals for this operator in qmit_system.doc_counts ---
$up = $mysqli->prepare("
  INSERT INTO doc_counts (operator_id, accepted, pending, received, not_received, updated_at)
  VALUES (?, ?, ?, ?, ?, NOW())
  ON DUPLICATE KEY UPDATE
    accepted = VALUES(accepted),
    pending = VALUES(pending),
    received = VALUES(received),
    not_received = VALUES(not_received),
    updated_at = NOW()
");
$opIdPersist = $row['operator_id']; // from the row we loaded earlier
$up->bind_param('siiii', $opIdPersist, $accepted, $pending, $received, $notReceived);
$up->execute();
$up->close();

echo json_encode([
  'success'=>true,
  'accepted'=>$accepted,
  'pending'=>$pending,
  'received'=>$received,
  'notReceived'=>$notReceived,
  'message'=>'Updated'
]);
