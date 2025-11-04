<?php
require_once "db_conn.php";

function norm_state(string $s): string
{
  $s = strtolower(trim($s));
  $map = [
    'accept' => 'accepted',
    'accepted' => 'accepted',
    'pending' => 'pending',
    'receive' => 'received',
    'received' => 'received',
    'replace' => 'replacement',
    'replaced' => 'replacement',
    'replacement' => 'replacement',
    'not_received' => 'not-received',
    'not-received' => 'not-received',
  ];
  return $map[$s] ?? 'received';
}

/**
 * STRICT: read only from operatordoc (no joins).
 * Accepts:
 *   - ?operator_id=OP206 / VEE...
 *   - ?operator_id=206  → tries OP206, else operatordoc.id=206
 */
// --- accept form-encoded or JSON body ---
$input = $_POST;
if (empty($input)) {
  $raw = file_get_contents('php://input');
  if ($raw) {
    $json = json_decode($raw, true);
    if (is_array($json)) $input = $json;
  }
}

$raw   = isset($_GET['operator_id']) ? trim((string)$_GET['operator_id']) : '';
$debug = isset($_GET['debug']) ? (int)$_GET['debug'] : 0;

if ($raw === '') {
  http_response_code(400);
  die("Invalid access — missing operator_id.");
}

function fetch_doc_exact(mysqli $c, string $opid): ?array
{
  $s = $c->prepare("SELECT * FROM operatordoc WHERE TRIM(operator_id)=? LIMIT 1");
  $s->bind_param("s", $opid);
  $s->execute();
  $r = $s->get_result();
  return $r->fetch_assoc() ?: null;
}
function fetch_doc_lenient(mysqli $c, string $opid): ?array
{
  $s = $c->prepare("SELECT * FROM operatordoc WHERE REPLACE(LOWER(operator_id),' ','')=REPLACE(LOWER(?),' ','') LIMIT 1");
  $s->bind_param("s", $opid);
  $s->execute();
  $r = $s->get_result();
  return $r->fetch_assoc() ?: null;
}
function fetch_doc_by_id(mysqli $c, int $id): ?array
{
  $s = $c->prepare("SELECT * FROM operatordoc WHERE id=? LIMIT 1");
  $s->bind_param("i", $id);
  $s->execute();
  $r = $s->get_result();
  return $r->fetch_assoc() ?: null;
}

$op = fetch_doc_exact($conn, $raw);
$trace = 'exact-opid';
if (!$op && ctype_digit($raw)) {
  $op = fetch_doc_exact($conn, 'OP' . $raw);
  $trace = 'try-OP-num';
  if (!$op) {
    $op = fetch_doc_by_id($conn, (int)$raw);
    $trace = 'id-num';
  }
}
if (!$op) {
  $op = fetch_doc_lenient($conn, $raw);
  $trace = 'lenient';
}
if (!$op && ctype_digit($raw)) {
  $op = fetch_doc_lenient($conn, 'OP' . $raw);
  $trace = 'lenient-OP-num';
}

if ($debug) {
  header('Content-Type: text/plain');
  echo "resolver=" . $trace . "\nfound=" . ($op ? 'yes' : 'no') . "\n";
  if (!$op) exit;
}
if (!$op) {
  http_response_code(404);
  die("Operator not found in operatordoc for: " . htmlspecialchars($raw));
}
/* -------- NEW: pull per-document states from operator_documents -------- */
$stateMap = [];
$countsFromOD = ['accepted' => 0, 'pending' => 0, 'received' => 0, 'not-received' => 0, 'replacement' => 0];

$st = $conn->prepare("SELECT doc_key, state FROM operator_documents WHERE operator_id = ?");
$st->bind_param('s', $op['operator_id']);
$st->execute();
$rs = $st->get_result();
while ($row = $rs->fetch_assoc()) {
  $s = norm_state((string)$row['state']);
  $stateMap[$row['doc_key']] = $s;
  if (isset($countsFromOD[$s])) $countsFromOD[$s]++;           // normalized counts
}
$st->close();

/* expose to JS if you still want boot painting support */
$stateMapJson = json_encode($stateMap, JSON_UNESCAPED_UNICODE);

