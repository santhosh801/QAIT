<?php
// operator_view.php
// Combined: registration form + POST handler + AJAX overview responder

// Start session if you want operator-only access later (optional)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// DB connection helper
function get_db() {
    static $mysqli = null;
    if ($mysqli === null) {
        $mysqli = new mysqli('localhost', 'root', '', 'qmit_system');
        if ($mysqli->connect_error) {
            http_response_code(500);
            echo "DB connection failed: " . htmlspecialchars($mysqli->connect_error);
            exit;
        }
        $mysqli->set_charset('utf8mb4');
    }
    return $mysqli;
}

// ---------- AJAX responder: return table fragment for Overview ----------
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    $mysqli = get_db();

    // params: page, filter, search
    $page   = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit  = 10;
    $offset = ($page - 1) * $limit;
    $filter = isset($_GET['filter']) ? trim($_GET['filter']) : '';
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';

    $whereParts = [];
    if ($search !== '') {
        $s = $mysqli->real_escape_string($search);
        $whereParts[] = "(operator_full_name LIKE '%$s%' OR email LIKE '%$s%' OR operator_id LIKE '%$s%')";
    }
    if ($filter !== '') {
        // accepted values: pending, accepted, rejected, nonworking (adjust if needed)
        $allowedFilters = ['pending', 'accepted', 'rejected', 'nonworking'];
        if (in_array($filter, $allowedFilters, true)) {
            $whereParts[] = "status = '" . $mysqli->real_escape_string($filter) . "'";
        }
    }
    $whereSql = $whereParts ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

    // total count (call fetch_assoc() only once)
    $countRes = $mysqli->query("SELECT COUNT(*) AS total FROM operatordoc $whereSql");
    $countRow = null;
    if ($countRes) {
        $countRow = $countRes->fetch_assoc();
    }
    $totalRows = $countRow && isset($countRow['total']) ? (int)$countRow['total'] : 0;
    $totalPages = max(1, (int)ceil($totalRows / $limit));

    // fetch rows
    $sql = "SELECT * FROM operatordoc $whereSql ORDER BY created_at DESC LIMIT $limit OFFSET $offset";
    $res = $mysqli->query($sql);

    // Render fragment
    ob_start();
    ?>
    <div class="k-card">
      <div class="k-sub">Showing: <?= $filter ? htmlspecialchars($filter) : 'Full Operator Overview' ?></div>
    </div>

    <div class="scrollable rounded-2xl shadow-lg">
      <table class="min-w-full text-left text-gray-700 border-collapse">
        <thead>
          <tr>
            <?php
            if ($res && $res->num_rows) {
                $fields = $res->fetch_fields();
                foreach ($fields as $f) {
                    echo "<th class='px-6 py-3 border-b'>" . htmlspecialchars($f->name) . "</th>";
                }
                echo "<th class='px-6 py-3 border-b'>Actions</th>";
            } else {
                // fallback header (common columns)
                $fallback = ['id','operator_id','operator_full_name','email','branch_name','joining_date','status'];
                foreach ($fallback as $h) {
                    echo "<th class='px-6 py-3 border-b'>".htmlspecialchars($h)."</th>";
                }
                echo "<th class='px-6 py-3 border-b'>Actions</th>";
            }
            ?>
          </tr>
        </thead>
        <tbody>
        <?php
        if ($res && $res->num_rows > 0) {
            $res->data_seek(0);
            while ($row = $res->fetch_assoc()) {
                echo "<tr id='row-".htmlspecialchars($row['id'])."'>";
                foreach ($row as $col => $val) {
                    echo "<td class='px-6 py-3 border-b'>";
                    if (str_ends_with($col, '_file') && $val) {
                        echo "<a href='".htmlspecialchars($val)."' target='_blank'>View</a>";
                    } else {
                        echo htmlspecialchars((string)$val);
                    }
                    echo "</td>";
                }
                // actions (uses updateStatus JS in em_verfi.php)
                echo "<td class='px-6 py-3 border-b'>
                        <button class='btn btn-outline-success' onclick=\"updateStatus(".(int)$row['id'].",'accepted')\">Accept</button>
                        <button class='btn btn-outline-warning' onclick=\"updateStatus(".(int)$row['id'].",'pending')\">Pending</button>
                        <button class='btn btn-outline-danger' onclick=\"updateStatus(".(int)$row['id'].",'rejected')\">Reject</button>
                      </td>";
                echo "</tr>";
            }
        } else {
            echo "<tr><td colspan='100%' class='text-center py-4'>No records found</td></tr>";
        }
        ?>
        </tbody>
      </table>
    </div>

    <div class="k-pagination" style="margin-top:12px;">
      <?php for ($p = 1; $p <= $totalPages; $p++): ?>
        <a href="#" class="k-page overview-page <?= $p === $page ? 'is-current' : '' ?>" data-page="<?= $p ?>"><?= $p ?></a>
      <?php endfor; ?>
    </div>
    <?php
    echo ob_get_clean();
    exit; // important for AJAX
}

