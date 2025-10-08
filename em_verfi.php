<?php 
session_start();
// -----------------------------
// AUTH
// -----------------------------
if (!isset($_SESSION['employee_email'])) {
    header("Location: employee.php");
    exit;
}
// -----------------------------
// DB
// -----------------------------
$mysqli = new mysqli("localhost", "root", "", "qmit_system");
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}
// -----------------------------
// DOC_KEYS - single source of truth for dropdowns
// -----------------------------
$DOC_KEYS = [
  'aadhar_file' => 'Aadhaar',
  'pan_file' => 'PAN',
  'voter_file' => 'VoterID',
  'ration_file' => 'RationCard',
  'consent_file' => 'Consent',
  'gps_selfie_file' => 'GPS_Selfie',
  'police_verification_file' => 'PoliceVerification',
  'permanent_address_proof_file' => 'PermanentAddressProof',
  'parent_aadhar_file' => 'ParentAadhaar',
  'nseit_cert_file' => 'NSEIT_Cert',
  'self_declaration_file' => 'SelfDeclaration',
  'non_disclosure_file' => 'NDA',
  'edu_10th_file' => '10th',
  'edu_12th_file' => '12th',
  'edu_college_file' => 'CollegeCert'
];
// helper: build options html server-side once
$docOptionsHtml = '<option value="">Export…</option>';
$docOptionsHtml .= '<option value="all">Export All Docs (ZIP)</option>';
foreach ($DOC_KEYS as $k => $label) {
    $safeLabel = htmlspecialchars($label, ENT_QUOTES);
    $docOptionsHtml .= "<option value=\"{$k}\">{$safeLabel} Only</option>";
}
// -----------------------------
// params
// -----------------------------
$limit  = 5;
$page   = isset($_GET['page']) ? max(1,(int)$_GET['page']) : 1;
$search = isset($_GET['search']) ? $mysqli->real_escape_string($_GET['search']) : '';
$offset = ($page - 1) * $limit;
// New: bank filter param (accept either 'bank' or 'bank_name' for backward compatibility)
$bank = '';
if (isset($_GET['bank_name'])) {
    $bank = $mysqli->real_escape_string($_GET['bank_name']);
} elseif (isset($_GET['bank'])) {
    $bank = $mysqli->real_escape_string($_GET['bank']);
}
$filter = isset($_GET['filter']) ? $mysqli->real_escape_string($_GET['filter']) : '';
$whereClauses = [];
if ($search !== '') {
    $s = $mysqli->real_escape_string($search);
    $whereClauses[] = "(operator_full_name LIKE '%$s%' OR email LIKE '%$s%' OR operator_id LIKE '%$s%')";
}
if ($filter !== '') {
    if (in_array($filter, ['pending','accepted','rejected'])) {
        $whereClauses[] = "status = '" . $mysqli->real_escape_string($filter) . "'";
    } elseif (in_array($filter, ['working','not working'])) {
        $whereClauses[] = "work_status = '" . $mysqli->real_escape_string($filter) . "'";
    }
}
// New: apply bank filter if provided (exact match) — use DB column bank_name
if ($bank !== '') {
    $whereClauses[] = "bank_name = '" . $mysqli->real_escape_string($bank) . "'";
}
$where = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';


