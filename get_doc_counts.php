<?php
header('Content-Type: application/json; charset=utf-8');

$mysqli = new mysqli('localhost','root','','qmit_system');
if ($mysqli->connect_errno) {
  http_response_code(500);
  echo json_encode(['success'=>false,'message'=>'DB connection error']);
  exit;
}

$operator_id = $_GET['operator_id'] ?? '';
if (!$operator_id) {
  http_response_code(400);
  echo json_encode(['success'=>false,'message'=>'Missing operator_id']);
  exit;
}

$q = $mysqli->prepare("SELECT accepted, pending, received, not_received FROM doc_counts WHERE operator_id = ? LIMIT 1");
$q->bind_param('s', $operator_id);
$q->execute();
$r = $q->get_result()->fetch_assoc();

echo json_encode([
  'success' => true,
  'accepted' => (int)($r['accepted'] ?? 0),
  'pending' => (int)($r['pending'] ?? 0),
  'received' => (int)($r['received'] ?? 0),
  'notReceived' => (int)($r['not_received'] ?? 0)
]);
