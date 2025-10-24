<?php
// update_row.php (robust drop-in)
// Overwrites previous file. Saves debug to logs/update_row_debug.log next to this script.
// Requires mysqli. Tested on PHP7+ / XAMPP.

// ---- quick config ----
ini_set('display_errors', 0);
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');
$LOG_PATH = __DIR__ . '/logs/update_row_debug.log';

// safe debug helper
function debug_log($line) {
    global $LOG_PATH;
    @mkdir(dirname($LOG_PATH), 0755, true);
    $t = date('Y-m-d H:i:s');
    @file_put_contents($LOG_PATH, "[$t] " . $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

// ---- read input ----
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) $input = $_POST ?? [];
debug_log("RAW_INPUT: " . ($raw ? $raw : json_encode($_POST)));

// ---- basic validation ----
$id = 0;
if (isset($input['id'])) $id = intval($input['id']);
elseif (isset($input['operator_id'])) $id = intval($input['operator_id']);
if ($id <= 0) {
    debug_log("ERROR: Missing id in payload");
    echo json_encode(['success'=>false, 'message'=>'Missing operator id (id or operator_id)']);
    exit;
}

// ---- DB connect ----
$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'qmit_system';

$mysqli = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($mysqli->connect_errno) {
    debug_log("DB CONNECT ERROR: " . $mysqli->connect_error);
    echo json_encode(['success'=>false, 'message'=>'DB connect error: '.$mysqli->connect_error]);
    exit;
}
$mysqli->set_charset('utf8mb4');

// ---- load allowed columns from DB (whitelist) ----
$allowedCols = [];
$colsRes = $mysqli->query("SHOW COLUMNS FROM `operatordoc`");
if ($colsRes && $colsRes->num_rows) {
    while ($c = $colsRes->fetch_assoc()) {
        $allowedCols[] = $c['Field'];
    }
} else {
    debug_log("ERROR: SHOW COLUMNS failed: " . $mysqli->error);
    echo json_encode(['success'=>false, 'message'=>'Failed to read operatordoc columns from DB']);
    $mysqli->close();
    exit;
}
$allowedMap = array_flip($allowedCols); // fast lookup

// ---- collect fields from payload that map to real columns ----
$fields = [];
$params = [];
$types = '';
$MAX_LEN = []; // optional: per-col max length (not required)

// Accept both DB column names and some friendly aliases by mapping them here:
$alias = [
    // basic identity fields
    'id'                    => 'id',
    'operator_id'           => 'operator_id',
    'operator_full_name'    => 'operator_full_name',
    'full_name'             => 'operator_full_name',
    'name'                  => 'operator_full_name',
    'email'                 => 'email',
    'mobile'                => 'operator_contact_no',
    'contact_no'            => 'operator_contact_no',
    'phone'                 => 'operator_contact_no',
    'operator_contact_no'   => 'operator_contact_no',

    // branch & joining info
    'branch'                => 'branch_name',
    'branch_name'           => 'branch_name',
    'joining'               => 'joining_date',
    'joining_date'          => 'joining_date',
    'nseit_number'          => 'nseit_number',
    'nseit_date'            => 'nseit_date',

    // personal info
    'father'                => 'father_name',
    'father_name'           => 'father_name',
    'dob'                   => 'dob',
    'date_of_birth'         => 'dob',
    'gender'                => 'gender',

    // identification numbers
    'aadhaar'               => 'aadhar_number',
    'aadhar'                => 'aadhar_number',
    'aadhar_number'         => 'aadhar_number',
    'pan'                   => 'pan_number',
    'pan_number'            => 'pan_number',
    'voter_id'              => 'voter_id_no',
    'voter'                 => 'voter_id_no',
    'voter_id_no'           => 'voter_id_no',
    'ration'                => 'ration_card',
    'ration_card'           => 'ration_card',

    // alternate contact
    'alt_contact_relation'  => 'alt_contact_relation',
    'alt_contact_number'    => 'alt_contact_number',

    // education docs (non-file values)
    'edu_10th'              => 'edu_10th_file',
    'edu_12th'              => 'edu_12th_file',
    'edu_college'           => 'edu_college_file',

    // current address
    'current_hno_street'    => 'current_hno_street',
    'current_village_town'  => 'current_village_town',
    'current_pincode'       => 'current_pincode',
    'current_postoffice'    => 'current_postoffice',
    'current_district'      => 'current_district',
    'current_state'         => 'current_state',

    // permanent address
    'permanent_hno_street'  => 'permanent_hno_street',
    'permanent_village_town'=> 'permanent_village_town',
    'permanent_pincode'     => 'permanent_pincode',
    'permanent_postoffice'  => 'permanent_postoffice',
    'permanent_district'    => 'permanent_district',
    'permanent_state'       => 'permanent_state',

    // bank and work info
    'bank'                  => 'bank_name',
    'bank_name'             => 'bank_name',
    'status'                => 'status',
    'work_status'           => 'work_status',
    'review_notes'          => 'review_notes',
    'rejection_summary'     => 'rejection_summary',

    // meta timestamps
    'created_at'            => 'created_at',
    'last_modified_at'      => 'last_modified_at',
];


// iterate payload keys
foreach ($input as $k => $v) {
    if ($k === 'id' || $k === 'operator_id') continue;
    $col = $k;
    if (isset($alias[$k])) $col = $alias[$k];
    // only accept if column exists
    if (!isset($allowedMap[$col])) continue;
    // sanitize value types: convert booleans to int, trim strings
    if (is_bool($v)) $v = $v ? '1' : '0';
    if (is_string($v)) $v = trim($v);
    // optional clipping by max len (if you want to add logic)
    $fields[] = "`$col` = ?";
    $params[] = $v;
    $types .= 's';
}

if (count($fields) === 0) {
    debug_log("NO_UPDATABLE_FIELDS in payload for id={$id}. Payload keys: " . json_encode(array_keys($input)));
    echo json_encode(['success'=>false,'message'=>'No updatable fields provided','payload_keys'=>array_values(array_keys($input))]);
    $mysqli->close();
    exit;
}

// append last_modified_at
$fields[] = "`last_modified_at` = NOW()";

// build SQL
$setSql = implode(', ', $fields);
$sql = "UPDATE `operatordoc` SET {$setSql} WHERE `id` = ? LIMIT 1";

// prepare
$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    debug_log("PREPARE_FAILED: " . $mysqli->error . " -- SQL: " . $sql);
    echo json_encode(['success'=>false,'message'=>'Prepare failed: '.$mysqli->error,'sql'=>$sql]);
    $mysqli->close();
    exit;
}

