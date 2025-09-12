<?php
// operator_view.php
// Combined: registration form + POST handler + AJAX overview responder
// NOTE: keep download.php (separate) which validates file access and serves files securely.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// -----------------------------
// DB helper
// -----------------------------
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

// -----------------------------
// canonical label map (PHP) - also printed to JS below
// -----------------------------
$labels = [
  'aadhar_file'=>'aadhardoc','pan_file'=>'pandoc','voter_file'=>'voterdoc',
  'ration_file'=>'rationdoc','consent_file'=>'consentdoc','gps_selfie_file'=>'gpsselfiedoc',
  'permanent_address_proof_file'=>'addressproofdoc','nseit_cert_file'=>'nseitcertdoc',
  'self_declaration_file'=>'selfdecdoc','non_disclosure_file'=>'ndadoc',
  'police_verification_file'=>'policeverdoc','parent_aadhar_file'=>'parentaadhardoc',
  'edu_10th_file'=>'edu10thdoc','edu_12th_file'=>'edu12thdoc','edu_college_file'=>'educollegedoc'
];

// -----------------------------
// allowlists: non-file and file columns
// -----------------------------
$allowlist = [
    'id','operator_id','operator_full_name','email','branch_name','joining_date','operator_contact_no',
    'father_name','dob','gender','aadhar_number','pan_number','voter_id_no','ration_card',
    'nseit_number','nseit_date','current_hno_street','current_village_town','current_pincode',
    'current_postoffice','current_district','current_state','permanent_hno_street',
    'permanent_village_town','permanent_pincode','permanent_postoffice','permanent_district',
    'permanent_state','bank','status','work_status','review_notes','rejection_summary',
    'created_at','last_modified_at'
];

$fileAllowlist = array_keys($labels); // use label keys

// small helper: filter row to safe shape for output
function filter_row_for_client(array $row, array $allowlist, array $fileAllowlist = []) : array {
    $out = [];
    foreach ($allowlist as $k) {
        if (array_key_exists($k, $row)) $out[$k] = $row[$k];
    }
    foreach ($fileAllowlist as $k) {
        $out[$k] = array_key_exists($k, $row) && $row[$k] ? $row[$k] : '';
    }
    return $out;
}

