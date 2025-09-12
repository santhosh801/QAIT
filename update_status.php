<?php
// update_status.php
session_start();
header('Content-Type: application/json; charset=utf-8');

// --- auth check ---
if (!isset($_SESSION['employee_email'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed, use POST']);
    exit;
}

// --- get DB ---
$mysqli = new mysqli('localhost', 'root', '', 'qmit_system');
if ($mysqli->connect_errno) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB connection error']);
    exit;
}
$mysqli->set_charset('utf8mb4');

// --- accept form-encoded or JSON body ---
$input = $_POST;
if (empty($input)) {
    // try raw json body
    $raw = file_get_contents('php://input');
    if ($raw) {
        $json = json_decode($raw, true);
        if (is_array($json)) $input = $json;
    }
}

// sanitize/normalize fields
$id = isset($input['id']) ? (int)$input['id'] : 0;
$status = isset($input['status']) ? trim((string)$input['status']) : null;
$work_status = isset($input['work_status']) ? trim((string)$input['work_status']) : null;

// basic validation
if (!$id || $id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid or missing id']);
    exit;
}

// allowed values
$allowedStatus = ['accepted', 'pending', 'rejected'];
$allowedWork = ['working', 'not working', 'not_working']; // accept both variants optionally

// normalize some common variants
if ($work_status === 'not_working') $work_status = 'not working';

// if neither provided -> nothing to update
if ($status === null && $work_status === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No status or work_status provided']);
    exit;
}

// validate provided values
$updates = [];
$params = [];
$types = '';

if ($status !== null) {
    $status = strtolower($status);
    if (!in_array($status, $allowedStatus, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid status value']);
        exit;
    }
    $updates[] = "status = ?";
    $params[] = $status;
    $types .= 's';
}

if ($work_status !== null) {
    $work_status = strtolower($work_status);
    if (!in_array($work_status, $allowedWork, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid work_status value']);
        exit;
    }
    // normalise to 'not working' if they passed 'not_working'
    if ($work_status === 'not_working') $work_status = 'not working';
    $updates[] = "work_status = ?";
    $params[] = $work_status;
    $types .= 's';
}

// always update last_modified_at
$updates[] = "last_modified_at = NOW()";

// build statement
$setSql = implode(', ', $updates);
$sql = "UPDATE operatordoc SET $setSql WHERE id = ? LIMIT 1";
$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB prepare failed', 'error' => $mysqli->error]);
    exit;
}

// bind params (types + id)
$typesWithId = $types . 'i';
$bindParams = array_merge($params, [$id]);

// dynamic bind
$bind_names[] = $typesWithId;
for ($i = 0; $i < count($bindParams); $i++) {
    $bind_name = 'bind' . $i;
    $$bind_name = $bindParams[$i];
    $bind_names[] = &$$bind_name;
}
call_user_func_array([$stmt, 'bind_param'], $bind_names);

// execute
$execOk = $stmt->execute();
$affected = $stmt->affected_rows;
$err = $stmt->error;
$stmt->close();

if ($execOk) {
    echo json_encode([
        'success' => true,
        'message' => $affected > 0 ? 'Status updated' : 'No change (maybe same value)',
        'affected_rows' => $affected
    ]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to update', 'error' => $err]);
}
exit;
