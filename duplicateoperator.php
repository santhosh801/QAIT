<?php
// duplicateoperator.php
session_start();
$mysqli = new mysqli('localhost','root','','qmit_system');
if ($mysqli->connect_errno) { http_response_code(500); echo "Database connection failed."; exit; }

// helper
function base_url_for_script() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    $dir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    $dir = $dir === '/' ? '' : $dir;
    return $scheme . '://' . $host . $dir;
}

// token input
$token = '';
if (!empty($_GET['token'])) $token = trim((string)$_GET['token']);
if ($token === '' && !empty($_POST['token'])) $token = trim((string)$_POST['token']);

if ($token === '') {
    $home = htmlspecialchars(base_url_for_script() . '/');
    ?>
    <!doctype html><html><head><meta charset="utf-8"><title>Missing token</title></head><body style="font-family:Arial;padding:24px">
    <div style="max-width:720px;margin:40px auto">
      <h1>Missing or invalid resubmission token</h1>
      <p>Paste the token from the email:</p>
      <form method="post" action="duplicateoperator.php">
        <input name="token" required style="width:100%;padding:10px;margin-bottom:8px">
        <button type="submit" style="padding:10px 14px">Continue</button>
        <a href="<?= $home ?>" style="margin-left:12px">Portal home</a>
      </form>
    </div></body></html>
    <?php exit;
}

// fetch request
$reqStmt = $mysqli->prepare("SELECT id, operator_id, token, docs_json, expires_at, created_by, created_at FROM resubmission_requests WHERE token = ? LIMIT 1");
if (!$reqStmt) { echo "<p>DB error</p>"; exit; }
$reqStmt->bind_param('s', $token);
$reqStmt->execute();
$reqRes = $reqStmt->get_result();
if (!$reqRes || $reqRes->num_rows === 0) { http_response_code(404); echo "<p>Resubmission token not found.</p>"; exit; }
$reqRow = $reqRes->fetch_assoc(); $reqStmt->close();

// expiry
if (!empty($reqRow['expires_at'])) {
    try { $now = new DateTime(); $exp = new DateTime($reqRow['expires_at']); if ($now > $exp) { echo "<p>This link expired at ".htmlspecialchars($reqRow['expires_at'])."</p>"; exit; } } catch (Exception $e) {}
}

// fetch operator
$opId = (int)$reqRow['operator_id'];
$opStmt = $mysqli->prepare("SELECT * FROM operatordoc WHERE id = ? LIMIT 1");
$opStmt->bind_param('i', $opId);
$opStmt->execute();
$opRes = $opStmt->get_result();
if (!$opRes || $opRes->num_rows === 0) { echo "<p>Operator not found (ID: $opId).</p>"; exit; }
$op = $opRes->fetch_assoc();
$opStmt->close();

// parse docs_json
$requested_docs = [];
if (!empty($reqRow['docs_json'])) {
    $tmp = json_decode($reqRow['docs_json'], true);
    if (is_array($tmp)) $requested_docs = $tmp;
}
if (empty($requested_docs) && !empty($op['rejection_summary'])) {
    foreach (preg_split('/\r\n|\n|\r/', $op['rejection_summary']) as $line) {
        $line = trim($line);
        if ($line !== '') $requested_docs[] = $line;
    }
}

// label map (same canonical keys)
$label_map = [
    'aadhar_file'=>'Aadhaar Card','pan_file'=>'PAN Card','voter_file'=>'Voter ID',
    'ration_file'=>'Ration Card','consent_file'=>'Consent','gps_selfie_file'=>'GPS Selfie',
    'permanent_address_proof_file'=>'Permanent Address Proof','nseit_cert_file'=>'NSEIT Certificate',
    'self_declaration_file'=>'Self Declaration','non_disclosure_file'=>'Non-Disclosure Agreement',
    'police_verification_file'=>'Police Verification','parent_aadhar_file'=>"Parent's Aadhaar",
    'edu_10th_file'=>'10th Certificate','edu_12th_file'=>'12th Certificate','edu_college_file'=>'College Certificate',
    'agreement_file'=>'Agreement','bank_passbook_file'=>'Bank Passbook','photo_file'=>'Photo'
];

