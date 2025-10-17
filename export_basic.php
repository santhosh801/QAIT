<?php
// export_basic.php
// Simple Excel export (HTML table) of basic operator info
// WARNING: Use appropriate access control in production (auth/CSRF).

// DB connection - update credentials if needed
$mysqli = new mysqli('localhost', 'root', '', 'qmit_system');
if ($mysqli->connect_error) {
    http_response_code(500);
    die('DB connect error: ' . $mysqli->connect_error);
}
$mysqli->set_charset('utf8mb4');

// Which filter? expect all / working / pending / accepted / not working
$filter = isset($_GET['filter']) ? strtolower(trim($_GET['filter'])) : 'all';

// Map filter to WHERE clause
$where = '1'; // default = all
$params = [];
if ($filter === 'working') {
    $where = "work_status = 'working'";
} elseif ($filter === 'not working' || $filter === 'not_working' || $filter === 'not-working') {
    $where = "work_status = 'not working'";
} elseif ($filter === 'pending') {
    $where = "status = 'pending'";
} elseif ($filter === 'accepted') {
    $where = "status = 'accepted'";
} else {
    $where = '1';
}

// Columns to export (textual only)
$cols = [
    'operator_id'            => 'Operator ID',
    'operator_full_name'     => 'Full Name',
    'email'                  => 'Email',
    'operator_contact_no'    => 'Mobile',

    'bank_name'              => 'Bank',
    'branch_name'            => 'Branch',
    'joining_date'           => 'Joining Date',

    'aadhar_number'          => 'Aadhaar',
    'pan_number'             => 'PAN',
    'voter_id_no'            => 'Voter ID',

    'father_name'            => 'Father',
    'alt_contact_relation'   => 'Alt Contact Relation',
    'alt_contact_number'     => 'Alt Contact Number',

    'dob'                    => 'Date of Birth',
    'gender'                 => 'Gender',

    'status'                 => 'Status',
    'work_status'            => 'Work Status'
];


// Build SQL - only select columns above
$colList = implode(", ", array_map(function($c){ return "`$c`"; }, array_keys($cols)));
$sql = "SELECT $colList FROM `operatordoc` WHERE $where ORDER BY id ASC";
$res = $mysqli->query($sql);
if (!$res) {
    http_response_code(500);
    die('Query error: ' . $mysqli->error);
}

// filename
$now = date('Ymd_His');
$filename = "operators_basic_{$filter}_{$now}.xls";

// Send headers for Excel download
header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

// Begin HTML table. Excel will open and render styles.
echo "<html><head><meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\" /></head><body>";
echo "<table border='1' cellpadding='4' cellspacing='0' style='border-collapse:collapse;'>";

// Header row with yellow background
echo "<thead>";
echo "<tr>";
foreach ($cols as $col => $label) {
    // inline style for yellow header; adjust hex as needed
    echo "<th style='background:#FFD54F; color:#000; font-weight:700; text-align:left;'>" . htmlspecialchars($label) . "</th>";
}
echo "</tr>";
echo "</thead>";

// Body rows
echo "<tbody>";
while ($row = $res->fetch_assoc()) {
    echo "<tr>";
    foreach (array_keys($cols) as $col) {
        $val = isset($row[$col]) ? $row[$col] : '';
        // sanitize & preserve spacing/newlines
        $safe = htmlspecialchars($val);
        echo "<td style='mso-number-format:\\@;'>" . $safe . "</td>";
    }
    echo "</tr>";
}
echo "</tbody>";
echo "</table></body></html>";

// cleanup
$res->free();
$mysqli->close();
exit;