// -----------------------------
// AJAX fragment endpoint (table only)
// -----------------------------
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    $countRes = $mysqli->query("SELECT COUNT(*) as total FROM operatordoc $where");
    $total_rows = ($countRes ? ($countRes->fetch_assoc()['total'] ?? 0) : 0);
    $total_pages = max(1, ceil($total_rows/$limit));
    $offset = (isset($_GET['page']) ? (max(1,(int)$_GET['page'])-1)*$limit : 0);
    $sql = "SELECT * FROM operatordoc $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset";
    $res = $mysqli->query($sql);
    ?>
    <div class="k-card table-fragment">
      <div class="table-actions">
        <div class="left"><span class="muted">Showing <?= (int)$total_rows ?> rows</span></div>
        <div class="right">
          <a class="sidebar-export fragment-export" href="em_verfi.php?export=1<?= $search ? '&search='.urlencode($search) : '' ?><?= $filter ? '&filter='.urlencode($filter) : '' ?><?= $bank ? '&bank='.urlencode($bank) : '' ?>">Export CSV</a>

          <!-- BULK EXPORT (AJAX fragment) -->
          <div id="bulkExportWrapFrag">
            <select id="bulkExportSelectFrag" class="form-select">
              <?= $docOptionsHtml ?>
            </select>
            <button id="bulkExportBtnFrag" type="button" class="small-btn">Export Do</button>
          </div>
        </div>
      </div>

      <div class="table-wrap auto-scroll-wrap">
        <div class="table-scroll-inner">
          <table class="data-table excel-like">
            <thead>
              <tr>
                <?php
                  $colsRes = $mysqli->query("SELECT * FROM operatordoc LIMIT 1");
                  $colNames = [];
                  if ($colsRes && $colsRes->num_rows) {
                      $fields = $colsRes->fetch_fields();
                      foreach ($fields as $f) { $colNames[] = $f->name; echo "<th>".htmlspecialchars($f->name)."</th>"; }
                  }
                ?>
                <th>Review</th>
                <th>Work</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php
              if ($res && $res->num_rows > 0) {
                  while ($row = $res->fetch_assoc()) {
                      $id = (int)$row['id'];
                      echo "<tr id='row-{$id}'>";
                      foreach ($colNames as $col) {
                          $val = $row[$col] ?? '';
                          if (str_ends_with($col, '_file') && $val) $display = '<a href="'.htmlspecialchars($val).'" target="_blank" class="file-link">View</a>';
                          else $display = htmlspecialchars((string)$val);
                          echo "<td data-col=\"" . htmlspecialchars($col, ENT_QUOTES) . "\">{$display}</td>";
                      }
                      $rv = htmlspecialchars($row['review_notes'] ?? '', ENT_QUOTES);
                      echo "<td><input id='review-{$id}' value=\"{$rv}\" class='input-compact' /><button class='small-btn' onclick='saveReview({$id})'>Save</button></td>";
                      $ws = htmlspecialchars($row['work_status'] ?? 'working', ENT_QUOTES);
                      echo "<td><div id='work-{$id}' class='nowrap'><strong>{$ws}</strong></div><div class='btn-row'><button class='small-btn' onclick=\"setWork({$id},'working')\">Working</button><button class='small-btn' onclick=\"setWork({$id},'not working')\">Not Working</button></div></td>";

                      // Added quick resubmit button + per-row export UI
                      echo "<td class='col-actions nowrap'>";
                      echo "<button class='small-btn' onclick=\"updateStatus({$id},'accepted')\">Accept</button>";
                      echo "<button class='small-btn' onclick=\"updateStatus({$id},'pending')\">Pending</button>";
                      echo "<button class='small-btn' onclick=\"updateStatus({$id},'rejected')\">Reject</button>";
                      echo "<button class='small-btn' onclick=\"openResubmitModal({$id})\">Resubmit</button>";
                      echo "<button class='small-btn' onclick=\"makeRowEditable({$id})\">Edit</button>";
                      echo "<button class='small-btn' onclick=\"saveRow({$id})\">Save Row</button>";

                      // Per-row export dropdown + button
                      echo "<div class='row-export'>";
                      echo "<select class='rowExportSelect form-select' data-id='{$id}'>{$docOptionsHtml}</select>";
                      echo "<button class='rowExportBtn small-btn' data-id='{$id}'>Export</button>";
                      echo "</div>";

                      echo "</td>";
                      echo "</tr>";
                  }
              } else {
                  echo "<tr><td colspan='200' class='center'>No records found</td></tr>";
              }
              ?>
            </tbody>
          </table>
<!-- Server rendered pagination (preserve search/filter/bank) -->
<div class="fragment-pagination">
<?php
// Build base query string preserving search/filter/bank
$baseQs = [];
if ($search) $baseQs[] = 'search=' . urlencode($search);
if ($filter) $baseQs[] = 'filter=' . urlencode($filter);
if ($bank)   $baseQs[] = 'bank='   . urlencode($bank);
$base = $baseQs ? ('?' . implode('&', $baseQs) . '&') : '?';
for ($p = 1; $p <= $total_pages; $p++):
  $active = $p === $page ? ' aria-current="page" class="page-active"' : '';
