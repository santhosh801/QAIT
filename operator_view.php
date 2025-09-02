<?php
// Only handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Required fields check
    $required = ['branch_name', 'joining_date', 'operator_id', 'operator_full_name', 'email'];
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            die("Error: '$field' is required!");
        }
    }

    // Collect POST data safely (only columns that exist in your table!)
   $data = [
    'branch_name' => $_POST['branch_name'] ?? '',
    'joining_date' => $_POST['joining_date'] ?? '',
    'operator_id' => $_POST['operator_id'] ?? '',
    'operator_full_name' => $_POST['operator_full_name'] ?? '',
    'father_name' => $_POST['father_name'] ?? '',
    'nseit_number' => $_POST['nseit_number'] ?? '',
    'nseit_date' => $_POST['nseit_date'] ?? '',
    'gender' => $_POST['gender'] ?? '',
    'dob' => $_POST['dob'] ?? '',
    'operator_contact_no' => $_POST['operator_contact_no'] ?? '',
    'email' => $_POST['email'] ?? '',
    'aadhar_number' => $_POST['aadhar_number'] ?? '',
    'pan_number' => $_POST['pan_number'] ?? '',
    'voter_id_no' => $_POST['voter_id_no'] ?? '',
    'ration_card' => $_POST['ration_card'] ?? '',

    // ✅ Add Current Address
    'current_hno_street'   => $_POST['current_hno_street'] ?? '',
    'current_village_town' => $_POST['current_village_town'] ?? '',
    'current_pincode'      => $_POST['current_pincode'] ?? '',
    'current_postoffice'   => $_POST['current_postoffice'] ?? '',
    'current_district'     => $_POST['current_district'] ?? '',
    'current_state'        => $_POST['current_state'] ?? '',

    // ✅ Add Permanent Address
    'permanent_hno_street'   => $_POST['permanent_hno_street'] ?? '',
    'permanent_village_town' => $_POST['permanent_village_town'] ?? '',
    'permanent_pincode'      => $_POST['permanent_pincode'] ?? '',
    'permanent_postoffice'   => $_POST['permanent_postoffice'] ?? '',
    'permanent_district'     => $_POST['permanent_district'] ?? '',
    'permanent_state'        => $_POST['permanent_state'] ?? ''
];


    // ==== Handle File Uploads ====
    $uploadFields = [
        'aadhar_file',
        'pan_file',
        'voter_file',
        'ration_file',
        'consent_file',
        'gps_selfie_file',
        'permanent_address_proof_file',
        'nseit_cert_file',
        'self_declaration_file',
        'non_disclosure_file',
        'police_verification_file',
        'parent_aadhar_file',
        'edu_10th_file',
        'edu_12th_file',
        'edu_college_file'
    ];

    $uploadDir = __DIR__ . "/uploads/";

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    foreach ($uploadFields as $field) {
        if (isset($_FILES[$field]) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
            $filename = time() . "_" . basename($_FILES[$field]['name']);
            $targetPath = $uploadDir . $filename;

            if (move_uploaded_file($_FILES[$field]['tmp_name'], $targetPath)) {
                // Save relative path for DB
                $data[$field] = "uploads/" . $filename;
            } else {
                $data[$field] = '';
            }
        } else {
            $data[$field] = '';
        }
    }

    // Connect to database qmit_system
    $conn = new mysqli('localhost', 'root', '', 'qmit_system');
    if ($conn->connect_error) {
        die("Database connection failed: " . $conn->connect_error);
    }

    // Prepare SQL dynamically
    $fields = array_keys($data);
    $placeholders = implode(',', array_fill(0, count($fields), '?'));
    $sql = "INSERT INTO operatordoc (" . implode(',', $fields) . ") VALUES ($placeholders)";

    $stmt = $conn->prepare($sql);
    if (!$stmt) die("Prepare failed: " . $conn->error);

    $stmt->bind_param(str_repeat('s', count($fields)), ...array_values($data));

    if ($stmt->execute()) {
        echo '<script>alert("Operator registered successfully!");window.location="operator.html";</script>';
    } else {
        echo "Error: " . $stmt->error;
    }

    $stmt->close();
    $conn->close();

} else {
    echo "No form data submitted.";
}
?>