// -----------------------------
// AJAX responder: return table fragment for Overview
// -----------------------------
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
        // treat filter as either status or work_status (accept aliases)
        $statusAllowed = ['pending','accepted','rejected'];
        $workAllowed = ['working','not working','notworking','nonworking'];

        $f = strtolower(trim($filter));
        if (in_array($f, $statusAllowed, true)) {
            $whereParts[] = "status = '" . $mysqli->real_escape_string($f) . "'";
        } elseif (in_array($f, $workAllowed, true)) {
            if ($f === 'notworking' || $f === 'nonworking') $f = 'not working';
            $whereParts[] = "work_status = '" . $mysqli->real_escape_string($f) . "'";
        }
    }

    // Optional bank filter passed via AJAX (exact match)
    if (isset($_GET['bank']) && $_GET['bank'] !== '') {
        $bn = $mysqli->real_escape_string($_GET['bank']);
        $whereParts[] = "bank = '$bn'";
    }

    $whereSql = $whereParts ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

    // total count
    $countRes = $mysqli->query("SELECT COUNT(*) AS total FROM operatordoc $whereSql");
    $totalRows = 0;
    if ($countRes) {
        $c = $countRes->fetch_assoc();
        $totalRows = isset($c['total']) ? (int)$c['total'] : 0;
    }
    $totalPages = max(1, (int)ceil($totalRows / $limit));

    // fetch rows
    $sql = "SELECT * FROM operatordoc $whereSql ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}";
    $res = $mysqli->query($sql);

    // Render fragment (HTML). Keep output minimal and safe.
    ob_start();
    ?>
    <div class="k-card">
      <div class="k-sub">Showing: <?= $filter ? htmlspecialchars($filter) : 'Full Operator Overview' ?></div>
    </div>

    <div class="scrollable rounded-2xl shadow-lg" style="overflow:auto;">
      <table class="min-w-full text-left text-gray-700 border-collapse" style="width:100%;border-collapse:collapse">
        <thead>
          <tr>
            <?php
            if ($res && $res->num_rows) {
                $fields = $res->fetch_fields();
                foreach ($fields as $f) {
                    echo "<th class='px-6 py-3 border-b' style='padding:8px;border-bottom:1px solid #eee'>" . htmlspecialchars($f->name) . "</th>";
                }
                echo "<th class='px-6 py-3 border-b' style='padding:8px;border-bottom:1px solid #eee'>Actions</th>";
            } else {
                $fallback = ['id','operator_id','operator_full_name','email','branch_name','joining_date','status'];
                foreach ($fallback as $h) {
                    echo "<th class='px-6 py-3 border-b' style='padding:8px;border-bottom:1px solid #eee'>".htmlspecialchars($h)."</th>";
                }
                echo "<th class='px-6 py-3 border-b' style='padding:8px;border-bottom:1px solid #eee'>Actions</th>";
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
                    echo "<td class='px-6 py-3 border-b' style='padding:8px;border-bottom:1px solid #f6f6f6'>";
                    if (str_ends_with($col, '_file')) {
                        if ($val) {
                            $view = htmlspecialchars($val);
                            $downloadHref = "download.php?id=" . urlencode($row['id']) . "&doc_key=" . urlencode($col);
                            echo "<a href='".htmlspecialchars($view)."' target='_blank' rel='noopener'>View</a>";
                            echo " &nbsp; ";
                            echo "<a class='btn-download' href='".htmlspecialchars($downloadHref)."'>Download</a>";
                        } else {
                            echo '—';
                        }
                    } else {
                        echo htmlspecialchars((string)$val);
                    }
                    echo "</td>";
                }
                // actions
                $id = (int)$row['id'];
                echo "<td class='px-6 py-3 border-b' style='padding:8px;border-bottom:1px solid #eee'>
                        <button class='btn' onclick=\"updateStatus($id,'accepted')\">Accept</button>
                        <button class='btn' onclick=\"updateStatus($id,'pending')\">Pending</button>
                        <button class='btn' onclick=\"updateStatus($id,'rejected')\">Reject</button>
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