?>
  <a href="<?= htmlspecialchars($base) ?>page=<?= $p ?>" class="overview-page-server" data-page="<?= $p ?>"<?= $active ?>><?= $p ?></a>
<?php endfor; ?>
</div>
     <div class="fragment-pagination">
<?php for ($p = 1; $p <= $total_pages; $p++): ?>
  <a href="#" class="overview-page" data-page="<?= $p ?>" data-filter="<?= htmlspecialchars($filter, ENT_QUOTES) ?>" data-current-page="<?= (int)$page ?>"><?= $p ?></a>
<?php endfor; ?>
</div>
  </div>
    <script src="em_verfi.js"></script>
    <?php
    exit;
}
// -----------------------------
// Export CSV
// -----------------------------
if (isset($_GET['export']) && $_GET['export'] === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=operator_details.csv');
    $out = fopen('php://output', 'w');
    $colsRes = $mysqli->query("SELECT * FROM operatordoc $where LIMIT 1");
    if ($colsRes && $colsRes->num_rows) {
        $headers = array_keys($colsRes->fetch_assoc());
        fputcsv($out, $headers);
    }
    $allRes = $mysqli->query("SELECT * FROM operatordoc $where ORDER BY created_at DESC");
    if ($allRes && $allRes->num_rows) {
        while ($r = $allRes->fetch_assoc()) {
            fputcsv($out, $r);
        }
    }
    fclose($out);
    exit;
}

// -----------------------------
// Counts
// -----------------------------
$employeeMailCount      = (int)$mysqli->query("SELECT COUNT(*) AS total FROM employees")->fetch_assoc()['total'] ?? 0;
$operatorPendingCount   = (int)$mysqli->query("SELECT COUNT(*) AS total FROM operatordoc WHERE status='pending'")->fetch_assoc()['total'] ?? 0;
$operatorFilledCount    = (int)$mysqli->query("SELECT COUNT(*) AS total FROM operatordoc WHERE status='accepted'")->fetch_assoc()['total'] ?? 0;
// Broaden rejected/non count to include common variants
// Working count (now directly uses 'working')
$operatorWorkingCount = (int)$mysqli
  ->query("SELECT COUNT(*) AS total FROM operatordoc WHERE status='working'")
  ->fetch_assoc()['total'] ?? 0;

// Count operator as working if work_status='working' OR status indicates accepted/verified
$operatorWorkingCount = (int)$mysqli
  ->query("SELECT COUNT(*) AS total FROM operatordoc WHERE work_status='working' OR status IN ('accepted','verified','em_verified','ph_verified')")->fetch_assoc()['total'] ?? 0;
$operatorNotWorkingCount= (int)$mysqli->query("SELECT COUNT(*) AS total FROM operatordoc WHERE work_status='not working'")->fetch_assoc()['total'] ?? 0;

$total_result = $mysqli->query("SELECT COUNT(*) as total FROM operatordoc $where")->fetch_assoc();
$total_rows   = (int)($total_result['total'] ?? 0);
$total_pages  = max(1, ceil($total_rows / $limit));

$sql_main = "SELECT * FROM operatordoc $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset";
$result = $mysqli->query($sql_main);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>Employee KYC Portal</title>
  <link rel="stylesheet" href="emverfi.css?v=<?=file_exists('emverfi.css') ? filemtime('emverfi.css') : time()?>" type="text/css">
  
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://unpkg.com/feather-icons"></script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@200..800&display=swap" rel="stylesheet">
  <!-- Chart.js + datalabels -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>

</head>

