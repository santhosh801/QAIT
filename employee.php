<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require __DIR__ . '/vendor/autoload.php';

if($_SERVER['REQUEST_METHOD'] !== 'POST'){ 
    http_response_code(403); 
    exit('Invalid request'); 
}

$employee_name = trim($_POST['employee_name']);
$employee_email = trim($_POST['employee_email']);
$operator_email = trim($_POST['operator_email']);
$aadhaar_id = trim($_POST['aadhaar_id']);
$unique_id = trim($_POST['unique_id']);
$mobile_number = trim($_POST['mobile_number']);

if(!$employee_name || !$employee_email || !$operator_email){ 
    http_response_code(400); 
    exit('Required fields missing'); 
}

// Generate unique token
$token = bin2hex(random_bytes(12));

// Save to MySQL
$mysqli = new mysqli('localhost','root','','qmit_system');
if($mysqli->connect_error){ 
    exit('DB Connection Failed'); 
}

$stmt = $mysqli->prepare('INSERT INTO employees (employee_name, employee_email, operator_email, aadhaar_id, unique_id, mobile_number, token) VALUES (?, ?, ?, ?, ?, ?, ?)');
$stmt->bind_param('sssssss', $employee_name, $employee_email, $operator_email, $aadhaar_id, $unique_id, $mobile_number, $token);
$stmt->execute();
$stmt->close();
$mysqli->close();

// Send email to operator
$operator_link = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/operator_view.php?token=$token";

$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'kelaon73@gmail.com';
    $mail->Password = 'alwkccislfaqsztr';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;

    $mail->setFrom('kelaon73@gmail.com', 'QMIT System');
    $mail->addAddress($operator_email);

    $mail->isHTML(true);
    $mail->Subject = 'New Employee Submission';
    $mail->Body = "<h3>New Employee Submission</h3>
                   <p>Name: $employee_name<br>Email: $employee_email<br>Aadhaar: $aadhaar_id<br>Unique ID: $unique_id<br>Mobile: $mobile_number</p>
                   <p><a href='$operator_link'>View Details</a></p>";

    $mail->send();
} catch (Exception $e){ 
    error_log($mail->ErrorInfo); 
}

echo '<script>alert("Details sent to operator successfully!");window.location="em_verfi.php";</script>';
?>
