<?php
// update_row.php (improved)
// Accepts POST of editable fields and updates operatordoc row.
// - Uses 'bank' column (validated against allowed list).
// - Only whitelisted columns may be changed.

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 0); // set to 1 only during dev

session_start();
if (!isset($_SESSION['employee_email'])) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success'=>false,'message'=>'Invalid method']);
    exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if (!$id) { echo json_encode(['success'=>false,'message'=>'Missing id']); exit; }

$mysqli = new mysqli("localhost","root","","qmit_system");
if ($mysqli->connect_error) { echo json_encode(['success'=>false,'message'=>'DB error: '.$mysqli->connect_error]); exit; }
$mysqli->set_charset('utf8mb4');

// Allowlist: only update these columns
$allowlist = [
    'id',
    'operator_id',
    'operator_full_name',
    'email',
    'branch_name',
    'joining_date',
    'operator_contact_no',
    'father_name',
    'dob',
    'gender',
    'aadhar_number',
    'pan_number',
    'voter_id_no',
    'ration_card',
    'nseit_number',
    'nseit_date',
    'current_hno_street',
    'current_village_town',
    'current_pincode',
    'current_postoffice',
    'current_district',
    'current_state',
    'permanent_hno_street',
    'permanent_village_town',
    'permanent_pincode',
    'permanent_postoffice',
    'permanent_district',
    'permanent_state',
    'bank',               // <-- using 'bank' column
    'status',
    'work_status',
    'review_notes',
    'rejection_summary',
    'created_at',
    'last_modified_at'
];

// Build fields and params
$fields = [];
$params = [];
$types = '';
$maxLengths = [
    'review_notes' => 4000, // example limit — tune to your schema (TEXT/MEDIUMTEXT)
    'operator_full_name' => 255,
    'email' => 255,
    // add limits for other fields as you prefer
];

// Allowed banks - same list as registration form; validate updates to 'bank'
$allowedBanks = [
  'Karur Vysya Bank',
  'City Union Bank',
  'Tamilnad Mercantile Bank',
  'Indian Bank',
  'Karnataka Bank',
  'Equitas Small Finance Bank',
  'Union Bank Of India'
];

foreach ($_POST as $k => $v) {
    if ($k === 'id') continue;
    if (!in_array($k, $allowlist, true)) continue;

    // normalize value (keep non-strings as-is)
    $val = is_string($v) ? trim($v) : $v;

    // If updating bank, handle validation:
    // - allow empty string to clear bank
    // - otherwise require exact match from allowedBanks
    if ($k === 'bank') {
        if ($val === '') {
            // allow clearing bank
            $val = '';
        } else {
            if (!in_array($val, $allowedBanks, true)) {
                // invalid bank value — skip update for this field
                continue;
            }
        }
    }

    // enforce a sensible max length if configured
    if (isset($maxLengths[$k]) && is_string($val) && mb_strlen($val, 'UTF-8') > $maxLengths[$k]) {
        $val = mb_substr($val, 0, $maxLengths[$k], 'UTF-8');
    }

    // convert PHP null-ish to actual SQL null if you want (currently keeping empty strings)
    // if ($val === '') { $val = null; }

    $fields[] = "`$k` = ?";
    $params[] = $val;
    $types .= 's';
}

if (empty($fields)) {
    echo json_encode(['success'=>false,'message'=>'No editable fields provided']);
    exit;
}

$sql = "UPDATE operatordoc SET " . implode(", ", $fields) . " WHERE id = ?";
$params[] = $id;
$types .= 'i';

$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    echo json_encode(['success'=>false,'message'=>'Prepare failed: '.$mysqli->error,'sql'=>$sql]);
    exit;
}

// bind_param needs references in call_user_func_array
$bindNames = [];
$bindNames[] = $types;
for ($i = 0; $i < count($params); $i++) {
    // ensure parameters are variables (not temporary values)
    $bindNames[] = &$params[$i];
}
call_user_func_array([$stmt, 'bind_param'], $bindNames);

$execOk = $stmt->execute();
if ($execOk) {
    $affected = $stmt->affected_rows;
    echo json_encode(['success'=>true,'message'=>'Row updated','affected_rows'=>$affected]);
} else {
    echo json_encode(['success'=>false,'message'=>'Execute failed: '.$stmt->error]);
}
$stmt->close();
$mysqli->close();
