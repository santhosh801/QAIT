<?php
// update_row.php
header('Content-Type: application/json');
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
if ($mysqli->connect_error) { echo json_encode(['success'=>false,'message'=>'DB error']); exit; }

// Allowlist: only update these columns
$allowlist = [
    'operator_full_name','email','branch_name','joining_date','operator_contact_no',
    'father_name','dob','gender','aadhar_number','pan_number','voter_id_no','ration_card',
    'nseit_number','nseit_date','current_hno_street','current_village_town','current_pincode',
    'current_postoffice','current_district','current_state','permanent_hno_street',
    'permanent_village_town','permanent_pincode','permanent_postoffice','permanent_district',
    'permanent_state','bank_name','status','work_status','review_notes'
];

$fields = [];
$params = [];
$types = '';
foreach ($_POST as $k => $v) {
    if ($k === 'id') continue;
    if (!in_array($k, $allowlist, true)) continue;
    $fields[] = "`$k` = ?";
    $params[] = $v;
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
if (!$stmt) { echo json_encode(['success'=>false,'message'=>$mysqli->error]); exit; }
$stmt->bind_param($types, ...$params);
if ($stmt->execute()) {
    echo json_encode(['success'=>true,'message'=>'Row updated']);
} else {
    echo json_encode(['success'=>false,'message'=>'Update error: '.$stmt->error]);
}
$stmt->close();
$mysqli->close();