// ---------- Handle POST registration (form submit) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // map accepted fields (columns that exist in operatordoc)
    $allowed = [
        'branch_name','joining_date','operator_id','operator_full_name','father_name','nseit_number','nseit_date',
        'gender','dob','operator_contact_no','email','aadhar_number','pan_number','voter_id_no','ration_card',
        'current_hno_street','current_village_town','current_pincode','current_postoffice','current_district','current_state',
        'permanent_hno_street','permanent_village_town','permanent_pincode','permanent_postoffice','permanent_district','permanent_state',
        // file fields below will be added to $data when uploaded
        'aadhar_file','pan_file','voter_file','ration_file','consent_file','gps_selfie_file','permanent_address_proof_file',
        'nseit_cert_file','self_declaration_file','non_disclosure_file','police_verification_file','parent_aadhar_file',
        'edu_10th_file','edu_12th_file','edu_college_file'
    ];

    // Basic required checks (minimal)
    $required = ['operator_full_name','email','operator_id'];
    foreach ($required as $r) {
        if (empty($_POST[$r])) {
            die("Error: '$r' is required.");
        }
    }

    // Collect posted values
    $data = [];
    foreach ($allowed as $k) {
        // file fields will be handled below; initialize empty
        if (str_ends_with($k, '_file')) {
            $data[$k] = '';
            continue;
        }
        $data[$k] = isset($_POST[$k]) ? trim($_POST[$k]) : '';
    }

    // File uploads handling
    $uploadFields = array_filter($allowed, fn($x) => str_ends_with($x,'_file'));
    $uploadDir = __DIR__ . '/uploads/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

    foreach ($uploadFields as $field) {
        if (isset($_FILES[$field]) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
            $orig = basename($_FILES[$field]['name']);
            $safe = time() . '_' . preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $orig);
            $target = $uploadDir . $safe;
            if (move_uploaded_file($_FILES[$field]['tmp_name'], $target)) {
                // Save relative path — adjust if you serve uploads from a different route
                $data[$field] = 'uploads/' . $safe;
            } else {
                $data[$field] = '';
            }
        } else {
            $data[$field] = '';
        }
    }

    // Add default status if not provided
    if (!isset($data['status'])) $data['status'] = 'pending';

    // Build insert (only include keys that exist in $data)
    $fields = array_keys($data);
    $placeholders = implode(',', array_fill(0, count($fields), '?'));
    $colList = implode(',', $fields);

    $mysqli = get_db();
    $stmt = $mysqli->prepare("INSERT INTO operatordoc ($colList) VALUES ($placeholders)");
    if (!$stmt) {
        die("Prepare failed: " . htmlspecialchars($mysqli->error));
    }

    // prepare types and values (all strings here)
    $types = str_repeat('s', count($fields));
    $values = array_values($data);

    // bind dynamically
    $bind_names[] = $types;
    for ($i=0; $i < count($values); $i++) {
        $bind_name = 'bind' . $i;
        $$bind_name = $values[$i];
        $bind_names[] = &$$bind_name;
    }
    call_user_func_array([$stmt, 'bind_param'], $bind_names);

    if ($stmt->execute()) {
        // success: redirect back to the registration page or show message
        echo '<script>alert("Operator registered successfully!");window.location="operator_view.html";</script>';
        exit;
    } else {
        echo "Error inserting: " . htmlspecialchars($stmt->error);
        exit;
    }
}