// -----------------------------
// Handle POST registration (form submit)
// -----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mysqli = get_db();

    // accepted fields (matching DB) — using 'bank' column
    $allowed = [
        'branch_name','joining_date','operator_id','operator_full_name','father_name','nseit_number','nseit_date',
        'gender','dob','operator_contact_no','email','aadhar_number','pan_number','voter_id_no','ration_card',
        'current_hno_street','current_village_town','current_pincode','current_postoffice','current_district','current_state',
        'permanent_hno_street','permanent_village_town','permanent_pincode','permanent_postoffice','permanent_district','permanent_state',
        'bank',
        // file fields (keys only)
        ...array_keys($labels)
    ];

    // minimal required checks
    $required = ['operator_full_name','email','operator_id'];
    foreach ($required as $r) {
        if (empty($_POST[$r])) {
            die("Error: '$r' is required.");
        }
    }

    // allowed bank list (server-side validation)
    $allowedBanks = [
      'Karur Vysya Bank',
      'City Union Bank',
      'Tamilnad Mercantile Bank',
      'Indian Bank',
      'Karnataka Bank',
      'Equitas Small Finance Bank',
      'Union Bank Of India'
    ];

    // collect posted values (initialize file fields empty)
    $data = [];
    foreach ($allowed as $k) {
        if (str_ends_with($k, '_file')) {
            $data[$k] = '';
            continue;
        }
        $data[$k] = isset($_POST[$k]) ? trim((string)$_POST[$k]) : '';
    }

    // Validate bank server-side - ensure only allowed values are stored
    if (isset($data['bank'])) {
        $bn = trim($data['bank']);
        if ($bn === '' || !in_array($bn, $allowedBanks, true)) {
            // store empty string if invalid / not provided
            $data['bank'] = '';
        } else {
            $data['bank'] = $bn;
        }
    }

    // -----------------------------
    // FILE UPLOADS: per-operator folder + canonical label filenames
    // -----------------------------
    $uploadFields = array_keys($labels); // e.g. aadhar_file, pan_file...
    $baseUploadDir = __DIR__ . '/uploads/'; // base folder for all uploads
    if (!is_dir($baseUploadDir)) {
        mkdir($baseUploadDir, 0755, true);
    }

    // sanitize operator folder name (use operator_id + safe name to avoid collisions)
    $rawOperatorId = isset($data['operator_id']) ? $data['operator_id'] : '';
    $rawOperatorName = isset($data['operator_full_name']) ? $data['operator_full_name'] : 'operator';
    // create safe component function
    $safe_component = function($s) {
        $s = trim((string)$s);
        $s = preg_replace('/\s+/', '_', $s);
        $s = preg_replace('/[^\p{L}\p{N}\._\-]/u', '', $s);
        $s = preg_replace('/_+/', '_', $s);
        return $s ?: 'val';
    };
    $safeOpId = preg_replace('/[^\w\-]/', '', (string)$rawOperatorId) ?: time();
    $safeOpName = $safe_component($rawOperatorName);
    $operatorFolderName = $safeOpName. '_' . $safeOpId;
    $operatorDir = $baseUploadDir . $operatorFolderName . '/';

    if (!is_dir($operatorDir)) {
        mkdir($operatorDir, 0755, true);
        // optional: add .htaccess to prevent directory listing or direct access if desired
        @file_put_contents($operatorDir . '.htaccess', "Options -Indexes\n<IfModule mod_headers.c>\n  Header set X-Content-Type-Options nosniff\n</IfModule>\n");
    }

    $maxBytes = 5 * 1024 * 1024; // 5MB limit
    $allowedMimes = ['image/jpeg','image/png','application/pdf'];

    foreach ($uploadFields as $field) {
        // expected input name is the field
        if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
            $data[$field] = ''; // no file uploaded
            continue;
        }

        $tmp = $_FILES[$field]['tmp_name'];
        $fsize = $_FILES[$field]['size'];

        // mime detection
        $ftype = null;
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $ftype = finfo_file($finfo, $tmp);
                finfo_close($finfo);
            }
        }
        if (!$ftype) {
            $ftype = mime_content_type($tmp) ?: $_FILES[$field]['type'];
        }

        // size & mime checks
        if ($fsize > $maxBytes) {
            // too big; skip storing and leave empty
            $data[$field] = '';
            continue;
        }
        if (!in_array($ftype, $allowedMimes, true)) {
            // unsupported type; skip
            $data[$field] = '';
            continue;
        }

        // get extension from original filename but normalize to common ones
        $orig = basename($_FILES[$field]['name']);
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','pdf'], true)) {
            // fallback: map mime -> ext
            if ($ftype === 'application/pdf') $ext = 'pdf';
            elseif ($ftype === 'image/png') $ext = 'png';
            elseif (in_array($ftype, ['image/jpeg','image/jpg'])) $ext = 'jpg';
            else $ext = 'bin';
        }

        // canonical label for filename (from $labels map)
        $labelBase = isset($labels[$field]) ? $labels[$field] : preg_replace('/[^A-Za-z0-9]/', '_', $field);
        // final filename inside operator folder: <label>.<ext>
        $finalFilename = $labelBase . '.' . $ext;
        $target = $operatorDir . $finalFilename;

        // move uploaded file to the operator folder (overwrite existing intentionally)
        if (move_uploaded_file($tmp, $target)) {
            // store relative path used by UI/download endpoint
            $data[$field] = 'uploads/' . $operatorFolderName . '/' . $finalFilename;
            // Optionally set secure permissions
            @chmod($target, 0644);
        } else {
            $data[$field] = '';
        }
    }

    // default status
    if (!isset($data['status'])) $data['status'] = 'pending';

    // Build insert only for non-empty keys (but keep keys order stable)
    $fields = array_keys($data);
    $placeholders = implode(',', array_fill(0, count($fields), '?'));
    $colList = implode(',', $fields);

    $stmt = $mysqli->prepare("INSERT INTO operatordoc ($colList) VALUES ($placeholders)");
    if (!$stmt) {
        die("Prepare failed: " . htmlspecialchars($mysqli->error));
    }

    // all string types for simplicity
    $types = str_repeat('s', count($fields));
    $values = array_values($data);

    // dynamic bind (call_user_func_array needs references)
    $bind_names = [];
    $bind_names[] = $types;
    for ($i = 0; $i < count($values); $i++) {
        $bind_name = 'bind' . $i;
        $$bind_name = $values[$i];
        $bind_names[] = &$$bind_name;
    }
    call_user_func_array([$stmt, 'bind_param'], $bind_names);

    if ($stmt->execute()) {
        echo '<script>alert("Operator registered successfully!");window.location="operator_view.php";</script>';
        exit;
    } else {
        echo "Error inserting: " . htmlspecialchars($stmt->error);
        exit;
    }
}