/** Map: label => column */
$DOCS = [
  "Aadhaar Card"                => "aadhar_file",
  "PAN Card"                    => "pan_file",
  "Voter ID"                    => "voter_file",
  "Ration Card"                 => "ration_file",
  "Consent Form"                => "consent_file",
  "GPS Selfie"                  => "gps_selfie_file",
  "Permanent Address Proof"     => "permanent_address_proof_file",
  "NSEIT Certificate"           => "nseit_cert_file",
  "Self Declaration"            => "self_declaration_file",
  "Non-Disclosure"              => "non_disclosure_file",
  "Police Verification"         => "police_verification_file",
  "Parent Aadhaar"              => "parent_aadhar_file",
  "10th Marksheet"              => "edu_10th_file",
  "12th Marksheet"              => "edu_12th_file",
  "College Certificate"         => "edu_college_file"
];

/* -------- counts: received / not_received -------- */
$received = 0;
$not_received = 0;
foreach ($DOCS as $col) {
  if (!empty($op[$col])) $received++;
  else $not_received++;
}

/* -------- counts: accepted / pending from doc_counts OR fallback to json -------- */
$counts = ['accepted' => 0, 'pending' => 0, 'received' => $received, 'notReceived' => $not_received];

$qc = $conn->prepare("SELECT accepted,pending,received,not_received FROM doc_counts WHERE operator_id=? LIMIT 1");
$qc->bind_param('s', $op['operator_id']);
$qc->execute();
$cr = $qc->get_result()->fetch_assoc();
$qc->close();

if ($cr) {
  $counts['accepted']    = (int)$cr['accepted'];
  $counts['pending']     = (int)$cr['pending'];
  $counts['received']    = (int)$cr['received'];
  $counts['notReceived'] = (int)$cr['not_received'];
} else {
  $map = json_decode($op['doc_status_json'] ?? '{}', true) ?: [];
  foreach ($map as $st) {
    $v = strtolower((string)$st);
    if ($v === 'accepted' || $v === 'accept') $counts['accepted']++;
    if ($v === 'pending') $counts['pending']++;
  }
}

