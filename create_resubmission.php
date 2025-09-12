<?php
// create_resubmission.php
// Creates a resubmission request row and returns JSON { success:true, token, url, request_id }

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

// DB connect
$mysqli = new mysqli("localhost", "root", "", "qmit_system");
if ($mysqli->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB connection failed']);
    exit;
}
$mysqli->set_charset('utf8mb4');

// canonical allowed doc keys (keep in sync with your other code)
$allowedDocKeys = [
  'aadhar_file','pan_file','voter_file','ration_file','consent_file','gps_selfie_file',
  'police_verification_file','permanent_address_proof_file','parent_aadhar_file','nseit_cert_file',
  'self_declaration_file','non_disclosure_file','edu_10th_file','edu_12th_file','edu_college_file'
];

// Helper: generate secure token
function genToken($len = 32) {
    return bin2hex(random_bytes($len));
}

// --------- parse input ----------
$opId       = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$docs       = [];
if (isset($_POST['docs'])) {
    if (is_array($_POST['docs'])) {
        $docs = array_values($_POST['docs']);
    } else {
        $tmp = json_decode((string)$_POST['docs'], true);
        if (is_array($tmp)) $docs = array_values($tmp);
        else $docs = array_map('trim', explode(',', (string)$_POST['docs']));
    }
}
$expires_days = isset($_POST['expires_days']) ? (int)$_POST['expires_days'] : 7;
$email_now    = isset($_POST['email_now']) && ($_POST['email_now'] === '1' || $_POST['email_now'] === 1 || $_POST['email_now'] === true);

// basic validation
if ($opId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid operator id']);
    exit;
}
if (empty($docs) || !is_array($docs)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No documents selected for resubmission']);
    exit;
}
if ($expires_days < 1) $expires_days = 7;

// normalize and filter docs to allowed list
$docs = array_values(array_filter(array_map('trim', $docs), function($d) use ($allowedDocKeys){
    return in_array($d, $allowedDocKeys, true);
}));
if (empty($docs)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No valid document keys provided']);
    exit;
}

// --------- ensure operator exists (operatordoc.id) ----------
$stmt = $mysqli->prepare("SELECT id, operator_full_name, email FROM operatordoc WHERE id = ? LIMIT 1");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB prepare failed']);
    exit;
}
$stmt->bind_param('i', $opId);
$stmt->execute();
$rs = $stmt->get_result();
if ($rs->num_rows === 0) {
    $stmt->close();
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Operator not found']);
    exit;
}
$opRow = $rs->fetch_assoc();
$stmt->close();

// --------- ensure resubmission_requests table & columns exist (safe create/alter) ----------
function ensure_resubmission_table(mysqli $m) {
    // Create table if not exists (includes used, used_at). Fields chosen to be compatible with duplicateoperator.php
    $sqlCreate = "CREATE TABLE IF NOT EXISTS `resubmission_requests` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      `operator_id` INT NOT NULL,
      `token` VARCHAR(128) NOT NULL UNIQUE,
      `docs_json` TEXT,
      `expires_at` DATETIME DEFAULT NULL,
      `created_by` VARCHAR(255),
      `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
      `used` TINYINT(1) NOT NULL DEFAULT 0,
      `used_at` DATETIME DEFAULT NULL,
      INDEX (operator_id),
      INDEX (token)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $m->query($sqlCreate);

    // If table exists but lacks 'used' or 'used_at', add them (use SHOW COLUMNS check)
    $cols = [];
    if ($res = $m->query("SHOW COLUMNS FROM `resubmission_requests`")) {
        while ($row = $res->fetch_assoc()) $cols[] = $row['Field'];
        $res->free();
    }
    if (!in_array('used', $cols, true)) {
        // add used
        $m->query("ALTER TABLE `resubmission_requests` ADD COLUMN `used` TINYINT(1) NOT NULL DEFAULT 0");
    }
    if (!in_array('used_at', $cols, true)) {
        $m->query("ALTER TABLE `resubmission_requests` ADD COLUMN `used_at` DATETIME NULL DEFAULT NULL");
    }
}
ensure_resubmission_table($mysqli);

// --------- generate unique token ----------
$token = '';
$tries = 0;
$exists = false;
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

if ($exists) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to generate unique token, try again']);
    exit;
}

// --------- insert request ----------
$expires_at = (new DateTime())->modify('+' . intval($expires_days) . ' days')->format('Y-m-d H:i:s');
$created_by = $_SESSION['employee_email'];
$docs_json  = json_encode(array_values($docs), JSON_UNESCAPED_UNICODE);

$ins = $mysqli->prepare("INSERT INTO resubmission_requests (operator_id, token, docs_json, expires_at, created_by) VALUES (?, ?, ?, ?, ?)");
if (!$ins) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB insert prepare failed']);
    exit;
}
$ins->bind_param('issss', $opId, $token, $docs_json, $expires_at, $created_by);
$ok = $ins->execute();
if (!$ok) {
    $ins->close();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to create resubmission request', 'error' => $mysqli->error]);
    exit;
}
$inserted_id = $ins->insert_id;
$ins->close();

// Build absolute URL for convenience
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$dir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$dir = $dir === '/' ? '' : $dir;
$resubmit_url = $scheme . '://' . $host . $dir . '/duplicateoperator.php?token=' . urlencode($token);

// Optionally email now (POST to send_rejection_mail.php)
$emailed = false;
$mail_response = null;
if ($email_now) {
    $mail_endpoint = $scheme . '://' . $host . $dir . '/send_rejection_mail.php';
    if (function_exists('curl_version')) {
        $ch = curl_init($mail_endpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $payload = http_build_query(['id' => $opId, 'token' => $token], '', '&');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        if (isset($_COOKIE[session_name()])) {
            curl_setopt($ch, CURLOPT_COOKIE, session_name() . '=' . urlencode($_COOKIE[session_name()]));
        }
        $resp = curl_exec($ch);
        $curl_err = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp !== false && $http_code >= 200 && $http_code < 300) {
            $decoded = json_decode($resp, true);
            if (is_array($decoded) && isset($decoded['success']) && $decoded['success']) {
                $emailed = true;
                $mail_response = $decoded;
            } else {
                $mail_response = $decoded ?: ['raw' => $resp, 'http_code' => $http_code];
            }
        } else {
            $mail_response = ['error' => $curl_err ?: 'HTTP ' . $http_code, 'raw' => $resp];
        }
    } else {
        $mail_response = ['error' => 'cURL unavailable'];
    }
}

// --------- return JSON ----------
$response = [
    'success' => true,
    'token' => $token,
    'url' => $resubmit_url,
    'request_id' => $inserted_id,
    'emailed' => $emailed,
    'message' => 'Resubmission request created'
];
if (!empty($mail_response)) $response['mail_response'] = $mail_response;
echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
