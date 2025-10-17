<?php
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo "<script>alert('Invalid request');window.history.back();</script>";
  exit;
}

$opid = trim($_POST['operator_id'] ?? '');
$code = trim($_POST['unique_code'] ?? '');

$mysqli = new mysqli('localhost', 'root', '', 'qmit_system');
if ($mysqli->connect_errno) {
  error_log('DB connect error: '.$mysqli->connect_error);
  echo "<script>alert('Server error');window.history.back();</script>";
  exit;
}

$stmt = $mysqli->prepare('SELECT operator_id FROM operators WHERE operator_id=? AND unique_code=? LIMIT 1');
$stmt->bind_param('ss', $opid, $code);
$stmt->execute();
$res = $stmt->get_result();

if ($res && $res->num_rows === 1) {
  $_SESSION['operator_id'] = $opid;
  header('Location: operator_view.php');
  exit;
} else {
  echo "<script>alert('Invalid Operator ID or Code');window.history.back();</script>";
  exit;
}
?>
