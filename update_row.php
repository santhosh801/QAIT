<?php
// update_row.php
// Accepts JSON or form POST with operator 'id' and fields to update.
// Returns JSON { success: bool, message: string, updated_fields: {...} }

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 0);
session_start();

// Optional auth check - uncomment if required
// if (!isset($_SESSION['employee_email'])) {
//     echo json_encode(['success' => false, 'message' => 'Not authenticated']);
//     exit;
//}

// DB connection - adjust to your DB credentials
$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'qmit_system'; // Change if different

$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($mysqli->connect_errno) {
    echo json_encode(['success' => false, 'message' => 'DB connect error: ' . $mysqli->connect_error]);
    exit;
}
$mysqli->set_charset('utf8mb4');

// Read input (JSON preferred, fallback to POST)
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !is_array($input)) {
    $input = $_POST;
}
if (!is_array($input)) $input = [];

// accept either 'id' or 'operator_id' as the primary key
$id = 0;
if (isset($input['id'])) $id = (int)$input['id'];
elseif (isset($input['operator_id'])) $id = (int)$input['operator_id'];

if (!$id) {
    echo json_encode(['success' => false, 'message' => 'Missing operator id (id or operator_id)']);
    exit;
}

// Whitelist of allowed fields (keys expected from client => actual DB column)
$allowed = [
    'mobile' => 'operator_contact_no',
    'operator_contact_no' => 'operator_contact_no',
    'email' => 'email',
    'aadhaar' => 'aadhar_number',
    'aadhar_number' => 'aadhar_number',
    'work_status' => 'work_status',
    'status' => 'status',
    'operator_full_name' => 'operator_full_name',
    // add additional mappings as needed
];

// optional per-field max lengths
$maxLengths = [
    'operator_contact_no' => 40,
    'email' => 200,
    'aadhar_number' => 64,
    'operator_full_name' => 255,
    'work_status' => 64,
    'status' => 64,
];

// collect params to update
$fields = [];
$params = [];
$types = ''; // bind types for prepared statement

foreach ($allowed as $clientKey => $dbCol) {
    if (array_key_exists($clientKey, $input)) {
        $val = $input[$clientKey];
        // trim if string
        if (is_string($val)) $val = trim($val);
        // apply max length
        if (isset($maxLengths[$dbCol]) && is_string($val) && mb_strlen($val, 'UTF-8') > $maxLengths[$dbCol]) {
            $val = mb_substr($val, 0, $maxLengths[$dbCol], 'UTF-8');
        }
        $fields[] = "`$dbCol` = ?";
        $params[] = $val;
        $types .= 's';
    }
}

if (count($fields) === 0) {
    echo json_encode(['success' => false, 'message' => 'No updatable fields provided']);
    exit;
}

// build SQL
$setSql = implode(', ', $fields);
$sql = "UPDATE `operatordoc` SET {$setSql} WHERE `id` = ? LIMIT 1";

$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'message' => 'Prepare failed: ' . $mysqli->error]);
    exit;
}

// bind params, include id as integer at end
$types .= 'i';
$params[] = $id;

// mysqli bind requires references
$bind_names[] = $types;
for ($i = 0; $i < count($params); $i++) {
    $bind_names[] = & $params[$i];
}
call_user_func_array([$stmt, 'bind_param'], $bind_names);

$execOk = $stmt->execute();
if ($execOk === false) {
    echo json_encode(['success' => false, 'message' => 'Execute failed: ' . $stmt->error]);
    exit;
}

// Fetch updated fields from DB to return canonical values
$colsToReturn = [];
foreach ($fields as $fld) {
    // $fld is like `col` = ?
    if (preg_match('/`([^`]+)`/', $fld, $m)) {
        $colsToReturn[] = $m[1];
    }
}
if (count($colsToReturn) === 0) {
    // fallback
    $colsToReturn = ['operator_contact_no','email','aadhar_number','work_status','status'];
}

$colsEscaped = array_map(function($c) use ($mysqli) {
    return "`" . $mysqli->real_escape_string($c) . "`";
}, $colsToReturn);
$colsSql = implode(',', $colsEscaped);

$selSql = "SELECT {$colsSql} FROM `operatordoc` WHERE `id` = ? LIMIT 1";
$selStmt = $mysqli->prepare($selSql);
if ($selStmt) {
    $selStmt->bind_param('i', $id);
    $selStmt->execute();
    $res = $selStmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $selStmt->close();
} else {
    $row = null;
}

$stmt->close();
$mysqli->close();

echo json_encode([
    'success' => true,
    'message' => 'Row updated',
    'updated_fields' => $row
]);
exit;