// -----------------------------
// Default: render registration HTML + helpful JS
// -----------------------------
?><!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>QMIT — Operator Registration</title>
<link href="https://fonts.googleapis.com/css?family=Raleway:400,700" rel="stylesheet">
<link rel="stylesheet" href="operator_view.css">
<style>
/* small helper styles for download buttons and layout */
.btn-download { padding:6px 10px; border-radius:6px; background:#eef; border:1px solid #ccd; text-decoration:none; }
.btn { margin-right:6px; padding:6px 10px; border-radius:6px; border:1px solid #bbb; background:#fff; cursor:pointer; }
.k-card { padding:8px; margin-bottom:8px; background:#fff; border:1px solid #eee; border-radius:8px; }

/* bank select style */
.bank-select { display:block; margin-top:8px; padding:10px; border-radius:6px; border:1px solid #ddd; max-width:420px; }
</style>
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

            <label for="bank">Bank</label>
            <select name="bank" id="bank" class="bank-select" required>
                <option value="">Select Bank</option>
                <option value="Karur Vysya Bank">Karur Vysya Bank</option>
                <option value="City Union Bank">City Union Bank</option>
                <option value="Tamilnad Mercantile Bank">Tamilnad Mercantile Bank</option>
                <option value="Indian Bank">Indian Bank</option>
                <option value="Karnataka Bank">Karnataka Bank</option>
                <option value="Equitas Small Finance Bank">Equitas Small Finance Bank</option>
                <option value="Union Bank Of India">Union Bank Of India</option>
            </select>

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

// expose PHP labels into JS for consistency
const LABELS = <?= json_encode($labels, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;

// small helper in client if you dynamically build doc rows
function prettifyFilename(u){ try{ return u.split('/').pop().split('?')[0]; }catch(e){ return 'file'; } }

/*
  New JS helpers:
  - sendRejectionMail(opId) -> create resubmission request and send a single consolidated mail
  - uploadDocClient(opId, docKey, fileInputEl, onSuccess) -> client uploader for upload_doc.php
*/

function sendRejectionMail(opId) {
  if (!opId) { alert('Missing operator id'); return; }

  // collect candidate doc keys from LABELS keys (UI should pass reasons elsewhere)
  // Prefer: collect from your panel UI if you have visible reason inputs
  const panel = document.getElementById('opDetailPanel');
  let docs = [];

  if (panel) {
    // collect doc keys for which a reason input exists with non-empty value
    panel.querySelectorAll('.doc-reject-reason .reason-input').forEach(inp => {
      const docKey = inp.getAttribute('data-doc') || inp.dataset.doc;
      if (docKey && inp.value && inp.value.trim()) docs.push(docKey);
    });

    // fallback: any doc-row which has a .btn-reject that was clicked and shows reason box
    if (docs.length === 0) {
      panel.querySelectorAll('.doc-row').forEach(row => {
        const rejectArea = row.querySelector('.doc-reject-reason');
        const btn = row.querySelector('.btn-reject');
        const dataDoc = btn ? (btn.dataset && btn.dataset.doc ? btn.dataset.doc : null) : null;
        if (dataDoc && rejectArea && rejectArea.style && rejectArea.style.display !== 'none') {
          docs.push(dataDoc);
        }
      });
    }
  }

  // Deduplicate & ensure allowed keys
  docs = Array.from(new Set(docs || [])).filter(k => Object.prototype.hasOwnProperty.call(LABELS, k));

  // Prepare payload to create resubmission request. If docs is empty, server fallback will use rejection_summary.
  const payload = { id: opId, docs: JSON.stringify(docs), email_now: 0 };
  $.ajax({
    url: 'create_resubmission.php',
    method: 'POST',
    data: payload,
    dataType: 'json'
  }).done(function(resp) {
    if (!resp || !resp.success) {
      console.warn('create_resubmission returned', resp);
      if (confirm('Failed to create resubmission request. Send mail based on rejection_summary anyway?')) {
        $.post('send_rejection_mail.php', { id: opId }, function(r){ if (r && r.success) alert('Mail sent'); else alert('Mail failed'); }, 'json');
      }
      return;
    }
    // success -> resp.token & resp.url
    const token = resp.token;
    $.post('send_rejection_mail.php', { token: token }, function(mres) {
      if (mres && mres.success) {
        alert('Rejection mail sent to operator.\nResubmission link: ' + (resp.url || mres.resubmit_url || ''));
        if (panel) panel.dataset.resubRequestId = resp.request_id || '';
      } else {
        alert('Mail failed: ' + (mres && mres.message ? mres.message : 'unknown'));
        console.warn('send_rejection_mail failed', mres);
      }
    }, 'json').fail(function() { alert('Mail request failed'); });
  }).fail(function() { alert('Failed to create resubmission request'); });
}


// Client-side uploader helper that calls upload_doc.php and updates UI if present.
// Usage: uploadDocClient(id, 'aadhar_file', inputEl, function(resp){ /* optional success */ })
function uploadDocClient(opId, docKey, fileInputEl, onSuccess) {
  if (!opId || !docKey || !fileInputEl || !fileInputEl.files || !fileInputEl.files[0]) {
    alert('Missing parameters or file');
    return;
  }

  // quick docKey validation client-side
  if (!Object.prototype.hasOwnProperty.call(LABELS, docKey)) {
    alert('Invalid document key: ' + docKey);
    return;
  }

  const fd = new FormData();
  fd.append('id', opId);
  fd.append('doc_key', docKey);
  fd.append('file', fileInputEl.files[0]);

  $.ajax({
    url: 'upload_doc.php',
    method: 'POST',
    data: fd,
    processData: false,
    contentType: false,
    dataType: 'json',
    success: function(res) {
      if (res && res.success) {
        alert('Uploaded: ' + (res.path || ''));
        // update panel if present
        const panel = document.getElementById('opDetailPanel');
        if (panel) {
          // find doc row by data-doc attr on file input or buttons
          const row = panel.querySelector('.doc-row [data-doc="'+docKey+'"]') ? panel.querySelector('.doc-row [data-doc="'+docKey+'"]').closest('.doc-row') : null;
          if (row) {
            const linkSpan = row.querySelector('.doc-link');
            if (linkSpan) linkSpan.innerHTML = '<a href="'+res.path+'" target="_blank">'+prettifyFilename(res.path)+'</a>';
            const dlBtn = row.querySelector('.btn-download');
            if (dlBtn) dlBtn.disabled = false;
          }
        }
        // update table row if present
        const tr = document.getElementById('row-' + opId);
        if (tr) {
          const td = tr.querySelector('td[data-col="'+docKey+'"]');
          if (td) {
            td.innerHTML = '<a href="'+res.path+'" target="_blank">View</a>';
          } else {
            // fallback: replace any cell that contains the old file path text
            tr.querySelectorAll('td').forEach(c => {
              if (c.textContent && c.textContent.indexOf(docKey) !== -1) {
                c.innerHTML = '<a href="'+res.path+'" target="_blank">View</a>';
              }
            });
          }
        }

        if (typeof onSuccess === 'function') onSuccess(res);
      } else {
        alert('Upload failed: ' + (res && res.message ? res.message : 'unknown'));
        console.warn('upload_doc response', res);
      }
    },
    error: function(xhr, status, err) {
      console.error('upload fail', status, err, xhr && xhr.responseText);
      alert('Upload request failed');
    }
  });
}
</script>
</body>
</html>