// bind params
$types .= 'i'; // id
$params[] = $id;
$bind = [];
$bind[] = $types;
for ($i = 0; $i < count($params); $i++) $bind[] = & $params[$i];
call_user_func_array([$stmt, 'bind_param'], $bind);

// execute
$execOk = $stmt->execute();
if ($execOk === false) {
    $err = $stmt->error ?: $mysqli->error;
    debug_log("EXECUTE_FAILED: {$err} -- SQL: {$sql} -- params: " . json_encode($params));
    echo json_encode(['success'=>false,'message'=>'Execute failed','error'=>$err,'sql'=>$sql,'params'=>$params]);
    $stmt->close();
    $mysqli->close();
    exit;
}

$affected = $stmt->affected_rows;
debug_log("EXEC_OK affected_rows={$affected} for id={$id}");

// if 0 rows affected: return preview to help debug
if ($affected === 0) {
    $preview = null;
    $pstmt = $mysqli->prepare("SELECT * FROM operatordoc WHERE id = ? LIMIT 1");
    if ($pstmt) {
        $pstmt->bind_param('i', $id);
        $pstmt->execute();
        $res = $pstmt->get_result();
        $preview = $res && $res->num_rows ? $res->fetch_assoc() : null;
        $pstmt->close();
    }
    debug_log("NO_ROWS_UPDATED preview: " . json_encode($preview));
    echo json_encode(['success'=>false,'message'=>'No rows updated (affected_rows = 0). Possible id mismatch or identical values.','row_preview'=>$preview,'sql'=>$sql,'params'=>$params]);
    $stmt->close();
    $mysqli->close();
    exit;
}

// fetch canonical updated values
$colsToReturn = [];
// pick columns we set except last_modified_at
foreach ($fields as $f) {
    if (preg_match('/`([^`]+)`/',$f,$m)) {
        $c = $m[1];
        if ($c === 'last_modified_at') continue;
        $colsToReturn[] = $c;
    }
}
if (count($colsToReturn) === 0) $colsToReturn = ['operator_contact_no','email','aadhar_number','work_status','status'];

$colsEsc = array_map(function($c) use ($mysqli){ return "`".$mysqli->real_escape_string($c)."`"; }, $colsToReturn);
$selSql = "SELECT ". implode(",",$colsEsc) ." FROM operatordoc WHERE id = ? LIMIT 1";
$sel = $mysqli->prepare($selSql);
$row = null;
if ($sel) {
    $sel->bind_param('i',$id);
    $sel->execute();
    $res = $sel->get_result();
    $row = $res && $res->num_rows ? $res->fetch_assoc() : null;
    $sel->close();
}

// clean up & return
$stmt->close();
$mysqli->close();
debug_log("SUCCESS_UPDATED id={$id} return_fields=" . json_encode($row));
echo json_encode(['success'=>true,'message'=>'Row updated','updated_fields'=>$row]);
exit;
