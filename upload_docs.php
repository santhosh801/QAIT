<?php
// upload_docs.php
// Bulk upload handler for files[doc_key] and legacy file+doc_key.
// Requirements: PHP 7.2+, mysqli, fileinfo extension enabled.
// Drop-in replacement: saves files under folder "nameofoperator_branchname_resumbiteed"
// and filenames as "nameoperator_doclabelname_resum__<rand>.<ext>"

ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED);
header('Content-Type: application/json; charset=utf-8');
session_start();

// ----- METHOD CHECK -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// ----- DB CONNECT ---------------------------------------------------------
$mysqli = new mysqli('localhost','root','','qmit_system');
if ($mysqli->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB connection error']);
    exit;
}
$mysqli->set_charset('utf8mb4');

// ----- CONFIG -------------------------------------------------------------
$MAX_SIZE = 8 * 1024 * 1024; // 8 MB
$ALLOWED_MIME = ['application/pdf','image/jpeg','image/png'];
$UPLOAD_ROOT = __DIR__ . '/uploads/operatordoc'; // base root; inside this we create the requested folder
if (!is_dir($UPLOAD_ROOT)) @mkdir($UPLOAD_ROOT, 0755, true);

// canonical keys allowed (must match operatordoc columns)
$ALLOWED_DOC_KEYS = [
  'aadhar_file','pan_file','voter_file','ration_file','consent_file','gps_selfie_file',
  'police_verification_file','permanent_address_proof_file','parent_aadhar_file',
  'nseit_cert_file','self_declaration_file','non_disclosure_file','edu_10th_file',
  'edu_12th_file','edu_college_file','agreement_file','bank_passbook_file','photo_file'
];

// ----- HELPERS ------------------------------------------------------------
function json_exit($arr, $code = 200) {
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}
function safe_mime_for_file($tmpPath) {
    try {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        return $finfo->file($tmpPath) ?: '';
    } catch (Exception $e) {
        return '';
    }
}
// sanitize a string to lowercase, keep letters, numbers, dash and underscore
function san($s) {
    $s = (string)$s;
    $s = preg_replace('/[^\pL\pN\-_]+/u', '_', $s);
    $s = preg_replace('/__+/', '_', $s);
    $s = trim($s, "_-");
    $s = strtolower($s);
    if ($s === '') return 'unknown';
    return $s;
}

// ----- TOKEN & OPERATOR RESOLUTION ----------------------------------------
$token = isset($_POST['token']) ? trim((string)$_POST['token']) : '';
$token_request = null;
if ($token !== '') {
    $tStmt = $mysqli->prepare("SELECT id, operator_id, docs_json, expires_at, status FROM resubmission_requests WHERE token = ? LIMIT 1");
    if ($tStmt) {
        $tStmt->bind_param('s', $token);
        $tStmt->execute();
        $tRes = $tStmt->get_result();
        if ($tRes && $tRes->num_rows > 0) {
            $token_request = $tRes->fetch_assoc();
            if (!empty($token_request['expires_at'])) {
                try {
                    $exp = new DateTime($token_request['expires_at']);
                    $now = new DateTime();
                    if ($now > $exp) json_exit(['success'=>false,'message'=>'Token expired'], 400);
                } catch (Exception $e) {}
            }
        } else {
            json_exit(['success'=>false,'message'=>'Invalid token'], 400);
        }
        $tStmt->close();
    }
}

// operator id (prefer POST id, else derive from token row)
$operator_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($operator_id <= 0 && $token_request) $operator_id = (int)$token_request['operator_id'];
if ($operator_id <= 0) json_exit(['success'=>false,'message'=>'Missing operator id'], 400);

