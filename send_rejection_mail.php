<?php
// send_rejection_mail_debug.php  -- TEMPORARY debug endpoint
session_start();
require __DIR__ . '/vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json; charset=utf-8');

// DEBUG FLAGS
$SMTP_DEBUG = true;   // set true to capture SMTP conversation (temporarily)
$DUMP_TO_LOG = true;  // also write SMTP debug to server error_log

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if (!$id) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Missing id']); exit; }

$mysqli = new mysqli('localhost','root','','qmit_system');
if ($mysqli->connect_error) { error_log('DB err: '.$mysqli->connect_error); echo json_encode(['success'=>false,'message'=>'DB error']); exit; }

$stmt = $mysqli->prepare("SELECT operator_full_name, operator_id, operator_contact_no, email AS operator_email, rejection_summary FROM operatordoc WHERE id = ? LIMIT 1");
$stmt->bind_param('i',$id);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$stmt->close();

if (!$row) { echo json_encode(['success'=>false,'message'=>'Operator not found']); exit; }
if (empty(trim($row['rejection_summary'] ?? ''))) { echo json_encode(['success'=>false,'message'=>'No rejected docs to mail']); exit; }

$operator_email = trim($row['operator_email'] ?? '');
if (!filter_var($operator_email, FILTER_VALIDATE_EMAIL)) { echo json_encode(['success'=>false,'message'=>'Operator email invalid or missing']); exit; }

$opName = $row['operator_full_name'] ?: $row['operator_id'];

$body  = "<h3>Documents rejected — action required</h3>";
$body .= "<p>Hi " . htmlspecialchars($opName) . ",</p>";
$body .= "<pre style='white-space:pre-wrap;'>" . htmlspecialchars($row['rejection_summary']) . "</pre>";
$body .= "<p>Regards,<br/>Ops Team</p>";

// Collect SMTP debug here
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

    // your creds (move to env later)
    $mail->Username = 'kelaon73@gmail.com';
    $mail->Password = 'alwkccislfaqsztr';

    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;

    $mail->setFrom('kelaon73@gmail.com', 'QMIT System');
    $mail->addAddress($operator_email, $opName);
    $mail->isHTML(true);
    $mail->Subject = 'Docs rejected — please re-upload';
    $mail->Body = $body;
    $mail->AltBody = strip_tags($row['rejection_summary']);

    $mail->send();

    echo json_encode(['success'=>true,'message'=>'Mail sent','smtp_debug' => $SMTP_DEBUG ? $smtpDebugOutput : null]);
    exit;
} catch (Exception $e) {
    $err = $e->getMessage();
    error_log('PHPMailer exception: '.$err);
    // return both the PHPMailer exception and the SMTP debug trace, if available
    echo json_encode([
        'success' => false,
        'message' => 'Mail send failed (see error & smtp_debug)',
        'error' => $err,
        'smtp_debug' => $smtpDebugOutput
    ]);
    exit;
}
