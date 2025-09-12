<?php
// send_rejection_mail.php
// Sends the consolidated rejection + resubmission link to an operator.
// Expects POST: id (operator id) and optional token (resubmission token).
// Returns JSON.

session_start();
require __DIR__ . '/vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json; charset=utf-8');

// CONFIG - adjust or move to env
$SMTP_DEBUG = false;   // set true to capture SMTP conversation (temporarily)
$DUMP_TO_LOG = false;  // also write SMTP debug to server error_log

// Basic request checks
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$token = isset($_POST['token']) ? trim((string)$_POST['token']) : '';

if (!$id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing operator id']);
    exit;
}

$mysqli = new mysqli('localhost', 'root', '', 'qmit_system');
if ($mysqli->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB connection error']);
    exit;
}
$mysqli->set_charset('utf8mb4');

// fetch operator row (and current rejection_summary)
$stmt = $mysqli->prepare("SELECT id, operator_full_name, operator_id, operator_contact_no, email, rejection_summary FROM operatordoc WHERE id = ? LIMIT 1");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB prepare failed']);
    exit;
}
$stmt->bind_param('i', $id);
$stmt->execute();
$res = $stmt->get_result();
$op = $res->fetch_assoc();
$stmt->close();

if (!$op) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Operator record not found.']);
    exit;
}

$operator_email = trim($op['email'] ?? '');
$opName = trim($op['operator_full_name'] ?: $op['operator_id'] ?: 'Operator');
$rejSummary = trim($op['rejection_summary'] ?? '');

if ($operator_email === '' || !filter_var($operator_email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Operator email invalid or missing']);
    exit;
}

//
// Resolve token: if token not provided, attempt to find latest resubmission_requests for this operator
//
if ($token === '') {
    // try to find most recent token for operator_id in resubmission_requests
    // (do NOT assume any 'used' column exists; just look for latest by created_at)
    $q = $mysqli->prepare("SELECT id, token, expires_at, created_at FROM resubmission_requests WHERE operator_id = ? ORDER BY created_at DESC LIMIT 1");
    if ($q) {
        $q->bind_param('i', $id);
        $q->execute();
        $r = $q->get_result();
        if ($r && $r->num_rows) {
            $row = $r->fetch_assoc();
            if (!empty($row['token'])) {
                $token = $row['token'];
            }
        }
        $q->close();
    }
}

if ($token === '') {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'No resubmission token available for this operator. Create one first (create_resubmission.php).']);
    exit;
}

// build absolute resubmission url (same base as create_resubmission.php)
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$dir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$dir = $dir === '/' ? '' : $dir;
$resubmit_url = $scheme . '://' . $host . $dir . '/duplicateoperator.php?token=' . urlencode($token);

// Prepare email content (consolidated)
$body  = "<div style='font-family: Arial, Helvetica, sans-serif; color:#222;'>";
$body .= "<h3 style='color:#c0392b;margin-bottom:6px;'>KYC Document Review — Action Required</h3>";
$body .= "<p>Hi " . htmlspecialchars($opName) . ",</p>";
$body .= "<p>During verification, the following documents/items require correction or re-upload. Please review and re-submit the corrected documents using the link below:</p>";

if ($rejSummary !== '') {
    // Show each rejected line as list item; support newline separated text
    $body .= "<ul style='background:#f8f9fa;padding:12px;border-radius:6px;line-height:1.6;'>";
    $lines = preg_split('/\r\n|\r|\n/', $rejSummary);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $body .= "<li>" . htmlspecialchars($line) . "</li>";
    }
    $body .= "</ul>";
} else {
    $body .= "<p><em>No specific rejection summary found in the record; contact the verifier.</em></p>";
}

$body .= "<p><strong>Re-submission link</strong>:<br><a href='" . htmlspecialchars($resubmit_url) . "'>" . htmlspecialchars($resubmit_url) . "</a></p>";
$body .= "<p>If the link above doesn't open, copy & paste the URL into your browser address bar.</p>";
$body .= "<p style='margin-top:12px;'>If you have any questions, reply to this email or contact our support at <a href='mailto:support@qit.com'>support@qit.com</a>.</p>";
$body .= "<p>Regards,<br/><strong>QIT Verification Team</strong></p>";
$body .= "</div>";

// Compose and send mail via PHPMailer
$smtpDebugOutput = '';

try {
    $mail = new PHPMailer(true);

    if ($SMTP_DEBUG) {
        $mail->SMTPDebug = 3;
        $mail->Debugoutput = function($str, $level) use (&$smtpDebugOutput, $DUMP_TO_LOG) {
            $line = "[".date('c')."] DEBUG[{$level}]: {$str}\n";
            $smtpDebugOutput .= $line;
            if ($DUMP_TO_LOG) error_log($line);
        };
    }

    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;

    // TODO: move credentials to environment or config file
    $mail->Username = 'kelaon73@gmail.com';
    $mail->Password = 'alwkccislfaqsztr';

    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;

    $mail->setFrom('kelaon73@gmail.com', 'QMIT System');
    $mail->addAddress($operator_email, $opName);
    $mail->isHTML(true);
    $mail->Subject = 'KYC documents require re-submission';
    $mail->Body = $body;
    // plaintext fallback: include the summary and URL
    $plain = "Hi {$opName},\n\n";
    $plain .= "During verification, the following items require correction or re-upload:\n\n";
    $plain .= ($rejSummary ? $rejSummary . "\n\n" : "No specific rejection summary provided.\n\n");
    $plain .= "Re-submission link:\n{$resubmit_url}\n\n";
    $plain .= "If you have any questions, contact support@qit.com\n\nRegards,\nQIT Verification Team\n";
    $mail->AltBody = $plain;

    $mail->send();

    $resp = ['success' => true, 'message' => 'Mail sent to operator', 'resubmit_url' => $resubmit_url];
    if ($SMTP_DEBUG) $resp['smtp_debug'] = $smtpDebugOutput;
    echo json_encode($resp);
    exit;
} catch (Exception $e) {
    $err = $e->getMessage();
    error_log('PHPMailer exception: ' . $err);
    $out = ['success' => false, 'message' => 'Mail send failed', 'error' => $err, 'resubmit_url' => $resubmit_url];
    if ($SMTP_DEBUG) $out['smtp_debug'] = $smtpDebugOutput;
    echo json_encode($out);
    exit;
}
