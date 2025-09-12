<?php
// employee_mailing_handler.php
session_start();

require __DIR__ . '/vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    exit('Invalid request');
}

// sanitize + trim inputs
$employee_name   = trim($_POST['employee_name'] ?? '');
$employee_email  = trim($_POST['employee_email'] ?? '');
$operator_email  = trim($_POST['operator_email'] ?? '');
$aadhaar_id      = trim($_POST['aadhaar_id'] ?? '');
$unique_id       = trim($_POST['unique_id'] ?? '');
$mobile_number   = trim($_POST['mobile_number'] ?? '');

// basic validation
if (!$employee_name || !filter_var($employee_email, FILTER_VALIDATE_EMAIL) || !filter_var($operator_email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['mail_error'] = 'Missing or invalid fields';
    header('Location: em_verfi.php');
    exit;
}

// generate token
$token = bin2hex(random_bytes(12));

// Save to DB
$mysqli = new mysqli('localhost', 'root', '', 'qmit_system');
if ($mysqli->connect_error) {
    $_SESSION['mail_error'] = 'DB error';
    header('Location: em_verfi.php');
    exit;
}
$stmt = $mysqli->prepare('INSERT INTO employees (employee_name, employee_email, operator_email, aadhaar_id, unique_id, mobile_number, token) VALUES (?, ?, ?, ?, ?, ?, ?)');
if (!$stmt) {
    $_SESSION['mail_error'] = 'DB prepare failed';
    header('Location: em_verfi.php');
    exit;
}
$stmt->bind_param('sssssss', $employee_name, $employee_email, $operator_email, $aadhaar_id, $unique_id, $mobile_number, $token);
$stmt->execute();
$stmt->close();
$mysqli->close();

// Build operator link
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$operator_link = $protocol . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . "/operator_view.php?token=" . urlencode($token);

// Escape for HTML
$esc = function($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };

// Compose operator-facing HTML body (responsive, clear, form-fill call-to-action)
$bodyHtml = <<<HTML
<div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,'Helvetica Neue',Arial; color:#111; line-height:1.45; max-width:720px;">
  <div style="padding:18px;border-radius:10px;background:#ffffff;border:1px solid #e6e9ee;">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:10px;">
     
      <div>
        <div style="font-size:18px;font-weight:800;color:#0f172a">Action required: Complete operator documents</div>
        <div style="font-size:13px;color:#6b7280;margin-top:4px">An employee has submitted their details — please complete the operator-side form below.</div>
      </div>
    </div>

    <h3 style="margin:6px 0 10px 0;font-size:15px;color:#0f172a">Submission summary</h3>
    <table cellpadding="6" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:14px;">
      <tr><td style="width:170px;color:#6b7280;font-weight:700">Employee name</td><td>{$esc($employee_name)}</td></tr>
      <tr><td style="color:#6b7280;font-weight:700">Employee email</td><td>{$esc($employee_email)}</td></tr>
      <tr><td style="color:#6b7280;font-weight:700">Aadhaar ID</td><td>{$esc($aadhaar_id)}</td></tr>
      <tr><td style="color:#6b7280;font-weight:700">Employee ID</td><td>{$esc($unique_id)}</td></tr>
      <tr><td style="color:#6b7280;font-weight:700">Mobile</td><td>{$esc($mobile_number)}</td></tr>
    </table>

    <div style="margin-top:16px;">
      <strong style="display:block;margin-bottom:8px; font-size:13px">What we need from you</strong>
      <p style="margin:0 0 12px 0;color:#111;font-size:8px">
        Please open the link below and complete the operator-side document form - OPERATOR only need to fill specific fields, attach required docs, and submit.
      </p>

      <a href="{$esc($operator_link)}" style="display:inline-block;padding:14px 20px;border-radius:12px;background:#0ea5e9;color:#fff;text-decoration:none;font-weight:800;font-size:16px;box-shadow:0 6px 18px rgba(14,165,233,0.18)">Complete operator form</a>
    </div>

    <div style="margin-top:18px;color:#374151;font-size:13px">
      <p style="margin:0 0 8px">If you have questions or need assistance, reply to this email or contact: <a href="mailto:support@qit.com">support@qit.com</a></p>
      <p style="margin:0">Thanks,<br><strong>QIT Verification Team</strong></p>
    </div>

    <div style="margin-top:12px;font-size:11px;color:#9ca3af">Please do not share the link publicly. The link grants access to the submission for completion only.</div>
  </div>
</div>
HTML;

// Plain text fallback
$altBody = "Action required: Complete operator documents\n\n"
         . "Employee: " . $employee_name . "\n"
         . "Employee Email: " . $employee_email . "\n"
         . "Aadhaar: " . $aadhaar_id . "\n"
         . "Employee ID: " . $unique_id . "\n"
         . "Mobile: " . $mobile_number . "\n\n"
         . "Open and complete the operator form: " . $operator_link . "\n\n"
         . "Need help? support@qit.com\n";

// === PHPMailer send ===
// NOTE: put real SMTP creds in environment variables (SMTP_HOST, SMTP_USER, SMTP_PASS, SMTP_PORT, SMTP_SECURE)
$smtpHost = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
$smtpUser = getenv('SMTP_USER') ?: 'kelaon73@gmail.com';
$smtpPass = getenv('SMTP_PASS') ?: 'alwkccislfaqsztr';
$smtpPort = getenv('SMTP_PORT') ?: 587;
$smtpSecure = getenv('SMTP_SECURE') ?: 'tls';

try {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = $smtpHost;
    $mail->SMTPAuth   = true;
    $mail->Username   = $smtpUser;
    $mail->Password   = $smtpPass;
    if (strtolower($smtpSecure) === 'ssl') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    } else {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    }
    $mail->Port       = (int)$smtpPort;

    $mail->setFrom($smtpUser, 'QMIT System');
    $mail->addAddress($operator_email);
    $mail->addReplyTo('support@qit.com', 'QIT Support');

    $mail->isHTML(true);
    $mail->Subject = 'Operator action required — complete documents';
    $mail->Body    = $bodyHtml;
    $mail->AltBody = $altBody;

    $mail->send();
    $_SESSION['mail_success'] = 'Details sent to operator!';
} catch (Exception $e) {
    error_log('Mailer Error: ' . $e->getMessage());
    $_SESSION['mail_error'] = 'Mail send failed';
}

header('Location: em_verfi.php');
exit;
