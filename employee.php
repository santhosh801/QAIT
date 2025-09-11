<?php
// employee_mailing_handler.php this is route file from the em_verfi.php
session_start();
require __DIR__ . '/vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if($_SERVER['REQUEST_METHOD'] !== 'POST'){ http_response_code(403); exit('Invalid request'); }

$employee_name = trim($_POST['employee_name'] ?? '');
$employee_email = trim($_POST['employee_email'] ?? '');
$operator_email = trim($_POST['operator_email'] ?? '');
$aadhaar_id = trim($_POST['aadhaar_id'] ?? '');
$unique_id = trim($_POST['unique_id'] ?? '');
$mobile_number = trim($_POST['mobile_number'] ?? '');

if (!$employee_name || !filter_var($employee_email, FILTER_VALIDATE_EMAIL) || !filter_var($operator_email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['mail_error'] = 'Missing or invalid fields';
    header('Location: em_verfi.php'); exit;
}

// generate token
$token = bin2hex(random_bytes(12));

// Save to DB
$mysqli = new mysqli('localhost','root','','qmit_system');
if ($mysqli->connect_error) { $_SESSION['mail_error']='DB error'; header('Location: em_verfi.php'); exit; }
$stmt = $mysqli->prepare('INSERT INTO employees (employee_name, employee_email, operator_email, aadhaar_id, unique_id, mobile_number, token) VALUES (?, ?, ?, ?, ?, ?, ?)');
$stmt->bind_param('sssssss', $employee_name, $employee_email, $operator_email, $aadhaar_id, $unique_id, $mobile_number, $token);
$stmt->execute();
$stmt->close();
$mysqli->close();

$operator_link = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/operator_view.php?token=$token";

try {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'kelaon73@gmail.com'; // move to env
    $mail->Password = 'alwkccislfaqsztr';    // move to env
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;

    $mail->setFrom('kelaon73@gmail.com', 'QMIT System');
    $mail->addAddress($operator_email);
    $mail->isHTML(true);
    $mail->Subject = 'New Employee Submission';
    $mail->Body = "<h3>New Employee Submission</h3>
                   <p>Name: $employee_name<br>Email: $employee_email<br>Aadhaar: $aadhaar_id<br>EMPLOYEE ID: $unique_id<br>Mobile: $mobile_number</p>
                   <p><a href='$operator_link'>View Details</a></p>";
    $mail->send();
    $_SESSION['mail_success'] = 'Details sent to operator!';
} catch (Exception $e) {
    error_log('Mailer Error: ' . $e->getMessage());
    $_SESSION['mail_error'] = 'Mail send failed';
}
header('Location: em_verfi.php');
exit;
