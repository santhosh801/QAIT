<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once "db_conn.php";

$raw = file_get_contents("php://input");
$data = json_decode($raw, true);
if (!$data || empty($data['operator_id']) || !is_array($data['states'])) {
  http_response_code(400);
  echo json_encode(['success'=>false,'message'=>'Bad payload']); exit;
}

$operator_id = trim($data['operator_id']);
$map = $data['states'];

$q = $conn->prepare("SELECT id, doc_status_json FROM operatordoc WHERE TRIM(operator_id)=? LIMIT 1");
$q->bind_param("s", $operator_id);
$q->execute();
$row = $q->get_result()->fetch_assoc();
$q->close();
if (!$row) { echo json_encode(['success'=>false,'message'=>'Operator not found']); exit; }

$id = (int)$row['id'];
$map_json = json_encode($map, JSON_UNESCAPED_UNICODE);

// update JSON
$u = $conn->prepare("UPDATE operatordoc SET doc_status_json=?, last_modified_at=NOW() WHERE id=?");
$u->bind_param('si', $map_json, $id);
$u->execute();
$u->close();

// recalc doc_counts
$accepted = 0; $pending = 0;
foreach($map as $v){
  $v = strtolower((string)$v);
  if ($v==='accepted' || $v==='accept') $accepted++;
  if ($v==='pending') $pending++;
}
$received = 0;
$cols = ['aadhar_file','pan_file','voter_file','ration_file','consent_file','gps_selfie_file',
  'police_verification_file','permanent_address_proof_file','parent_aadhar_file','nseit_cert_file',
  'self_declaration_file','non_disclosure_file','edu_10th_file','edu_12th_file','edu_college_file'];
foreach($cols as $c) if(!empty($row[$c])) $received++;
$notReceived = count($cols) - $received;

$conn->query("INSERT INTO doc_counts(operator_id,accepted,pending,received,not_received,updated_at)
VALUES('$operator_id',$accepted,$pending,$received,$notReceived,NOW())
ON DUPLICATE KEY UPDATE accepted=VALUES(accepted), pending=VALUES(pending),
received=VALUES(received), not_received=VALUES(not_received), updated_at=NOW()");

echo json_encode(['success'=>true]);
