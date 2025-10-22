<?php
// employee.php (hardened + fixed prepare checks)
session_start();

require __DIR__ . '/vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Block non-POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    exit('Invalid request');
}

// sanitize + trim
$employee_name   = trim($_POST['employee_name'] ?? '');
$employee_email  = trim($_POST['employee_email'] ?? '');
$operator_email  = trim($_POST['operator_email'] ?? '');
$operator_id     = trim($_POST['operator_id'] ?? '');
$aadhaar_id      = trim($_POST['aadhaar_id'] ?? '');
$unique_id       = trim($_POST['unique_id'] ?? '');
$mobile_number   = trim($_POST['mobile_number'] ?? '');

// basic validation
if (!$employee_name || !filter_var($employee_email, FILTER_VALIDATE_EMAIL) || !filter_var($operator_email, FILTER_VALIDATE_EMAIL) || !$operator_id) {
    $_SESSION['mail_error'] = 'Missing or invalid fields (employee/operator email and operator id required).';
    header('Location: em_verfi.php');
    exit;
}

// DB connect (with error reporting)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $mysqli = new mysqli('localhost', 'root', '', 'qmit_system');
    $mysqli->set_charset('utf8mb4');
} catch (Throwable $e) {
    error_log("DB connect error: " . $e->getMessage());
    $_SESSION['mail_error'] = 'DB connection failed.';
    header('Location: em_verfi.php');
    exit;
}

$token = bin2hex(random_bytes(12));
$operator_code = bin2hex(random_bytes(5)); // 10 hex chars

