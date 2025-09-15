<?php
// upload_docs.php
// Bulk upload handler for files[doc_key] and legacy file+doc_key.
// Put this file in your webroot/QAIT/ folder.
// Requirements: PHP 7.2+, mysqli, fileinfo extension enabled.

ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_NOTICE);
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// DB connection (adjust credentials if necessary)
$mysqli = new mysqli('localhost','root','','qmit_system');
if ($mysqli->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB connection error']);
    exit;
}
$mysqli->set_charset('utf8mb4');

// config
$MAX_SIZE = 8 * 1024 * 1024; // 8 MB
$ALLOWED_MIME = ['application/pdf','image/jpeg','image/png'];
$UPLOAD_BASE = __DIR__ . '/uploads/operatordoc';
if (!is_dir($UPLOAD_BASE)) @mkdir($UPLOAD_BASE, 0755, true);

// canonical keys allowed (must match operatordoc columns)
$ALLOWED_DOC_KEYS = [
  'aadhar_file','pan_file','voter_file','ration_file','consent_file','gps_selfie_file',
  'police_verification_file','permanent_address_proof_file','parent_aadhar_file',
  'nseit_cert_file','self_declaration_file','non_disclosure_file','edu_10th_file',
  'edu_12th_file','edu_college_file','agreement_file','bank_passbook_file','photo_file'
];

// optional token validation (if provided)
$token = isset($_POST['token']) ? trim($_POST['token']) : '';
$token_request = null;
if ($token !== '') {
    $tStmt = $mysqli->prepare("SELECT id, operator_id, docs_json, expires_at FROM resubmission_requests WHERE token = ? LIMIT 1");
    if ($tStmt) {
        $tStmt->bind_param('s', $token);
        $tStmt->execute();
        $tRes = $tStmt->get_result();
        if ($tRes && $tRes->num_rows > 0) $token_request = $tRes->fetch_assoc();
        else { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Invalid token']); exit; }
        $tStmt->close();
    }
}

// operator id (prefer POST, else token-derived)
$operator_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($operator_id <= 0 && $token_request) $operator_id = (int)$token_request['operator_id'];
if ($operator_id <= 0) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Missing operator id']); exit; }

// helper to process + store a single file
function process_single_file($tmpPath, $origName, $docKey, $operatorId, $mysqli, $UPLOAD_BASE, $ALLOWED_MIME, $MAX_SIZE, $ALLOWED_DOC_KEYS) {
    $result = ['name'=>$origName,'ok'=>false,'msg'=>''];

    if (!in_array($docKey, $ALLOWED_DOC_KEYS, true)) {
        $result['msg'] = 'Invalid document key';
        return $result;
    }
    if (!is_uploaded_file($tmpPath)) { $result['msg']='No uploaded file'; return $result; }

    $size = filesize($tmpPath);
    if ($size === false || $size > $MAX_SIZE) { $result['msg']='File too large'; return $result; }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmpPath) ?: '';
    if (!in_array($mime, $ALLOWED_MIME, true)) { $result['msg']='Invalid file type: ' . $mime; return $result; }

    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','pdf'], true)) {
        if ($mime === 'application/pdf') $ext = 'pdf';
        elseif ($mime === 'image/png') $ext = 'png';
        elseif (strpos($mime, 'jpeg') !== false) $ext = 'jpg';
        else $ext = 'bin';
    }

    $safeName = $docKey . '__' . bin2hex(random_bytes(6)) . '.' . $ext;
    $userDir = rtrim($UPLOAD_BASE, '/\\') . '/' . intval($operatorId);
    if (!is_dir($userDir)) @mkdir($userDir, 0755, true);
    $dest = $userDir . '/' . $safeName;

    if (!move_uploaded_file($tmpPath, $dest)) { $result['msg']='Failed to move file'; return $result; }

    $webPath = 'uploads/operatordoc/' . intval($operatorId) . '/' . $safeName;

    // update operatordoc column (safe because $docKey is validated)
    $col = $docKey;
    $upd = $mysqli->prepare("UPDATE operatordoc SET `$col` = ?, last_modified_at = NOW() WHERE id = ? LIMIT 1");
    if ($upd) {
        $upd->bind_param('si', $webPath, $operatorId);
        $ok = $upd->execute();
        $upd->close();
        if (!$ok) { $result['msg']='DB update failed'; return $result; }
    } else {
        $result['msg']='DB prepare failed';
        return $result;
    }

    // optional logging
    $req_id = isset($GLOBALS['token_request']['id']) ? intval($GLOBALS['token_request']['id']) : null;
    $log = $mysqli->prepare("INSERT INTO resubmission_uploads (request_id, operator_id, doc_key, filename, uploaded_at) VALUES (?, ?, ?, ?, NOW())");
    if ($log) {
        $request_id_param = $req_id ?? 0;
        $log->bind_param('iiss', $request_id_param, $operatorId, $docKey, $safeName);
        $log->execute();
        $log->close();
    }

    $result['ok'] = true;
    $result['stored'] = $webPath;
    return $result;
}