<body>
  <header class="qit-header">
    <div class="container">
      <div class="logo">
        <div class="logo-grid"><span class="sq1"></span><span class="sq2"></span><span class="sq3"></span><span class="sq4"></span></div>
        <div class="logo-text"><span class="big-q">Q</span>
        <span class="small-it"> IT</span></div>
      </div>

    <div class="header-search">
        <input id="topSearch" type="search" placeholder="Search operator..." value="<?= htmlspecialchars($search) ?>">
        <button id="topSearchBtn" class="gold-btn">Search</button>

        <div class="filter-bar">
          <label for="bankFilter" class="filter-label">Bank:</label>
          <select id="bankFilter" name="bank" class="qit-select">
            <option value="" class="sas" <?= $bank === '' ? 'selected' : '' ?>>All Banks</option>
            <option value="Karur Vysya Bank" <?= $bank === 'Karur Vysya Bank' ? 'selected' : '' ?>>Karur Vysya Bank</option>
            <option value="City Union Bank" <?= $bank === 'City Union Bank' ? 'selected' : '' ?>>City Union Bank</option>
            <option value="Tamilnad Mercantile Bank" <?= $bank === 'Tamilnad Mercantile Bank' ? 'selected' : '' ?>>Tamilnad Mercantile Bank</option>
            <option value="Indian Bank" <?= $bank === 'Indian Bank' ? 'selected' : '' ?>>Indian Bank</option>
            <option value="Karnataka Bank" <?= $bank === 'Karnataka Bank' ? 'selected' : '' ?>>Karnataka Bank</option>
            <option value="Equitas Small Finance Bank" <?= $bank === 'Equitas Small Finance Bank' ? 'selected' : '' ?>>Equitas Small Finance Bank</option>
            <option value="Union Bank Of India" <?= $bank === 'Union Bank Of India' ? 'selected' : '' ?>>Union Bank Of India</option>
          </select>
        </div>

        <a id="topExport" class="sidebar-export gold-export" href="em_verfi.php?export=1<?= $search ? '&search='.urlencode($search) : '' ?><?= $filter ? '&filter='.urlencode($filter) : '' ?><?= $bank ? '&bank='.urlencode($bank) : '' ?>">Export CSV</a>

        <!-- BULK EXPORT (TOP-RIGHT) -->
        <div id="bulkExportWrap">
          <select id="bulkExportSelect" class="form-select">
            <?= $docOptionsHtml ?>
          </select>
          <button id="bulkExportBtn" type="button" class="small-btn gold-export">Export Docs</button>
        </div>
      </div>

      
    </div>
  </header>


  <div class="k-shell">
    <aside id="resizableSidebar">
      <div class="k-card sidebar-inner">
        <div class="k-card-title">Operator</div>
        <nav class="sidebar-nav">
          <a href="#" data-section="operatorStatusSection" class="is-active"><span class="nav-label">Operator Status</span><span class="nav-icon"></span></a>
          <a href="#" data-section="operatorOverviewSection" data-filter=""><span class="nav-label">OP DATA (All)</span><span class="nav-icon"></span></a>
          <div class="k-subtitle">Working</div>
          <a href="#" data-section="operatorOverviewSection" data-filter="working"><span class="nav-label" id="sss">Working</span><span class="nav-icon"></span><span class="badge"><?= $operatorWorkingCount ?></span></a>
          <a href="#" data-section="operatorOverviewSection" data-filter="pending"><span class="nav-label">Pending</span><span class="nav-icon"></span><span class="badge"><?= $operatorPendingCount ?></span></a>
          <a href="#" data-section="operatorOverviewSection" data-filter="accepted"><span class="nav-label">Accepted</span><span class="nav-icon"></span><span class="badge"><?= $operatorFilledCount ?></span></a>
          <div class="k-subtitle">Not Working</div>
          <a href="#" data-section="operatorOverviewSection" data-filter="not working"><span class="nav-label">Not Working</span><span class="nav-icon"></span><span class="badge"><?= $operatorNotWorkingCount ?></span></a>
          <div class="k-sb-footer">
            <a href="#" data-section="operatorMailingSection"><span class="nav-label">Operator Mailing</span><span class="nav-icon"></span></a>
          </div>
        </nav>
      </div>
      <!-- circular emoji collapse/expand button -->
    </aside>
    <main>
      <div class="k-container">
        <div class="k-page-head">
          <div class="k-page-title"><h1 class="gold-title">KYC Dashboard</h1><div class="k-subtitle">&nbsp;</div></div>
        </div>
        <!-- Status cards -->
        <section id="operatorStatusSection" class="k-section">
          <div class="status-row">
            <div class="k-card status-card dash-colored dash-green">
              <div class="dash-icon"></div>
              <div class="k-value"><?= $employeeMailCount ?></div>
              <div class="k-card-sub">Employee Send Mail</div>
            </div>
            <div class="k-card status-card dash-colored dash-orange">
              <div class="dash-icon"></div>
              <div class="k-value"><?= $operatorPendingCount ?></div>
              <div class="k-card-sub">Pending</div>
            </div>
            <div class="k-card status-card dash-colored dash-blue">
              <div class="dash-icon"></div>
              <div class="k-value"><?= $operatorFilledCount ?></div>
              <div class="k-card-sub">Accepted</div>
            </div>
            <div class="k-card status-card dash-colored dash-red">
              <div class="dash-icon"></div>
              <div class="k-value"><?= $operatorWorkingCount ?></div>
              <div class="k-card-sub">Working</div>
            </div>
          </div>
          <!-- NEW layout: left = clock+counts, center = calendar, right = donut -->
          <div class="status-main-row">
            <div class="k-card status-left">
              <div class="clock-card fancy-clock">
                <div id="statusClock" class="clock-large" aria-live="polite"></div>
                <div class="clock-ring"></div>
                <div class="clock-meta" id="statusClockMeta"></div>
              </div>
            </div>
            <div class="k-card status-center">
              <div class="chart-head"></div>
              <div class="calendar-wrap animated-calendar">
                <input id="statusDate" type="date" aria-label="Pick date">
                <div id="statusDateDisplay" class="date-display"> <span class="sel-date">—</span></div>
                <div class="calendar-legend">
                  <span class="dot dot-accent"></span><small>Event</small>
                  <span class="dot dot-green"></span><small>Working</small>
                </div>
              </div>
            </div>
            <div class="k-card status-right">
              <div class="chart-head">Operator status distribution</div>
              <div class="chart-wrap"><canvas id="statusDonut"></canvas></div>
            </div>
          </div>
        </section>

        <!-- Overview (OP DATA) - no email chart; table loads into placeholder -->
        <section id="operatorOverviewSection" class="k-section hidden">
          <div id="overviewTablePlaceholder"></div>
        </section>
        <!-- Mailing form -->
        <section id="operatorMailingSection" class="k-section hidden">
          <div class="signup mail-form">
            <form method="post" action="employee.php">
              <label for="chk" aria-hidden="true">Employee → Operator Mail</label>
              <div class="mail-row"><input type="text" name="employee_name" placeholder="Employee Name" required><input type="email" name="employee_email" placeholder="Employee Email" required></div>
              <div class="mail-row"><input type="email" name="operator_email" placeholder="Operator Email" required><input type="text" name="aadhaar_id" placeholder="Aadhaar ID" required></div>
              <div class="mail-row"><input type="text" name="unique_id" placeholder="EMPLOYEE ID" required><input type="text" name="mobile_number" placeholder="Mobile Number" required></div>
              <button class="btn-effect3" type="submit">Submit</button>
            </form>
          </div>
        </section>

