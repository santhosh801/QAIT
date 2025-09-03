<?php
$mysqli = new mysqli("localhost", "root", "", "qmit_system");
if ($mysqli->connect_error) {
    die(json_encode(["success" => false, "message" => "DB Connection failed"]));
}

$id = intval($_POST['id']);
$status = $_POST['status'];

if (!in_array($status, ['accepted','pending','rejected'])) {
    echo json_encode(["success" => false, "message" => "Invalid status"]);
    exit;
}

$mysqli->query("UPDATE operatordoc SET status='$status' WHERE id=$id");

if ($mysqli->affected_rows > 0) {
    echo json_encode(["success" => true, "message" => "Status updated to $status"]);
} else {
    echo json_encode(["success" => false, "message" => "No changes made"]);
}
?>
