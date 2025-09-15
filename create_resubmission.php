<?php
// create_resubmission.php
// Creates a resubmission request and optionally triggers send_rejection_mail.php
// Expects POST: id (operator id), docs[] (array of canonical doc keys), expires_days (optional), email_now (optional)
// Session must contain employee_email (verifier)

session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['employee_email']) || empty($_SESSION['employee_email'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// DB
$mysqli = new mysqli("localhost", "root", "", "qmit_system");
if ($mysqli->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB connection failed']);
    exit;
}
$mysqli->set_charset('utf8mb4');

// canonical allowed doc keys (extend as needed)
$allowedDocKeys = [
  'aadhar_file','pan_file','voter_file','ration_file','consent_file','gps_selfie_file',
  'police_verification_file','permanent_address_proof_file','parent_aadhar_file','nseit_cert_file',
  'self_declaration_file','non_disclosure_file','edu_10th_file','edu_12th_file','edu_college_file',
  'agreement_file','bank_passbook_file','photo_file'
];

// helper token
function genToken($len = 32) { return bin2hex(random_bytes($len)); }

// parse inputs
$opId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$docs = [];
if (isset($_POST['docs'])) {
    if (is_array($_POST['docs'])) $docs = array_values($_POST['docs']);
    else {
        $tmp = json_decode((string)$_POST['docs'], true);
        if (is_array($tmp)) $docs = array_values($tmp);
        else $docs = array_filter(array_map('trim', explode(',', (string)$_POST['docs'])));
    }
}
$expires_days = isset($_POST['expires_days']) ? (int)$_POST['expires_days'] : 7;
$email_now = isset($_POST['email_now']) && ($_POST['email_now'] === '1' || $_POST['email_now'] === 'true' || $_POST['email_now'] === true);

// basic validation
if ($opId <= 0) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Invalid operator id']); exit; }
if (empty($docs) || !is_array($docs)) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'No documents selected']); exit; }
if ($expires_days < 1) $expires_days = 7;

// normalize & filter docs to allowed keys
$docs = array_values(array_filter(array_map('trim', $docs), function($d) use ($allowedDocKeys){ return in_array($d, $allowedDocKeys, true); }));
if (empty($docs)) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'No valid document keys provided']); exit; }

// ensure operator exists
$stmt = $mysqli->prepare("SELECT id, operator_full_name, email FROM operatordoc WHERE id = ? LIMIT 1");
if (!$stmt) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'DB prepare failed']); exit; }
$stmt->bind_param('i', $opId);
$stmt->execute();
$rs = $stmt->get_result();
if ($rs->num_rows === 0) { $stmt->close(); http_response_code(404); echo json_encode(['success'=>false,'message'=>'Operator not found']); exit; }
$opRow = $rs->fetch_assoc();
$stmt->close();

// create table if missing (safe create)
$mysqli->query("CREATE TABLE IF NOT EXISTS `resubmission_requests` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `operator_id` INT NOT NULL,
  `token` VARCHAR(128) NOT NULL UNIQUE,
  `docs_json` TEXT,
  `expires_at` DATETIME DEFAULT NULL,
  `created_by` VARCHAR(255),
  `status` VARCHAR(32) DEFAULT 'open',
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `completed_at` DATETIME DEFAULT NULL,
  `used` TINYINT(1) DEFAULT 0,
  `used_at` DATETIME DEFAULT NULL,
  INDEX (operator_id),
  INDEX (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// generate token (unique)
$tries = 0; $token = ''; $exists = false;
do {
    $token = genToken(32);
    $q = $mysqli->prepare("SELECT 1 FROM resubmission_requests WHERE token = ? LIMIT 1");
    if (!$q) break;
    $q->bind_param('s', $token);
    $q->execute();
    $qr = $q->get_result();
    $exists = ($qr && $qr->num_rows > 0);
    $q->close();
    $tries++;
} while ($exists && $tries < 6);
if ($exists) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Failed to generate unique token']); exit; }

// insert
$expires_at = (new DateTime())->modify('+' . intval($expires_days) . ' days')->format('Y-m-d H:i:s');
$created_by = $_SESSION['employee_email'];
$docs_json = json_encode(array_values($docs), JSON_UNESCAPED_UNICODE);

$ins = $mysqli->prepare("INSERT INTO resubmission_requests (operator_id, token, docs_json, expires_at, created_by) VALUES (?, ?, ?, ?, ?)");
if (!$ins) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'DB insert prepare failed']); exit; }
$ins->bind_param('issss', $opId, $token, $docs_json, $expires_at, $created_by);
$ok = $ins->execute();
if (!$ok) { $ins->close(); http_response_code(500); echo json_encode(['success'=>false,'message'=>'Failed to create request','error'=>$mysqli->error]); exit; }
$inserted_id = $ins->insert_id; $ins->close();

// build URL
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$dir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\'); $dir = $dir === '/' ? '' : $dir;
$resubmit_url = $scheme . '://' . $host . $dir . '/duplicateoperator.php?token=' . urlencode($token);

// optional email trigger (calls send_rejection_mail.php via local POST)
$emailed = false; $mail_response = null;
if ($email_now) {
    $mail_endpoint = $scheme . '://' . $host . $dir . '/send_rejection_mail.php';
    if (function_exists('curl_version')) {
        $ch = curl_init($mail_endpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $payload = http_build_query(['id' => $opId, 'token' => $token], '', '&');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $resp = curl_exec($ch);
        $curl_err = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp !== false && $http_code >= 200 && $http_code < 300) {
            $decoded = json_decode($resp, true);
            if (is_array($decoded) && isset($decoded['success']) && $decoded['success']) { $emailed = true; $mail_response = $decoded; }
            else { $mail_response = $decoded ?: ['raw'=>$resp,'http_code'=>$http_code]; }
        } else { $mail_response = ['error'=>$curl_err ?: 'HTTP '.$http_code,'raw'=>$resp]; }
    } else { $mail_response = ['error'=>'cURL unavailable']; }
}

$response = [
  'success'=>true,
  'token'=>$token,
  'url'=>$resubmit_url,
  'request_id'=>$inserted_id,
  'emailed'=>$emailed,
  'message'=>'Resubmission request created'
];
if (!empty($mail_response)) $response['mail_response']=$mail_response;
echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
