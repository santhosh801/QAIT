<?php
$mysqli = new mysqli('localhost','root','','qmit_system');
if($mysqli->connect_error){
    exit('DB Connection Failed: '.$mysqli->connect_error);
}

if($_SERVER["REQUEST_METHOD"] == "POST"){
    $email = $_POST['employee_email'] ?? '';
    $password = $_POST['employee_password'] ?? '';

    $stmt = $mysqli->prepare("INSERT INTO employeelog (employee_email, employee_password) VALUES (?, ?)");
    $stmt->bind_param("ss", $email, $password);

    if($stmt->execute()){
        header("Location: em_verfi.html");
        exit();
    } else {
        echo "Error: " . $stmt->error;
    }

    $stmt->close();
}
$mysqli->close();
?>