// ensure logs table exists (no-op if exists)
$mysqli->query("CREATE TABLE IF NOT EXISTS resubmission_uploads (
  id INT AUTO_INCREMENT PRIMARY KEY,
  request_id INT DEFAULT NULL,
  operator_id INT NOT NULL,
  doc_key VARCHAR(128) NOT NULL,
  filename VARCHAR(255) NOT NULL,
  uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  uploaded_by VARCHAR(255) DEFAULT NULL,
  INDEX (request_id), INDEX (operator_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// collect results
$summary = [];

// legacy single-file upload
if (isset($_FILES['file']) && isset($_POST['doc_key'])) {
    $doc_key = trim($_POST['doc_key']);
    $file = $_FILES['file'];
    if ($file['error'] === UPLOAD_ERR_OK) {
        $res = process_single_file($file['tmp_name'], $file['name'], $doc_key, $operator_id, $mysqli, $UPLOAD_BASE, $ALLOWED_MIME, $MAX_SIZE, $ALLOWED_DOC_KEYS);
        $summary[$doc_key][] = $res;
    } else {
        $summary[$doc_key][] = ['name'=>'','ok'=>false,'msg'=>'Upload error code: '.$file['error']];
    }
    echo json_encode(['success'=>true,'summary'=>$summary]);
    exit;
}

// bulk associative files structure: $_FILES['files']['name'][docKey]
if (!empty($_FILES['files']) && !empty($_FILES['files']['name'])) {
    foreach ($_FILES['files']['name'] as $docKey => $nameField) {
        if (is_array($nameField)) {
            $count = count($nameField);
            for ($i=0;$i<$count;$i++) {
                $origName = $nameField[$i];
                $tmpPath = $_FILES['files']['tmp_name'][$docKey][$i] ?? null;
                $err = $_FILES['files']['error'][$docKey][$i] ?? UPLOAD_ERR_NO_FILE;
                if ($err === UPLOAD_ERR_NO_FILE) continue;
                if ($err !== UPLOAD_ERR_OK) { $summary[$docKey][] = ['name'=>$origName,'ok'=>false,'msg'=>'Upload error '.$err]; continue; }
                $res = process_single_file($tmpPath, $origName, $docKey, $operator_id, $mysqli, $UPLOAD_BASE, $ALLOWED_MIME, $MAX_SIZE, $ALLOWED_DOC_KEYS);
                $summary[$docKey][] = $res;
            }
        } else {
            $origName = $nameField;
            $tmpPath = $_FILES['files']['tmp_name'][$docKey] ?? null;
            $err = $_FILES['files']['error'][$docKey] ?? UPLOAD_ERR_NO_FILE;
            if ($err === UPLOAD_ERR_NO_FILE) continue;
            if ($err !== UPLOAD_ERR_OK) { $summary[$docKey][] = ['name'=>$origName,'ok'=>false,'msg'=>'Upload error '.$err]; continue; }
            $res = process_single_file($tmpPath, $origName, $docKey, $operator_id, $mysqli, $UPLOAD_BASE, $ALLOWED_MIME, $MAX_SIZE, $ALLOWED_DOC_KEYS);
            $summary[$docKey][] = $res;
        }
    }

    // after processing: if token_request present, check if all requested docs are present and mark request used
    if (!empty($token_request)) {
        $req_id = intval($token_request['id']);
        $r = $mysqli->prepare("SELECT docs_json, status FROM resubmission_requests WHERE id = ? LIMIT 1");
        if ($r) {
            $r->bind_param('i', $req_id);
            $r->execute();
            $qres = $r->get_result();
            if ($qres && $qres->num_rows) {
                $row = $qres->fetch_assoc();
                $docs = json_decode($row['docs_json'] ?? '[]', true) ?: [];
                $all_present = true;
                foreach ($docs as $dk) {
                    if (!in_array($dk, $ALLOWED_DOC_KEYS, true)) { $all_present = false; break; }
                    $qq = $mysqli->prepare("SELECT `$dk` FROM operatordoc WHERE id = ? LIMIT 1");
                    if ($qq) {
                        $qq->bind_param('i', $operator_id); $qq->execute(); $r2 = $qq->get_result(); $qq->close();
                        $has = false;
                        if ($r2 && $r2->num_rows) {
                            $rrow = $r2->fetch_assoc();
                            if (!empty($rrow[$dk])) $has = true;
                        }
                        if (!$has) { $all_present = false; break; }
                    } else { $all_present = false; break; }
                }
                if ($all_present) {
                    $u = $mysqli->prepare("UPDATE resubmission_requests SET used = 1, used_at = NOW(), completed_at = NOW(), status = 'completed' WHERE id = ? LIMIT 1");
                    if ($u) { $u->bind_param('i', $req_id); $u->execute(); $u->close(); }
                }
            }
            $r->close();
        }
    }

    echo json_encode(['success'=>true,'summary'=>$summary]);
    exit;
}

// files[general][]
if (!empty($_FILES['files']) && isset($_FILES['files']['name']['general']) && is_array($_FILES['files']['name']['general'])) {
    $docKey = 'general';
    $count = count($_FILES['files']['name']['general']);
    for ($i=0;$i<$count;$i++) {
        $origName = $_FILES['files']['name']['general'][$i];
        $tmpPath = $_FILES['files']['tmp_name']['general'][$i] ?? null;
        $err = $_FILES['files']['error']['general'][$i] ?? UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_NO_FILE) continue;
        if ($err !== UPLOAD_ERR_OK) { $summary[$docKey][] = ['name'=>$origName,'ok'=>false,'msg'=>'Upload error '.$err]; continue; }
        $res = process_single_file($tmpPath, $origName, $docKey . '_' . $i, $operator_id, $mysqli, $UPLOAD_BASE, $ALLOWED_MIME, $MAX_SIZE, array_merge($ALLOWED_DOC_KEYS, [$docKey . '_' . $i]));
        $summary[$docKey][] = $res;
    }
    echo json_encode(['success'=>true,'summary'=>$summary]);
    exit;
}

http_response_code(400);
echo json_encode(['success'=>false,'message'=>'No files received or unsupported structure']);
exit;