<!-- Paste inside your header .logo element -->
<!-- MAIN LOGO (inline SVG) -->
<span class="qit-logo" aria-hidden="true">
  <svg width="120" height="36" viewBox="0 0 320 96" xmlns="http://www.w3.org/2000/svg" role="img">
    <!-- squares grid -->
    <g transform="translate(12,12)">
      <rect x="0" y="0" width="18" height="18" rx="4" fill="#3b82f6"/>
      <rect x="22" y="0" width="18" height="18" rx="4" fill="#6c63ff"/>
      <rect x="0" y="22" width="18" height="18" rx="4" fill="#10b981"/>
      <rect x="22" y="22" width="18" height="18" rx="4" fill="#f59e0b"/>
    </g>

    <!-- text -->
    <g transform="translate(72,22)">
      <text x="0" y="6" font-family="Vidaloka, serif" font-size="34" fill="var(--gold)" font-weight="700">Q</text>
      <text x="38" y="24" font-family="Inter, sans-serif" font-size="18" fill="var(--black)" font-weight="700">IT</text>
    </g>
  </svg>
</span>

<!-- ICONS: small set (inline) - copy these into header or wherever you need them -->
<!-- Search Icon -->
<svg class="icon icon-search" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
  <circle cx="11" cy="11" r="6"></circle>
  <path d="m21 21-4.35-4.35"></path>