function h($s)
{
  return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" />

  <title>DOC & STATUS</title> <noscript>
    <style>
      header nav ul li {
        opacity: 1;
        transform: none;
        pointer-events: auto;
      }
    </style>
  </noscript>
  <link rel="icon" type="image/png" href="qit_logo.png">
  <link rel="shortcut icon" href="favicon.png" type="image/x-icon">
  <link href="https://fonts.googleapis.com/css2?family=Vidaloka&family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="document_status.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>

<body>

  <!-- 3s animated loader -->
  <div id="loader" class="loading-container" style="display:flex">
    <div class="loading-text">
      <span>S</span><span>Y</span><span>N</span><span>C</span><span>I</span><span>N</span><span>G</span><span>.</span><span>.</span><span>.</span><span>.</span>
    </div>
  </div>
  </div>

  <!-- Swapped grid: left=sidebar(30), right=overall(70) -->
  <main id="main-content" class="ds-grid swapped" style="display:none;">
    <!-- LEFT • DOCUMENT & STATUS -->
    <aside class="ds-left sidebar">
      <h1 class="gradient-title">DOCUMENT &amp; STATUS</h1>

      <div class="operator-meta">
        <div class="stack">
          <div><b>Name</b><br><?= h($op['operator_full_name']) ?></div>
          <div><b>Operator ID</b><br><?= h($op['operator_id']) ?></div>
          <div><b>Branch</b><br><?= h($op['branch_name']) ?></div>
        </div>

        <div class="status-block">
          <div>
            <b>Status</b><br>
            <span id="overallStatusChip" class="status-chip"><?= h($op['status'] ?: '—') ?></span>
          </div>
          <div class="vline"></div>
          <div>
            <b>Work</b><br>
            <span id="workStatusChip" class="status-chip"><?= h($op['work_status'] ?: '—') ?></span>
          </div>
        </div>
      </div>

      <div class="sidebar-divider"></div>

      <div class="doc-list">
        <h3>Received</h3>
        <div id="docReceived" class="reveal-ltr">
          <?php
          $map = json_decode($op['doc_status_json'] ?? '{}', true) ?: [];
          foreach ($DOCS as $label => $col):
            $file = $op[$col] ?? '';
            if (!$file) continue;

            // NEW: prefer operator_documents; fallback to your old json
            $rawState = $stateMap[$col] ?? (isset($map[$col]) ? strtolower((string)$map[$col]) : 'received');
            $state = norm_state($rawState); // 'accepted' | 'pending' | 'received' | 'replacement' | 'not-received'
          ?>
            <div class="doc-item received <?= $state ?>"
              data-key="<?= h($col) ?>"
              data-label="<?= h($label) ?>"
              data-file="<?= h($file) ?>"
              data-state="<?= h($state) ?>">
              <span class="doc-label"><?= h($label) ?></span>
              <div class="icons">
                <a href="#" class="ico i-pending" title="Mark Pending">🕓</a>
                <a href="#" class="ico i-accept" title="Mark Accepted">✅</a>
                <a class="ico i-download" title="Download"
                  href="download.php?id=<?= (int)$op['id'] ?>&operator_id=<?= urlencode($op['operator_id']) ?>&doc_key=<?= urlencode($col) ?>">⬇️</a>
                <a href="#" class="ico i-replace" title="Replace">🔁</a>
              </div>
            </div>
          <?php endforeach; ?>

        </div>

        <div class="ds-sep"></div>

        <h3>Not Received</h3>
        <div id="docNotReceived" class="reveal-ltr slow">
          <?php foreach ($DOCS as $label => $col):
            $file = $op[$col] ?? '';
            if ($file) continue; ?>
            <div class="doc-item not-received none" data-key="<?= h($col) ?>"
              data-label="<?= h($label) ?>" data-state="none">
              <span class="doc-label"><?= h($label) ?></span>
              <div class="icons"><a href="#" class="ico i-upload" title="Upload">📤</a></div>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="ds-sep"></div>

        <h3>Resubmission</h3>
        <div id="docResub"></div>
      </div>
    </aside>

    <!-- RIGHT • OVERALL -->
    <section class="ds-right">
      <div class="panel-header fade-in">
        <h2 class="panel-title gold">OVERALL</h2>
        <p class="panel-subcopy">Pending/Accept/Working/Not Working actions will reflect live here and in the donut.</p>
      </div>

      <div class="action-grid">
        <button class="action-btn" data-status="pending">Pending</button>
        <button class="action-btn" data-status="accepted">Accept</button>
        <button class="action-btn" data-work="working">Working</button>
        <button class="action-btn" data-work="not working">Not Working</button>

      </div>

      <div class="review-box">
        <label for="reviewNotes" class="gold"><b>Review Notes</b></label>
        <div class="review-inline">
          <textarea id="reviewNotes" class="review-ff"><?= h($op['review_notes']) ?></textarea>
          <button id="saveReview" class="action-btn tight gold-btn">Save Review</button>
          <span id="reviewSaved" class="tick-msg">Saved ✓</span>
        </div>
      </div>

      <div class="counter-section uncluttered">
        <div class="counter-card">
          <p>Pending</p>
          <h3 id="cPending"><?= (int)$counts['pending'] ?></h3>
        </div>
        <div class="counter-card">
          <p>Accepted</p>
          <h3 id="cAccepted"><?= (int)$counts['accepted'] ?></h3>
        </div>
        <div class="counter-card">
          <p class="gold">Received</p>
          <h3 id="cReceived" class="gold"><?= (int)$counts['received'] ?></h3>
        </div>
        <div class="counter-card">
          <p>Not Received</p>
          <h3 id="cNotReceived"><?= (int)$counts['notReceived'] ?></h3>
        </div>
        <div style="text-align:right;margin-top:6px;">
          <button id="saveAllDocs" class="action-btn tight gold-btn">💾 Save All</button>
        </div>
      </div>

      <div class="chart-preview">
        <div class="preview-box" id="previewBox">
          <h4 id="previewLabel" class="muted">Select a Document</h4>
          <div id="previewContent" class="prev-body">No document selected.</div>
        </div>
        <div class="chart-area"><canvas id="statusChart"></canvas></div>
      </div>
    </section>
  </main>

  <script>
    // bootstrap vars for JS — MUST be defined before document_status.js
    window.OPERATOR_ID = <?= json_encode($op['operator_id']) ?>;
    window.OP_ROW_ID = <?= (int)$op['id'] ?>;
    window.BOOT_STATUS = <?= json_encode($op['status'] ?: '') ?>;
    window.BOOT_WORK = <?= json_encode($op['work_status'] ?: '') ?>;

    // counts + json map for boot painting
    // counts + normalized map for boot painting (now from operator_documents)
    window.DOC_COUNTS = <?= json_encode($counts, JSON_UNESCAPED_UNICODE) ?>;
    window.DOC_STATUS_MAP = <?= $stateMapJson ?: '{}' ?>;
  </script>
  <script src="document_status.js"></script>
</body>

</html>