<?php
// employee_login is used to send the login request page for em_verfi  dontdelete .php
session_start();

$mysqli = new mysqli('localhost','root','','qmit_system');
if($mysqli->connect_error){
    exit('DB Connection Failed: '.$mysqli->connect_error);
}

if($_SERVER["REQUEST_METHOD"] == "POST"){
    $email = $_POST['employee_email'] ?? '';




    $password = $_POST['employee_password'] ?? '';

    // Check if employee exists
    $stmt = $mysqli->prepare("SELECT * FROM employeelog WHERE employee_email = ? AND employee_password = ?");
    $stmt->bind_param("ss", $email, $password);
    $stmt->execute();
    $result = $stmt->get_result();

    if($result->num_rows > 0){
        // Save session
        $_SESSION['employee_email'] = $email;
        header("Location: em_verfi.php");
        exit();
    } else {
        echo "<script>alert('Invalid login credentials'); window.location.href='em_login.html';</script>";
    }

    $stmt->close();
}
$mysqli->close();
?>