?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Re-submit Documents — <?= htmlspecialchars($op['operator_full_name'] ?: $op['operator_id']) ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    body{font-family:Inter,system-ui,Arial,sans-serif;background:#f7fafc;color:#0f172a;padding:18px}
    .wrap{max-width:920px;margin:20px auto;background:#fff;padding:18px;border-radius:10px;box-shadow:0 8px 30px rgba(2,6,23,.06)}
    h1{margin:0 0 6px;font-size:20px}
    .muted{color:#6b7280}
    ul.req{background:#f8fafc;padding:12px;border-radius:8px}
    .doc-row{display:flex;gap:12px;align-items:center;padding:8px 0;border-bottom:1px dashed #eef2f6}
    .doc-row:last-child{border-bottom:none}
    label.file-label{flex:1}
    input[type=file]{padding:6px}
    button.primary{background:#0ea5e9;border:none;color:white;padding:10px 12px;border-radius:8px;cursor:pointer}
    .note{font-size:13px;color:#374151;margin-top:12px}
  </style>
</head>
<body>
  <div class="wrap">
    <h1>Re-submit documents — <?= htmlspecialchars($op['operator_full_name'] ?: $op['operator_id']) ?></h1>
    <p class="muted">This link was created by <?= htmlspecialchars($reqRow['created_by'] ?? 'the verification team') ?> and expires on <strong><?= htmlspecialchars($reqRow['expires_at'] ?? 'N/A') ?></strong>.</p>

    <h3 style="margin-top:12px">Documents to re-submit</h3>
    <ul class="req">
      <?php
        if (!empty($requested_docs)) {
            foreach ($requested_docs as $d) {
                $label = isset($label_map[$d]) ? $label_map[$d] : $d;
                echo '<li>' . htmlspecialchars($label) . '</li>';
            }
        } else {
            echo '<li>General re-upload requested. Please attach corrected documents below.</li>';
        }
      ?>
    </ul>

    <h3 style="margin-top:12px">Upload corrected documents</h3>

    <!-- single form -->
    <form action="upload_docs.php" method="post" enctype="multipart/form-data" id="bulk-upload-form">
      <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
      <input type="hidden" name="id" value="<?= (int)$opId ?>">

      <?php
      if (!empty($requested_docs)) {
          foreach ($requested_docs as $kIndex => $doc) {
              // ensure the input name uses canonical key if it's a recognized key, otherwise use doc_<index>
              $docKey = array_key_exists($doc, $label_map) ? $doc : "doc_{$kIndex}";
              $label = htmlspecialchars($label_map[$doc] ?? $doc);
              // show indicator if a file already exists
              $existing = '';
              if (!empty($op[$docKey])) $existing = ' — <em>Already uploaded</em>';
              ?>
              <div class="doc-row">
                <label class="file-label" for="file_<?= htmlspecialchars($docKey) ?>"><strong><?= $label ?></strong><span style="color:#6b7280"><?= $existing ?></span></label>
                <input type="file" id="file_<?= htmlspecialchars($docKey) ?>" name="files[<?= htmlspecialchars($docKey) ?>]" accept=".pdf,.jpg,.png,.jpeg">
              </div>
              <?php
          }
      } else {
          ?>
          <div class="doc-row">
            <label class="file-label" for="file_generic"><strong>Attach corrected documents</strong></label>
            <input type="file" id="file_generic" name="files[general][]" multiple accept=".pdf,.jpg,.png,.jpeg">
          </div>
          <?php
      }
      ?>

      <div style="margin-top:14px">
        <button type="submit" class="primary">Upload all corrected docs</button>
        <span class="note">Select files for each document then click <strong>Upload all corrected docs</strong>. Files left empty will be ignored.</span>
      </div>
    </form>

    <hr style="margin:16px 0;border:none;border-top:1px solid #eef2f7">
    <p class="muted">If you have trouble uploading files, reply to the email or contact support at <a href="mailto:support@qit.com">support@qit.com</a>.</p>
  </div>
</body>
</html>
