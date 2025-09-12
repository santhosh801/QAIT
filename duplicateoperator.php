<?php
// duplicateoperator.php
// Safe, user-friendly token handling for resubmission links.
// - If ?token=... is missing, shows a small page explaining how to use the link and a form to paste a token.
// - If token provided, validates request, checks expiry, shows operator info and file upload form(s).
// - Does NOT assume a `used` column exists. Optionally mark used if you add that column.
//
// Requirements: PHP 7.2+ (uses mysqli, DateTime). Adjust DB creds if necessary.

session_start();
$mysqli = new mysqli('localhost','root','','qmit_system');
if ($mysqli->connect_errno) {
    http_response_code(500);
    echo "Database connection failed.";
    exit;
}

// helper: absolute url builder for this script
function base_url_for_script() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    $dir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    $dir = $dir === '/' ? '' : $dir;
    return $scheme . '://' . $host . $dir;
}

// normalize token input (GET or POST)
$token = '';
if (!empty($_GET['token'])) $token = trim((string)$_GET['token']);
if ($token === '' && !empty($_POST['token'])) $token = trim((string)$_POST['token']);

// If token still missing: show helpful UI (paste token or instructions)
if ($token === '') {
    $home = htmlspecialchars(base_url_for_script() . '/');
    ?>
    <!doctype html>
    <html>
    <head>
      <meta charset="utf-8">
      <title>Missing resubmission token</title>
      <meta name="viewport" content="width=device-width,initial-scale=1">
      <style>
        body{font-family:Inter,system-ui,Arial,sans-serif;background:#f6f8fa;color:#111;margin:0;padding:24px;}
        .card{max-width:820px;margin:36px auto;padding:20px;background:#fff;border-radius:10px;box-shadow:0 6px 22px rgba(12,20,30,.06);}
        h1{margin:0 0 8px;font-size:20px}
        p{margin:8px 0 16px;color:#4b5563}
        input[type=text]{width:100%;padding:10px;border:1px solid #d1d5db;border-radius:6px;margin-bottom:8px}
        button{padding:10px 14px;border-radius:8px;border:none;background:#2563eb;color:#fff;cursor:pointer}
        a.small{color:#2563eb;text-decoration:none;margin-left:10px}
        .muted{color:#6b7280;font-size:13px}
      </style>
    </head>
    <body>
      <div class="card">
        <h1>Missing or invalid resubmission token</h1>
        <p class="muted">This page expects a secure link with a token. Example: <code><?= htmlspecialchars(base_url_for_script() . '/duplicateoperator.php?token=...') ?></code></p>

        <p>If you clicked a link in an email and it opened the site root (or directory listing), the link may have been truncated or the token omitted. Please copy the full link from the email and paste the token below (or ask the verifier to resend the resubmission link).</p>

        <form method="post" action="duplicateoperator.php" style="margin-top:12px;">
          <label for="token">Paste token (from the email URL)</label>
          <input id="token" name="token" type="text" placeholder="paste token here" required />
          <div style="display:flex;gap:8px;align-items:center">
            <button type="submit">Continue</button>
            <a class="small" href="<?= $home ?>">Back to portal home</a>
          </div>
        </form>

        <hr style="margin:16px 0;border:none;border-top:1px solid #eef2f7">
        <p class="muted">If you are an employee/resolver: use <code>create_resubmission.php</code> → copy token → send via mail. The token must be included in the link you email the operator.</p>
      </div>
    </body>
    </html>
    <?php
    exit;
}

// token provided: validate it and fetch request + operator
$reqStmt = $mysqli->prepare("SELECT id, operator_id, token, docs_json, expires_at, created_by, created_at FROM resubmission_requests WHERE token = ? LIMIT 1");
if (!$reqStmt) {
    echo "DB error (prepare).";
    exit;
}
$reqStmt->bind_param('s', $token);
$reqStmt->execute();
$reqRes = $reqStmt->get_result();
if (!$reqRes || $reqRes->num_rows === 0) {
    // token not found
    http_response_code(404);
    echo "<p style='font-family:Arial;padding:16px'>Resubmission token not found or invalid. Please verify the link or request a new resubmission token from the verifier.</p>";
    exit;
}
$reqRow = $reqRes->fetch_assoc();
$reqStmt->close();

// expiry check
if (!empty($reqRow['expires_at'])) {
    try {
        $now = new DateTime();
        $exp = new DateTime($reqRow['expires_at']);
        if ($now > $exp) {
            echo "<p style='font-family:Arial;padding:16px'><strong>This resubmission link has expired</strong> (expired at " . htmlspecialchars($reqRow['expires_at']) . "). Please contact support or request a new link.</p>";
            exit;
        }
    } catch (Exception $e) {
        // ignore parse errors and continue
    }
}

// fetch operator
$opId = (int)$reqRow['operator_id'];
$opStmt = $mysqli->prepare("SELECT * FROM operatordoc WHERE id = ? LIMIT 1");
if (!$opStmt) { echo "DB error (prepare op)."; exit; }
$opStmt->bind_param('i', $opId);
$opStmt->execute();
$opRes = $opStmt->get_result();
if (!$opRes || $opRes->num_rows === 0) {
    echo "<p style='font-family:Arial;padding:16px'>Operator record not found (ID: $opId). Please contact support.</p>";
    exit;
}
$op = $opRes->fetch_assoc();
$opStmt->close();

// parse requested docs (docs_json) — fallback to rejection_summary if empty
$requested_docs = [];
if (!empty($reqRow['docs_json'])) {
    $tmp = json_decode($reqRow['docs_json'], true);
    if (is_array($tmp)) $requested_docs = $tmp;
}

if (empty($requested_docs) && !empty($op['rejection_summary'])) {
    // try to parse labels out of rejection_summary for display (will keep raw lines)
    foreach (preg_split('/\r\n|\n|\r/', $op['rejection_summary']) as $line) {
        $line = trim($line);
        if ($line !== '') $requested_docs[] = $line;
    }
}

// human friendly label map (use same map as other scripts)
$label_map = [
    'aadhar_file'=>'Aadhaar Card','pan_file'=>'PAN Card','voter_file'=>'Voter ID',
    'ration_file'=>'Ration Card','consent_file'=>'Consent','gps_selfie_file'=>'GPS Selfie',
    'permanent_address_proof_file'=>'Permanent Address Proof','nseit_cert_file'=>'NSEIT Certificate',
    'self_declaration_file'=>'Self Declaration','non_disclosure_file'=>'Non-Disclosure Agreement',
    'police_verification_file'=>'Police Verification','parent_aadhar_file'=>"Parent's Aadhaar",
    'edu_10th_file'=>'10th Certificate','edu_12th_file'=>'12th Certificate','edu_college_file'=>'College Certificate'
];

// Build page
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
    .file-input{display:flex;gap:8px;align-items:center}
    input[type=file]{padding:6px}
    button.primary{background:#0ea5e9;border:none;color:white;padding:10px 12px;border-radius:8px;cursor:pointer}
    .small{background:#eef2ff;border:1px solid #dbeafe;padding:8px;border-radius:6px}
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
                // if $d matches a known doc key, render friendly label; otherwise treat as free-text line
                if (isset($label_map[$d])) {
                    echo '<li>' . htmlspecialchars($label_map[$d]) . '</li>';
                } else {
                    echo '<li>' . htmlspecialchars($d) . '</li>';
                }
            }
        } else {
            echo '<li>General re-upload requested. Please upload the corrected documents below.</li>';
        }
      ?>
    </ul>

    <h3 style="margin-top:12px">Upload corrected documents</h3>

    <?php
    // If server-side upload handler supports single file per submit, render one form per requested doc (simplest)
    // If requested_docs contains raw lines from rejection_summary (not keys), show a single general upload box.
    if (!empty($requested_docs) && isset($label_map[$requested_docs[0]])) {
        // these appear to be keys (render a form for each)
        foreach ($requested_docs as $docKey) {
            $label = htmlspecialchars($label_map[$docKey] ?? $docKey);
            ?>
            <div class="doc-row">
              <div style="flex:1"><strong><?= $label ?></strong></div>
              <div class="file-input">
                <form action="upload_doc.php" method="post" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:center">
                  <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                  <input type="hidden" name="id" value="<?= (int)$opId ?>">
                  <input type="hidden" name="doc_key" value="<?= htmlspecialchars($docKey) ?>">
                  <input type="file" name="file" required>
                  <button class="primary" type="submit">Upload</button>
                </form>
              </div>
            </div>
            <?php
        }
    } else {
        // free-text lines or fallback: single upload form with doc_key optional
        ?>
        <form action="upload_doc.php" method="post" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:center;margin-top:8px">
          <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
          <input type="hidden" name="id" value="<?= (int)$opId ?>">
          <label for="doc_key" class="muted">Document type (optional)</label>
          <select name="doc_key" id="doc_key">
            <option value="">Select (optional)</option>
            <?php foreach ($label_map as $k=>$v) echo '<option value="'.htmlspecialchars($k).'">'.htmlspecialchars($v).'</option>'; ?>
          </select>
          <input type="file" name="file" required>
          <button class="primary" type="submit">Upload</button>
        </form>
        <p class="note">You can upload multiple files one by one. If you'd like to upload many at once, please contact support.</p>
        <?php
    }
    ?>

    <hr style="margin:16px 0;border:none;border-top:1px solid #eef2f7">
    <p class="muted">If you have trouble uploading files, reply to the email you received or contact support at <a href="mailto:support@qit.com">support@qit.com</a>.</p>
  </div>
</body>
</html>