</svg>

<!-- Export Icon -->
<svg class="icon icon-export" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.6">
  <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
  <polyline points="7 10 12 5 17 10"/>
  <line x1="12" y1="5" x2="12" y2="19"/>
</svg>

<!-- Accept / Reject small badges -->
<svg class="icon icon-check" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
  <path d="M20 6L9 17l-5-5"></path>
</svg>
<svg class="icon icon-x" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
  <path d="M18 6L6 18M6 6l12 12"></path>
</svg>

        <!-- Server-rendered main table (initial fallback) -->
        <section id="employeeTable" class="k-section">
          <div class="table-wrap auto-scroll-wrap">
            <div class="table-scroll-inner">
              <table class="data-table excel-like">
                <thead>
                  <tr>
                    <?php
                      $cols = $result ? $result->fetch_fields() : [];
                      $colNames = [];
                      foreach ($cols as $c) { $colNames[] = $c->name; echo "<th>" . htmlspecialchars($c->name) . "</th>"; }
                    ?>
                    <th>Review</th>
                    <th>Work</th>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                  if ($result && $result->num_rows > 0):
                    $result->data_seek(0);
                    while ($row = $result->fetch_assoc()):
                      $id = (int)$row['id'];
                      echo "<tr id='row-{$id}'>";
                      foreach ($colNames as $col) {
                          $val = $row[$col] ?? '';
                          $display = str_ends_with($col, '_file') && $val ? '<a href="'.htmlspecialchars($val).'" target="_blank" class="file-link">View</a>' : htmlspecialchars((string)$val);
                          echo "<td data-col=\"" . htmlspecialchars($col, ENT_QUOTES) . "\">{$display}</td>";
                      }
                      $rv = htmlspecialchars($row['review_notes'] ?? '', ENT_QUOTES);
                      echo "<td><input id='review-{$id}' value=\"{$rv}\" class='input-compact' /><button class='small-btn' onclick='saveReview({$id})'>Save</button></td>";
                      $ws = htmlspecialchars($row['work_status'] ?? 'working', ENT_QUOTES);
                      echo "<td><div id='work-{$id}' class='nowrap'><strong>{$ws}</strong></div><div class='btn-row'><button class='small-btn' onclick=\"setWork({$id},'working')\">Working</button><button class='small-btn' onclick=\"setWork({$id},'not working')\">Not Working</button></div></td>";

                      // Added Resubmit quick button in server-rendered table as well
                      echo "<td class='col-actions nowrap'>";
                      echo "<button class='small-btn' onclick=\"updateStatus({$id},'accepted')\">Accept</button>";
                      echo "<button class='small-btn' onclick=\"updateStatus({$id},'pending')\">Pending</button>";
                      echo "<button class='small-btn' onclick=\"openResubmitModal({$id})\">Resubmit</button>";
                      echo "<button class='small-btn' onclick=\"makeRowEditable({$id})\">Edit</button>";
                      echo "<button class='small-btn' onclick=\"saveRow({$id})\">Save Row</button>";

                      // Per-row export dropdown + button
                      echo "<div class='row-export'>";
                      echo "<select class='rowExportSelect form-select' data-id='{$id}'>{$docOptionsHtml}</select>";
                      echo "<button class='rowExportBtn small-btn' data-id='{$id}'>Export</button>";
                      echo "</div>";

                      echo "</td>";
                      echo "</tr>";
                    endwhile;
                  else:
                    echo "<tr><td colspan='200' class='center'>No records found</td></tr>";
                  endif;
                  ?>
                </tbody>
              </table>
            </div>
          </div>
        </section>
      </div>
    </main>
  </div>

  <script>
  // Server-side data exposed to JS
  window._ev = {
    operatorFilledCount: <?= isset($operatorFilledCount) ? (int)$operatorFilledCount : 0 ?>,
    operatorPendingCount: <?= isset($operatorPendingCount) ? (int)$operatorPendingCount : 0 ?>,
    // 👉 add more PHP variables here if your JS needs them
  };
  </script>
  <script src="js/em_verfi.js"></script>
</body>
</html>