// ---------- If not AJAX and not POST: show registration HTML ----------
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>QMIT — Operator Registration</title>
<link href="https://fonts.googleapis.com/css?family=Raleway:400,700" rel="stylesheet">
<!-- Link your existing CSS or use operator_view.css -->
<link rel="stylesheet" href="operator_view.css">
</head>
<body>
<div class="container">
    <h2>Operator Registration</h2>
    <ul id="progressbar">
        <li class="active">Personal Info</li>
        <li>Current Address</li>
        <li>Permanent Address</li>
        <li>Documents & Submit</li>
    </ul>

    <form id="msform" method="post" action="operator_view.php" enctype="multipart/form-data">
        <!-- Personal Info -->
        <fieldset>
            <input type="text" name="operator_full_name" placeholder="Full Name" required>
            <input type="email" name="email" placeholder="Email" required>

            <label for="father_name">Father’s Name</label>
            <input type="text" id="father_name" name="father_name" required>

            <label for="dob">Date of Birth</label>
            <input type="date" id="dob" name="dob" required>

            <label for="gender">Gender</label>
            <select name="gender" id="gender" required>
                <option value="">Select Gender</option>
                <option value="Male">Male</option>
                <option value="Female">Female</option>
                <option value="Other">Other</option>
            </select>

            <label for="aadhar_number">Aadhaar Number</label>
            <input type="text" id="aadhar_number" name="aadhar_number"
                pattern="\d{12}" maxlength="12" minlength="12"
                title="Aadhaar must be exactly 12 digits" required>
            <input type="file" name="aadhar_file" accept=".jpg,.jpeg,.png,.pdf" required>

            <input type="text" name="operator_id" placeholder="Unique ID" required>

            <label for="operator_contact_no">Mobile Number</label>
            <input type="tel" id="operator_contact_no" name="operator_contact_no"
                pattern="\d{10}" maxlength="10" minlength="10"
                title="Mobile number must be exactly 10 digits" required>

            <input type="text" name="branch_name" placeholder="Branch Name" required>
            <input type="date" name="joining_date" required>

            <label for="nseit_number">NSEIT Number</label>
            <input type="text" id="nseit_number" name="nseit_number">

            <label for="nseit_date">NSEIT Date</label>
            <input type="date" id="nseit_date" name="nseit_date">

            <button type="button" class="next action-button">Next</button>
        </fieldset>

        <!-- Current Address -->
        <fieldset>
            <h4>Current Address</h4>
            <input type="text" name="current_hno_street" placeholder="H.No / Street">
            <input type="text" name="current_village_town" placeholder="Village/Town">
            <label for="current_pincode">Current Pincode</label>
            <input type="text" id="current_pincode" name="current_pincode"
                pattern="\d{6}" maxlength="6" minlength="6"
                title="Pincode must be 6 digits">
            <input type="text" name="current_postoffice" placeholder="Postoffice">
            <input type="text" name="current_district" placeholder="District">
            <input type="text" name="current_state" placeholder="State">
            <button type="button" class="previous action-button">Previous</button>
            <button type="button" class="next action-button">Next</button>
        </fieldset>

        <!-- Permanent Address -->
        <fieldset>
            <h4>Permanent Address</h4>
            <input type="text" name="permanent_hno_street" placeholder="H.No / Street">
            <input type="text" name="permanent_village_town" placeholder="Village/Town">
            <input type="text" name="permanent_pincode" placeholder="Pincode">
            <input type="text" name="permanent_postoffice" placeholder="Postoffice">
            <input type="text" name="permanent_district" placeholder="District">
            <input type="text" name="permanent_state" placeholder="State">
            <button type="button" class="previous action-button">Previous</button>
            <button type="button" class="next action-button">Next</button>
        </fieldset>

        <!-- Documents & Submit -->
        <fieldset>
            <h4>Documents Upload</h4>

            <label>PAN Number</label>
            <input type="text" name="pan_number" pattern="[A-Z]{5}[0-9]{4}[A-Z]{1}" maxlength="10" required>
            <input type="file" name="pan_file" accept=".jpg,.jpeg,.png,.pdf" required>

            <label>Voter ID</label>
            <input type="text" name="voter_id_no" pattern="[A-Za-z0-9]{10,20}" maxlength="20" required>
            <input type="file" name="voter_file" accept=".jpg,.jpeg,.png,.pdf" required>

            <label>Consent Form</label>
            <input type="file" name="consent_file" accept=".jpg,.jpeg,.png,.pdf" required>

            <label>HOME GPS Selfie</label>
            <input type="file" name="gps_selfie_file" accept=".jpg,.jpeg,.png,.pdf" required>

            <label>PASSBOOK</label>
            <input type="file" name="permanent_address_proof_file" accept=".jpg,.jpeg,.png,.pdf" required>

            <label>Ration Card Number</label>
            <input type="text" name="ration_card">
            <input type="file" name="ration_file" accept=".jpg,.jpeg,.png,.pdf">

            <label>NSEIT Certificate</label>
            <input type="file" name="nseit_cert_file" accept=".jpg,.jpeg,.png,.pdf" required>

            <label>Self Declaration</label>
            <input type="file" name="self_declaration_file" accept=".jpg,.jpeg,.png,.pdf" required>

            <label>Non-Disclosure Certificate</label>
            <input type="file" name="non_disclosure_file" accept=".jpg,.jpeg,.png,.pdf" required>

            <label>Police Verification</label>
            <input type="file" name="police_verification_file" accept=".jpg,.jpeg,.png,.pdf" required>

            <label>Parent Aadhaar</label>
            <input type="file" name="parent_aadhar_file" accept=".jpg,.jpeg,.png,.pdf" required>

            <label>10th Certificate (Optional)</label>
            <input type="file" name="edu_10th_file" accept=".jpg,.jpeg,.png,.pdf">

            <label>12th Certificate (Optional)</label>
            <input type="file" name="edu_12th_file" accept=".jpg,.jpeg,.png,.pdf">

            <label>College Certificate (Optional)</label>
            <input type="file" name="edu_college_file" accept=".jpg,.jpeg,.png,.pdf">

            <button type="button" class="previous action-button">Previous</button>
            <button type="submit" class="submit action-button">Register Operator</button>
        </fieldset>
    </form>