// fetch operator full name and branch (if exists) to build folder name
$opStmt = $mysqli->prepare("SELECT id, COALESCE(operator_full_name, operator_id) AS fullname, 
    (CASE WHEN JSON_EXTRACT(JSON_OBJECT(), '$') IS NOT NULL THEN NULL ELSE NULL END) AS _dummy FROM operatordoc WHERE id = ? LIMIT 1");
// Note: above query simply fetches fullname; we'll fetch branch separately if it exists
$opStmt = $mysqli->prepare("SELECT id, operator_full_name, operator_id, email, (SELECT NULL) as branch_check FROM operatordoc WHERE id = ? LIMIT 1");
if (!$opStmt) json_exit(['success'=>false,'message'=>'Operator lookup failed (prepare)'], 500);
$opStmt->bind_param('i', $operator_id);
$opStmt->execute();
$opR = $opStmt->get_result();
if (!$opR || $opR->num_rows === 0) json_exit(['success'=>false,'message'=>'Operator not found'], 404);
$opRow = $opR->fetch_assoc();
$opStmt->close();

// try to get branch if column exists
$branch_name = 'branch_unknown';
$res_check = $mysqli->query("SHOW COLUMNS FROM `operatordoc` LIKE 'branch'");
if ($res_check && $res_check->num_rows) {
    // branch column exists; fetch it
    $bq = $mysqli->prepare("SELECT branch FROM operatordoc WHERE id = ? LIMIT 1");
    if ($bq) {
        $bq->bind_param('i', $operator_id);
        $bq->execute();
        $br = $bq->get_result();
        if ($br && $br->num_rows) {
            $brow = $br->fetch_assoc();
            if (!empty($brow['branch'])) $branch_name = san($brow['branch']);
        }
        $bq->close();
    }
}

// build folder name exactly: nameofoperator_branchname_resumbiteed
$operator_name_raw = $opRow['operator_full_name'] ?? $opRow['operator_id'] ?? 'operator';
$operator_name_s = san($operator_name_raw);
$folder_name = $operator_name_s . '_' . $branch_name . '_resumbiteed';

// build final upload base for this operator
$UPLOAD_BASE = rtrim($UPLOAD_ROOT, '/\\') . '/' . $folder_name;
if (!is_dir($UPLOAD_BASE)) @mkdir($UPLOAD_BASE, 0755, true);

// ensure resubmission_uploads table exists
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

// ----- FILE PROCESSOR -----------------------------------------------------
function process_single_file($tmpPath, $origName, $docKey, $operatorId, $mysqli, $UPLOAD_BASE, $ALLOWED_MIME, $MAX_SIZE, $ALLOWED_DOC_KEYS, $requestId = null, $operator_name_s = 'operator') {
    $result = ['name'=>$origName,'ok'=>false,'msg'=>'','stored'=>null,'doc_key'=>$docKey];

    if (!is_uploaded_file($tmpPath)) { $result['msg']='No uploaded file'; return $result; }

    $size = @filesize($tmpPath);
    if ($size === false) { $result['msg']='Unable to read file'; return $result; }
    if ($size > $MAX_SIZE) { $result['msg']='File too large'; return $result; }

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

    // create random suffix
    try { $rand = bin2hex(random_bytes(6)); } catch (Exception $e) { $rand = substr(md5(uniqid('', true)), 0, 12); }

    // sanitize docKey label part
    $doc_label = preg_replace('/[^a-z0-9\-_]+/i', '_', $docKey);
    $doc_label = preg_replace('/__+/', '_', $doc_label);
    $doc_label = strtolower(trim($doc_label, "_-"));
    if ($doc_label === '') $doc_label = 'doc';

    // build filename exactly: nameoperator_doclabelname_resum__<rand>.<ext>
    $san_operator = preg_replace('/[^a-z0-9\-_]+/i', '_', $operator_name_s);
    $san_operator = strtolower(trim($san_operator, "_-"));
    $filename = $san_operator . '_' . $doc_label . '_resum__' . $rand . '.' . $ext;

    $dest = rtrim($UPLOAD_BASE, '/\\') . '/' . $filename;
    if (!move_uploaded_file($tmpPath, $dest)) { $result['msg']='Failed to move file'; return $result; }

    $webPath = 'uploads/operatordoc/' . $folder = basename($UPLOAD_BASE) . '/' . $filename;
    $result['stored'] = $webPath;

    // If docKey is canonical column -> update operatordoc column
    if (in_array($docKey, $ALLOWED_DOC_KEYS, true)) {
        $col = $docKey;
        $upd = $mysqli->prepare("UPDATE operatordoc SET `$col` = ?, last_modified_at = NOW() WHERE id = ? LIMIT 1");
        if ($upd) {
            $upd->bind_param('si', $webPath, $operatorId);
            $ok = $upd->execute();
            $upd->close();
            if (!$ok) {
                $result['msg']='DB update failed';
                return $result;
            }
        } else {
            $result['msg']='DB prepare failed (update)';
            return $result;
        }
    }

    // log into resubmission_uploads
    $uploaded_by = isset($_SESSION['employee_email']) ? $_SESSION['employee_email'] : null;
    $log = $mysqli->prepare("INSERT INTO resubmission_uploads (request_id, operator_id, doc_key, filename, uploaded_by) VALUES (?, ?, ?, ?, ?)");
    if ($log) {
        $request_id_param = $requestId !== null ? intval($requestId) : 0;
        $log->bind_param('iisss', $request_id_param, $operatorId, $docKey, $filename, $uploaded_by);
        @$log->execute();
        $log->close();
    }

    $result['ok'] = true;
    return $result;
}

// ----- PROCESS UPLOADS ---------------------------------------------------
$summary = [];

// legacy single-file upload (file + doc_key)
if (isset($_FILES['file']) && isset($_POST['doc_key'])) {
    $doc_key = trim((string)($_POST['doc_key']));
    $file = $_FILES['file'];
    if ($file['error'] === UPLOAD_ERR_OK) {
        $res = process_single_file($file['tmp_name'], $file['name'], $doc_key, $operator_id, $mysqli, $UPLOAD_BASE, $ALLOWED_MIME, $MAX_SIZE, $ALLOWED_DOC_KEYS, ($token_request['id'] ?? null), $operator_name_s);
        $summary[$doc_key][] = $res;
    } else {
        $summary[$doc_key][] = ['name'=>'','ok'=>false,'msg'=>'Upload error code: '.$file['error'],'doc_key'=>$doc_key];
    }
    json_exit(['success'=>true,'summary'=>$summary]);
}

// bulk associative files structure: $_FILES['files']['name'][docKey]
if (!empty($_FILES['files']) && !empty($_FILES['files']['name'])) {
    foreach ($_FILES['files']['name'] as $docKey => $nameField) {
        // handle both single and multiple for each docKey
        if (is_array($nameField)) {
            $count = count($nameField);
            for ($i=0; $i<$count; $i++) {
                $origName = $nameField[$i];
                $tmpPath = $_FILES['files']['tmp_name'][$docKey][$i] ?? null;
                $err = $_FILES['files']['error'][$docKey][$i] ?? UPLOAD_ERR_NO_FILE;
                if ($err === UPLOAD_ERR_NO_FILE) continue;
                if ($err !== UPLOAD_ERR_OK) { $summary[$docKey][] = ['name'=>$origName,'ok'=>false,'msg'=>'Upload error '.$err,'doc_key'=>$docKey]; continue; }
                $res = process_single_file($tmpPath, $origName, $docKey, $operator_id, $mysqli, $UPLOAD_BASE, $ALLOWED_MIME, $MAX_SIZE, $ALLOWED_DOC_KEYS, ($token_request['id'] ?? null), $operator_name_s);
                $summary[$docKey][] = $res;
            }
        } else {
            $origName = $nameField;
            $tmpPath = $_FILES['files']['tmp_name'][$docKey] ?? null;
            $err = $_FILES['files']['error'][$docKey] ?? UPLOAD_ERR_NO_FILE;
            if ($err === UPLOAD_ERR_NO_FILE) continue;
            if ($err !== UPLOAD_ERR_OK) { $summary[$docKey][] = ['name'=>$origName,'ok'=>false,'msg'=>'Upload error '.$err,'doc_key'=>$docKey]; continue; }
            $res = process_single_file($tmpPath, $origName, $docKey, $operator_id, $mysqli, $UPLOAD_BASE, $ALLOWED_MIME, $MAX_SIZE, $ALLOWED_DOC_KEYS, ($token_request['id'] ?? null), $operator_name_s);
            $summary[$docKey][] = $res;
        }
    }

    // After processing, if token_request present -> check completion and mark used if all present
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
                if (empty($docs)) $all_present = false;

                foreach ($docs as $dk) {
                    if (!in_array($dk, $ALLOWED_DOC_KEYS, true)) { $all_present = false; break; }
                    $qq = $mysqli->prepare("SELECT `$dk` FROM operatordoc WHERE id = ? LIMIT 1");
                    if ($qq) {
                        $qq->bind_param('i', $operator_id);
                        $qq->execute();
                        $r2 = $qq->get_result();
                        $qq->close();
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

    json_exit(['success'=>true,'summary'=>$summary]);
}

// files[general][] multi-upload (fallback)
if (!empty($_FILES['files']) && isset($_FILES['files']['name']['general']) && is_array($_FILES['files']['name']['general'])) {
    $docKey = 'general';
    $count = count($_FILES['files']['name']['general']);
    for ($i=0; $i<$count; $i++) {
        $origName = $_FILES['files']['name']['general'][$i];
        $tmpPath = $_FILES['files']['tmp_name']['general'][$i] ?? null;
        $err = $_FILES['files']['error']['general'][$i] ?? UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_NO_FILE) continue;
        if ($err !== UPLOAD_ERR_OK) { $summary[$docKey][] = ['name'=>$origName,'ok'=>false,'msg'=>'Upload error '.$err,'doc_key'=>$docKey]; continue; }
        $dynamicKey = $docKey . '_' . $i;
        $res = process_single_file($tmpPath, $origName, $dynamicKey, $operator_id, $mysqli, $UPLOAD_BASE, $ALLOWED_MIME, $MAX_SIZE, array_merge($ALLOWED_DOC_KEYS, [$dynamicKey]), ($token_request['id'] ?? null), $operator_name_s);
        $summary[$docKey][] = $res;
    }
    json_exit(['success'=>true,'summary'=>$summary]);
}

// nothing matched
json_exit(['success'=>false,'message'=>'No files received or unsupported structure'], 400);
