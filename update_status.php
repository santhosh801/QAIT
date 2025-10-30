<?php
declare(strict_types=1);                       // MUST be first

ini_set('display_errors', '1');                // <- string
ini_set('display_startup_errors', '1');        // <- string
error_reporting(E_ALL);

session_start();
header('Content-Type: application/json; charset=utf-8');


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

$body = $_POST;
if (!$body) {
  $raw = file_get_contents('php://input');
  $json = $raw ? json_decode($raw, true) : null;
  if (is_array($json)) $body = $json;
}

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

$table = 'operatordoc'; // or 'operator' if that’s your main table

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
    'message' => 'OK',
    'status' => $res['status'] ?? $status,
    'work_status' => $res['work_status'] ?? $work_status
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['success' => false, 'message' => 'Update failed', 'error' => $e->getMessage()]);
}