// Begin transaction
$mysqli->begin_transaction();
try {
    // ensure employees table columns exist — prepare may still fail if schema differs
    $insertSql = 'INSERT INTO employees (employee_name, employee_email, operator_email, operator_id, aadhaar_id, unique_id, mobile_number, token)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?)';
    $stmt = $mysqli->prepare($insertSql);
    if ($stmt === false) {
        throw new Exception('Prepare failed for employees insert: ' . $mysqli->error);
    }
    $stmt->bind_param('ssssssss', $employee_name, $employee_email, $operator_email, $operator_id, $aadhaar_id, $unique_id, $mobile_number, $token);
    $stmt->execute();
    $stmt->close();

    // ensure operators table exists (safe to run)
    $createOperatorsSql = "
        CREATE TABLE IF NOT EXISTS operators (
            id INT AUTO_INCREMENT PRIMARY KEY,
            operator_id VARCHAR(191) NOT NULL UNIQUE,
            unique_code VARCHAR(191) NOT NULL,
            operator_email VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    $mysqli->query($createOperatorsSql);

    // Try update first
    $upd = $mysqli->prepare('UPDATE operators SET unique_code = ?, operator_email = ? WHERE operator_id = ?');
    if ($upd === false) {
        throw new Exception('Prepare failed for operators update: ' . $mysqli->error);
    }
    $upd->bind_param('sss', $operator_code, $operator_email, $operator_id);
    $upd->execute();
    $affected = $upd->affected_rows;
    $upd->close();

    if ($affected === 0) {
        $ins = $mysqli->prepare('INSERT INTO operators (operator_id, unique_code, operator_email) VALUES (?, ?, ?)');
        if ($ins === false) {
            throw new Exception('Prepare failed for operators insert: ' . $mysqli->error);
        }
        $ins->bind_param('sss', $operator_id, $operator_code, $operator_email);
        $ins->execute();
        $ins->close();
    }

    $mysqli->commit();
} catch (Throwable $e) {
    $mysqli->rollback();
    error_log('DB Error in employee.php: ' . $e->getMessage());
    // give a friendly message to user, log the real message
    $_SESSION['mail_error'] = 'Database error. Check server log for details.';
    header('Location: em_verfi.php');
    exit;
}

$mysqli->close();

// Build operator link
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$operator_link = $protocol . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . "/op_login.html";


// Prepare email HTML and alt body (kept short for clarity)
// prepare an escaper and escaped values
$esc = function($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
$operator_id_esc    = $esc($operator_id);
$operator_code_esc  = $esc($operator_code);
$employee_name_esc  = $esc($employee_name);
$operator_link_esc  = $esc($operator_link);

// HTML email (inline CSS for best compatibility with email clients)
$bodyHtml = <<<HTML
<!doctype html>
<html>
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
  </head>
  <body style="margin:0;padding:24px;font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#f4f6f9;">
    <!-- Preheader (visible in inbox preview) -->
    <span style="display:none;max-height:0;overflow:hidden;visibility:hidden;">Please login with Operator ID & Unique Code to complete your documents.</span>

    <table width="100%" cellpadding="0" cellspacing="0" role="presentation">
      <tr>
        <td align="center">
          <table width="720" cellpadding="0" cellspacing="0" role="presentation" style="max-width:720px;">
            <tr>
              <td style="padding:18px 18px 6px 18px;">
                <div style="background:#ffffff;border-radius:12px;border:1px solid #e6e9ee;padding:22px;color:#0f172a;box-shadow:0 10px 30px rgba(2,6,23,0.06);">
                  
                  <!-- Header -->
                  <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
                    <div style="flex:0 0 64px;height:64px;border-radius:10px;background:linear-gradient(180deg,#f7fafc,#fff);display:flex;align-items:center;justify-content:center;border:1px solid #eef2ff;">
                      <strong style="font-size:20px;color:#bd9a68;font-family:'Vidaloka',serif;">QIT</strong>
                    </div>
                    <div>
                      <div style="font-size:18px;font-weight:800;color:#0f172a">Action required: Complete operator documents</div>
                      <div style="font-size:13px;color:#6b7280;margin-top:4px">Please login and finish your section of the verification form.</div>
                    </div>
                  </div>

                  <!-- Employee note -->
                  <div style="padding:12px;border-radius:8px;border:1px solid #000000ff;margin-bottom:14px;font-size:13px;color:#000000;">
                    Operator name : <strong>{$employee_name_esc}</strong>
                  </div>

                  <!-- Details box -->
                  <div style="display:flex;gap:18px;align-items:center;flex-wrap:wrap;">
                    <div style="flex:1;min-width:220px;">
                      <div style="font-size:13px;color:#6b7280;margin-bottom:6px;font-weight:700">Operator ID</div>
                      <div style="padding:10px;border-radius:8px;background:#f8fafc;border:1px solid #eef2ff;font-weight:700;">{$operator_id_esc}</div>
                    </div>

                    <div style="flex:1;min-width:220px;">
                      <div style="font-size:13px;color:#6b7280;margin-bottom:6px;font-weight:700">Unique Code</div>
                      <div style="padding:10px;border-radius:8px;background:#f1f5f9;border:1px solid #e6eef6;font-weight:700;">{$operator_code_esc}</div>
                    </div>

                    <div style="flex-basis:100%;height:6px"></div>

                    <!-- Call-to-action button -->
                    <div style="width:100%;text-align:left;margin-top:6px;">
                      <a href="{$operator_link_esc}" 
                         style="display:inline-block;padding:14px 22px;border-radius:10px;background:#0ea5e9;color:#ffffff;text-decoration:none;font-weight:800;box-shadow:0 8px 20px rgba(14,165,233,0.18);margin-top:4px;">
                        Login & Complete Form
                      </a>
                    </div>
                  </div>

                  <!-- Helper text -->
                  <div style="margin-top:16px;font-size:13px;color:#374151;line-height:1.45;">
                    <p style="margin:0 0 6px;">Important: copy both <strong>Operator ID</strong> and <strong>Unique Code</strong> exactly when you log in at the link above.</p>
                    <p style="margin:0;">If you can't access the link, reply to this email or contact <a href="mailto:support@qit.com">support@qit.com</a>.</p>
                  </div>

                  <!-- Footer small -->
                  <div style="margin-top:14px;font-size:11px;color:#9ca3af;">
                    This message was generated by QIT Verification. Do not share the link publicly — it is intended for the operator only.
                  </div>
                </div>
              </td>
            </tr>
            <tr>
              <td align="center" style="padding:12px 18px;color:#9ca3af;font-size:12px;">
                QIT • Verification Team
              </td>
            </tr>
          </table>
        </td>
      </tr>
    </table>
  </body>
</html>
HTML;

// Plain-text fallback (for clients that don't render HTML)
$altBody = "Action required: Complete operator documents\n\n"
         . "Submitted by: " . $employee_name . "\n"
         . "Operator ID: " . $operator_id . "\n"
         . "Unique Code: " . $operator_code . "\n\n"
         . "Open and login here: " . $operator_link . "\n\n"
         . "If you need help reply to this email: support@qit.com\n";

// PHPMailer send (use env vars or change below)
$smtpHost = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
$smtpUser = getenv('SMTP_USER') ?: 'kelaon73@gmail.com';
$smtpPass = getenv('SMTP_PASS') ?: 'alwkccislfaqsztr'; // replace or use env
$smtpPort = getenv('SMTP_PORT') ?: 587;
$smtpSecure = getenv('SMTP_SECURE') ?: 'tls';

try {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = $smtpHost;
    $mail->SMTPAuth = true;
    $mail->Username = $smtpUser;
    $mail->Password = $smtpPass;
    $mail->SMTPSecure = ($smtpPort == 465 || strtolower($smtpSecure) === 'ssl') ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = (int)$smtpPort;

    // dev debug - set to 0 for production
    $mail->SMTPDebug = 0;
    $mail->Debugoutput = function($str, $level) { error_log("PHPMailer: $str"); };

    $mail->setFrom($smtpUser, 'Team QIT');
    $mail->addAddress($operator_email);

    $mail->isHTML(true);
    $mail->Subject = 'Operator action required complete documents';
    $mail->Body = $bodyHtml;
    $mail->AltBody = $altBody;

    $mail->send();
    $_SESSION['mail_success'] = 'Details sent to operator.';
} catch (Throwable $e) {
    error_log('Mailer exception: ' . $e->getMessage());
    $_SESSION['mail_error'] = 'Mail sending failed. Check SMTP settings or server log.';
}

header('Location: em_verfi.php');
exit;