</div>

<!-- jQuery + easing (your existing scripts) -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery-easing/1.4.1/jquery.easing.min.js"></script>
<script>
var current_fs, next_fs, previous_fs, animating;
$(".next").click(function(){
    if(animating) return false;
    animating = true;
    current_fs = $(this).parent();
    next_fs = $(this).parent().next();
    $("#progressbar li").eq($("fieldset").index(next_fs)).addClass("active");
    next_fs.css({opacity:0}).show();
    current_fs.animate({opacity: 0}, {
        step: function(now) {
            var scale = 1 - (1 - now) * 0.2;
            var left = (now * 50)+"%";
            var opacity = 1 - now;
            current_fs.css({'transform':'scale('+scale+')'});
            next_fs.css({'left': left, 'opacity': opacity});
        },
        duration: 600,
        complete: function(){
            current_fs.hide();
            animating = false;
        },
        easing: 'easeInOutBack'
    });
});
$(".previous").click(function(){
    if(animating) return false;
    animating = true;
    current_fs = $(this).parent();
    previous_fs = $(this).parent().prev();
    $("#progressbar li").eq($("fieldset").index(current_fs)).removeClass("active");
    previous_fs.show();
    current_fs.animate({opacity: 0}, {
        step: function(now) {
            var scale = 0.8 + (1 - now) * 0.2;
            var left = ((1-now) * 50)+"%";
            var opacity = 1 - now;
            current_fs.css({'left': left});
            previous_fs.css({'transform':'scale('+scale+')', 'opacity': opacity});
        },
        duration: 600,
        complete: function(){
            current_fs.hide();
            animating = false;
        },
        easing: 'easeInOutBack'
    });
});
</script>
</body>
</html>
