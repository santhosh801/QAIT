<?php
// update_doc_review.php
session_start();
header('Content-Type: application/json; charset=utf-8');

// quick auth guard (adjust if your app uses different check)
if (!isset($_SESSION['employee_email'])) {
    http_response_code(401);
    echo json_encode(['success'=>false,'message'=>'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success'=>false,'message'=>'Method not allowed']);
    exit;
}

$mysqli = new mysqli('localhost','root','','qmit_system');
if ($mysqli->connect_errno) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'DB connection error: '.$mysqli->connect_error]);
    exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$doc_key = isset($_POST['doc_key']) ? trim($_POST['doc_key']) : '';
$action = isset($_POST['action']) ? trim($_POST['action']) : '';
$reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';

if (!$id || !$doc_key || !in_array($action, ['accept','reject'])) {
    echo json_encode(['success'=>false,'message'=>'Invalid params: id/doc_key/action required']);
    exit;
}

// label map (keep in sync)
$labels = [
  'aadhar_file'=>'Aadhaar Card','pan_file'=>'PAN Card','voter_file'=>'Voter ID',
  'ration_file'=>'Ration Card','consent_file'=>'Consent','gps_selfie_file'=>'GPS Selfie',
  'permanent_address_proof_file'=>'Permanent Address Proof','nseit_cert_file'=>'NSEIT Certificate',
  'self_declaration_file'=>'Self Declaration','non_disclosure_file'=>'Non-Disclosure Agreement',
  'police_verification_file'=>'Police Verification','parent_aadhar_file'=>"Parent's Aadhaar",
  'edu_10th_file'=>'10th Certificate','edu_12th_file'=>'12th Certificate','edu_college_file'=>'College Certificate'
];

$label = $labels[$doc_key] ?? $doc_key;

// fetch current rejection_summary
$stmt = $mysqli->prepare("SELECT rejection_summary FROM operatordoc WHERE id = ? LIMIT 1");
if (!$stmt) { echo json_encode(['success'=>false,'message'=>'DB prepare failed']); exit; }
$stmt->bind_param('i', $id);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$stmt->close();

$cur = $row['rejection_summary'] ?? '';

$lines = [];
if (strlen(trim($cur))>0) {
    $lines = preg_split('/\r\n|\n|\r/', $cur);
    $lines = array_values(array_filter(array_map('trim', $lines)));
}

function remove_doc_line($lines, $label) {
    return array_values(array_filter($lines, function($l) use ($label) {
        return stripos($l, $label . ':') !== 0;
    }));
}

if ($action === 'accept') {
    $lines = remove_doc_line($lines, $label);
    $newSummary = count($lines) ? implode("\n", $lines) : null;
    $stmt = $mysqli->prepare("UPDATE operatordoc SET rejection_summary = ?, last_modified_at = NOW() WHERE id = ?");
    $stmt->bind_param('si', $newSummary, $id);
    $ok = $stmt->execute();
    $stmt->close();
    echo json_encode(['success'=>(bool)$ok,'message'=>'Document accepted']);
    exit;
}

if ($action === 'reject') {
    if ($reason === '') {
        echo json_encode(['success'=>false,'message'=>'Provide reason for rejection']);
        exit;
    }
    $lines = remove_doc_line($lines, $label);
    $lines[] = $label . ': ' . $reason;
    $newSummary = implode("\n", $lines);

    $stmt = $mysqli->prepare("UPDATE operatordoc SET rejection_summary = ?, last_modified_at = NOW(), status = 'pending' WHERE id = ?");
    $stmt->bind_param('si', $newSummary, $id);
    $ok = $stmt->execute();
    $stmt->close();
    echo json_encode(['success'=>(bool)$ok,'message'=>'Document rejected']);
    exit;
}

echo json_encode(['success'=>false,'message'=>'unknown action']);
exit;
