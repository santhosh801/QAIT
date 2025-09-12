<?php
// upload_doc.php
session_start();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit;
}

$mysqli = new mysqli('localhost','root','','qmit_system');
if ($mysqli->connect_error) { echo json_encode(['success'=>false,'message'=>'DB error']); exit; }

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$doc_key = isset($_POST['doc_key']) ? trim($_POST['doc_key']) : '';

if (!$id || !$doc_key) { echo json_encode(['success'=>false,'message'=>'Invalid params']); exit; }

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success'=>false,'message'=>'File upload error']); exit;
}

$file = $_FILES['file'];
$maxSize = 8 * 1024 * 1024; // 8MB
$allowed = ['application/pdf','image/jpeg','image/png'];

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']) ?: '';

if (!in_array($mime, $allowed)) {
    echo json_encode(['success'=>false,'message'=>'Invalid file type']); exit;
}
if ($file['size'] > $maxSize) {
    echo json_encode(['success'=>false,'message'=>'File too large']); exit;
}

$ext = pathinfo($file['name'], PATHINFO_EXTENSION);
$ext = preg_replace('/[^a-z0-9]/i','', $ext);
$baseDir = __DIR__ . '/uploads/operatordoc/' . $id;
if (!is_dir($baseDir)) mkdir($baseDir, 0755, true);

$filename = $doc_key . '__' . time() . '.' . ($ext ?: 'bin');
$dest = $baseDir . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    echo json_encode(['success'=>false,'message'=>'Failed to move file']); exit;
}

// create web-accessible path relative to project root (adjust if you store uploads outside webroot)
$webPath = 'uploads/operatordoc/' . $id . '/' . $filename;

// Security: validate doc_key against allowlist to avoid SQL injection via column name
$allowedDocKeys = [
  'aadhar_file','pan_file','voter_file','ration_file','consent_file','gps_selfie_file',
  'police_verification_file','permanent_address_proof_file','parent_aadhar_file',
  'nseit_cert_file','self_declaration_file','non_disclosure_file','edu_10th_file',
  'edu_12th_file','edu_college_file'
];

if (!in_array($doc_key, $allowedDocKeys, true)) {
    // remove just-uploaded file to avoid orphan
    @unlink($dest);
    echo json_encode(['success'=>false,'message'=>'Invalid document key']); exit;
}

// update DB column (column name inserted only after validation)
$col = $doc_key; // safe because validated
$sql = "UPDATE operatordoc SET `$col` = ?, last_modified_at = NOW() WHERE id = ?";
$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    echo json_encode(['success'=>false,'message'=>'DB prepare failed']); exit;
}
$stmt->bind_param('si', $webPath, $id);
$ok = $stmt->execute();
$stmt->close();

if ($ok) echo json_encode(['success'=>true,'message'=>'Uploaded','path'=>$webPath]);
else echo json_encode(['success'=>false,'message'=>'DB update failed']);
exit;
