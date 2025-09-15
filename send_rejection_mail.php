<?php
// send_rejection_mail.php
// Sends the rejection + resubmission link to an operator using PHPMailer (Gmail SMTP).
// Expects POST: id (operator id) and optional token (resubmission token).
// Requires composer autoload (PHPMailer).

// --- hardening: quiet stray output and return clean JSON ---
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED);
ob_start();
header('Content-Type: application/json; charset=utf-8');

session_start();

require __DIR__ . '/vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ---------------- CONFIG (for quick testing these are in-file; move to env for prod) ----------------
$smtpHost = 'smtp.gmail.com';
$smtpUser = 'kelaon73@gmail.com';                // <-- your Gmail
$smtpPass = 'alwkccislfaqsztr';                  // <-- your Gmail App Password (16 chars)
$smtpPort = 587;
$smtpSecure = 'tls';
$fromEmail = $smtpUser;                          // use authenticated account as From to avoid Gmail issues
$fromName  = 'QIT Verification Team';
$replyTo   = 'support@qit.com';

// debug / storage dirs
$logDir = __DIR__ . '/logs';
$outDir = __DIR__ . '/outgoing_emails';
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
if (!is_dir($outDir)) @mkdir($outDir, 0755, true);

// helper
$esc = function($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
function json_exit($arr) {
    // clear any buffered output to avoid invalid JSON
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

// require POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_exit(['success'=>false,'message'=>'Method not allowed']);
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$token = isset($_POST['token']) ? trim((string)$_POST['token']) : '';
if ($id <= 0) json_exit(['success'=>false,'message'=>'Missing operator id']);

// DB connect
$mysqli = new mysqli('localhost','root','','qmit_system');
if ($mysqli->connect_error) json_exit(['success'=>false,'message'=>'DB connection failed'] );
$mysqli->set_charset('utf8mb4');

// fetch operator
$stmt = $mysqli->prepare("SELECT id, operator_full_name, operator_id, operator_contact_no, email, rejection_summary FROM operatordoc WHERE id = ? LIMIT 1");
if (!$stmt) json_exit(['success'=>false,'message'=>'DB prepare failed (operator)']);
$stmt->bind_param('i', $id);
$stmt->execute();
$res = $stmt->get_result();
$op = $res->fetch_assoc();
$stmt->close();
if (!$op) json_exit(['success'=>false,'message'=>'Operator not found']);

$operator_email = trim($op['email'] ?? '');
$opName = trim($op['operator_full_name'] ?: $op['operator_id'] ?: 'Operator');
$rejSummary = trim($op['rejection_summary'] ?? '');
if ($operator_email === '' || !filter_var($operator_email, FILTER_VALIDATE_EMAIL)) {
    json_exit(['success'=>false,'message'=>'Invalid operator email']);
}

// resolve token (if not provided, use latest for operator)
if ($token === '') {
    $q = $mysqli->prepare("SELECT id, token FROM resubmission_requests WHERE operator_id = ? ORDER BY created_at DESC LIMIT 1");
    if ($q) {
        $q->bind_param('i', $id);
        $q->execute();
        $r = $q->get_result();
        if ($r && $r->num_rows) {
            $rr = $r->fetch_assoc();
            $token = $rr['token'];
        }
        $q->close();
    }
}
if ($token === '') json_exit(['success'=>false,'message'=>'No resubmission token found for operator']);

// build resubmission URL
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
$dir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$dir = $dir === '/' ? '' : $dir;
$resubmit_url = $scheme . '://' . $host . $dir . '/duplicateoperator.php?token=' . urlencode($token);

// fetch docs_json from resubmission_requests (prefer this)
$docsList = [];
$p = $mysqli->prepare("SELECT docs_json FROM resubmission_requests WHERE token = ? LIMIT 1");
if ($p) {
    $p->bind_param('s', $token);
    $p->execute();
    $rr = $p->get_result();
    if ($rr && $rr->num_rows) {
        $rrow = $rr->fetch_assoc();
        $docsList = json_decode($rrow['docs_json'] ?? '[]', true);
        if (!is_array($docsList)) $docsList = [];
    }
    $p->close();
}

// fallback to rejection_summary lines if docsList empty
if (empty($docsList) && !empty($rejSummary)) {
    $docsList = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/',$rejSummary)));
}

// friendly label map
$label_map = [
  'aadhar_file'=>'Aadhaar Card','pan_file'=>'PAN Card','voter_file'=>'Voter ID',
  'ration_file'=>'Ration Card','consent_file'=>'Consent','gps_selfie_file'=>'GPS Selfie',
  'police_verification_file'=>'Police Verification','permanent_address_proof_file'=>'Permanent Address Proof',
  'parent_aadhar_file'=>"Parent's Aadhaar",'nseit_cert_file'=>'NSEIT Certificate',
  'self_declaration_file'=>'Self Declaration','non_disclosure_file'=>'Non-Disclosure Agreement',
  'edu_10th_file'=>'10th Certificate','edu_12th_file'=>'12th Certificate','edu_college_file'=>'College Certificate',
  'agreement_file'=>'Agreement','bank_passbook_file'=>'Bank Passbook','photo_file'=>'Photo'
];

// build HTML body
$bodyHtml  = '<div style="font-family:Arial,Helvetica,sans-serif;color:#222;">';
$bodyHtml .= '<h3 style="color:#c0392b;margin-bottom:6px;">KYC Document Review — Action Required</h3>';
$bodyHtml .= '<p>Hi ' . $esc($opName) . ',</p>';
$bodyHtml .= '<p>During verification the following documents require correction or re-upload. Use the link below to re-submit the corrected documents:</p>';

if (!empty($docsList)) {
    $bodyHtml .= '<ul style="background:#f8fafc;padding:12px;border-radius:6px;line-height:1.6;">';
    foreach ($docsList as $dk) {
        $label = $label_map[$dk] ?? $dk;
        $bodyHtml .= '<li>' . $esc($label) . '</li>';
    }
    $bodyHtml .= '</ul>';
} else {
    $bodyHtml .= '<p><em>No specific documents listed; contact the verifier.</em></p>';
}

$bodyHtml .= '<p><strong>Re-submission link:</strong><br><a href="' . $esc($resubmit_url) . '">' . $esc($resubmit_url) . '</a></p>';
$bodyHtml .= '<p>If you have trouble uploading files, reply to this email or contact <a href="mailto:support@qit.com">support@qit.com</a>.</p>';
$bodyHtml .= '<p>Regards,<br/><strong>QIT Verification Team</strong></p>';
$bodyHtml .= '</div>';

// plain text fallback
$plain = "Hi {$opName},\n\n";
$plain .= "During verification the following items require correction or re-upload:\n\n";
if (!empty($docsList)) {
    foreach ($docsList as $dk) {
        $plain .= "- " . ($label_map[$dk] ?? $dk) . "\n";
    }
} else {
    $plain .= "(no specific documents listed)\n";
}
$plain .= "\nRe-submission link:\n{$resubmit_url}\n\nIf you have questions, contact support@qit.com\n\nRegards,\nQIT Verification Team\n";

// --- PHPMailer send with debug capture ---
$smtpDebugOutput = '';
try {
    $mail = new PHPMailer(true);

    // capture debug
    $mail->SMTPDebug = 3; // verbose; set to 0 after debugging
    $mail->Debugoutput = function($str, $level) use (&$smtpDebugOutput) {
        $smtpDebugOutput .= "[".date('c')."][DBG{$level}] " . rtrim($str) . PHP_EOL;
    };

    $mail->isSMTP();
    $mail->Host = $smtpHost;
    $mail->SMTPAuth = true;
    $mail->Username = $smtpUser;
    $mail->Password = $smtpPass;
    // TLS
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = $smtpPort;

    // use authenticated Gmail as From to avoid Gmail send-as rejection
    $mail->setFrom($fromEmail, $fromName);
    $mail->addAddress($operator_email, $opName);
    $mail->addReplyTo($replyTo, 'QIT Support');

    $mail->isHTML(true);
    $mail->Subject = 'KYC documents require re-submission';
    $mail->Body = $bodyHtml;
    $mail->AltBody = $plain;

    $mail->send();

    // write debug file if any debug output captured
    $debugFile = '';
    if ($smtpDebugOutput !== '') {
        $debugFile = $logDir . '/mail_debug_success_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)),0,8) . '.log';
        @file_put_contents($debugFile, $smtpDebugOutput);
    }

    json_exit(['success'=>true,'message'=>'Mail sent','resubmit_url'=>$resubmit_url,'debug_log'=>$debugFile]);

} catch (Exception $e) {
    $err = $mail->ErrorInfo ?? $e->getMessage();
    $debugFile = $logDir . '/mail_debug_fail_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)),0,8) . '.log';
    @file_put_contents($debugFile, "Exception: {$err}\n\n{$smtpDebugOutput}");

    // fallback: save .eml for manual forwarding
    $emlPath = $outDir . '/rejection_email_' . date('Ymd_His') . '_' . rand(1000,9999) . '.eml';
    $raw = "From: {$fromName} <{$fromEmail}>\r\n";
    $raw .= "To: {$operator_email}\r\n";
    $raw .= "Subject: KYC documents require re-submission\r\n";
    $raw .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
    $raw .= $bodyHtml;
    @file_put_contents($emlPath, $raw);

    error_log("send_rejection_mail failed: {$err}; debug log: {$debugFile}");
    json_exit(['success'=>false,'message'=>'Mail send failed','error'=>$err,'debug_log'=>$debugFile,'eml'=>$emlPath,'resubmit_url'=>$resubmit_url]);
}
