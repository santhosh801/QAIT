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
            <div id="bulkExportWrapFrag" style="display:inline-block; margin-left:12px; vertical-align:middle;">
              <select id="bulkExportSelectFrag" class="form-select" style="min-width:220px;">
                <?= $docOptionsHtml ?>
              </select>
              <button id="bulkExportBtnFrag" type="button" class="small-btn" style="margin-left:6px;">Export Do</button>
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
                        echo "<div class='row-export' style='display:inline-block; margin-left:8px; margin-top:6px;'>";
                        echo "<select class='rowExportSelect form-select' data-id='{$id}' style='min-width:160px;'>{$docOptionsHtml}</select>";
                        echo "<button class='rowExportBtn small-btn' data-id='{$id}' style='margin-left:6px;'>Export</button>";
                        echo "</div>";

                        echo "</td>";
                        echo "</tr>";
                    }
                } else {
                    echo "<tr><td colspan='200' style='padding:10px;text-align:center'>No records found</td></tr>";
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
      <script>/* fragment injected */</script>
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

      <div class="header-search" style="display:flex;align-items:center;gap:20px; width:55%; margin-left:250px;">
          <input id="topSearch" type="search" placeholder="Search operator..." value="<?= htmlspecialchars($search) ?>">
          <button id="topSearchBtn" class="gold-btn">Search</button>

          <div class="filter-bar" style="display:flex;align-items:center;gap:10px;">
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
          <div id="bulkExportWrap" style="display:inline-block; margin-left:12px; vertical-align:middle;">
            <select id="bulkExportSelect" class="form-select" style="min-width:220px;">
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
                <div class="chart-wrap" style="min-height:220px"><canvas id="statusDonut"></canvas></div>
              </div>
            </div>
          </section>

          <!-- Overview (OP DATA) - no email chart; table loads into placeholder -->
          <section id="operatorOverviewSection" class="k-section hidden">
            <div id="overviewTablePlaceholder" style="margin-top:12px"></div>
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
        <text x="38" y="24" font-family:"Inter", sans-serif; font-size="18" fill="var(--black)" font-weight="700">IT</text>
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
                        echo "<div class='row-export' style='display:inline-block; margin-left:8px; margin-top:6px;'>";
                        echo "<select class='rowExportSelect form-select' data-id='{$id}' style='min-width:160px;'>{$docOptionsHtml}</select>";
                        echo "<button class='rowExportBtn small-btn' data-id='{$id}' style='margin-left:6px;'>Export</button>";
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

          <footer class="k-footer">
            <span>2025 © SINCE</span> <span>QAIT SCHEMA</span>
            <div class="spacer"></div>
            <a href="#">Docs</a><a href="#"></a><a href="#">FAQ</a><a href="#">Support</a><a href="#">License</a>
          </footer>
        </div>
      </main>
    </div>

    <script>
      
      // Helper: read selected bank from header select if present
      function getSelectedBank() {
        const el = document.getElementById('bankFilter');
        return el ? el.value.trim() : '';
      }

  (function(){
    // run after your calendar builder exists
    const calWrap = document.querySelector('.status-center .calendar-wrap');
    const dateInput = document.getElementById('statusDate');
    const selDateEl = document.querySelector('.status-center .sel-date');

    if (!calWrap) return;

    // helper: normalize YYYY-MM-DD
    function toISO(y,m,d){ return `${y}-${String(m+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`; }

    // patch renderMonth: wrap existing renderMonth or re-run marking after render
    function markTodayAndDefaultSelection() {
      const datesGrid = calWrap.querySelector('.cal-dates');
      if (!datesGrid) return;
      const today = new Date();
      const todayY = today.getFullYear(), todayM = today.getMonth(), todayD = today.getDate();

      // remove old classes
      datesGrid.querySelectorAll('span').forEach(s => { s.classList.remove('today'); });

      // find and mark today's cell(s) for current visible month
      datesGrid.querySelectorAll('span').forEach(s => {
        if (s.classList.contains('disable')) return;
        const val = parseInt(s.textContent, 10);
        if (!isNaN(val)) {
          // determine the month displayed by header
          const header = calWrap.querySelector('.cal-title');
          if (!header) return;
          const title = header.textContent || '';
          // parse displayed month/year (locale-safe fallback)
          const parts = title.split(' ');
          if (parts.length >= 2) {
            const monName = parts.slice(0, parts.length-1).join(' ');
            const yr = parseInt(parts[parts.length-1],10);
            const testDate = new Date(`${monName} 1 ${yr}`);
            if (!isNaN(testDate)) {
              const monIndex = testDate.getMonth();
              if (yr === todayY && monIndex === todayM && val === todayD) {
                s.classList.add('today');
                // if no explicit input value, default-select today
                if (dateInput && !dateInput.value) {
                  // clear existing active
                  datesGrid.querySelectorAll('span').forEach(x => x.classList.remove('active'));
                  s.classList.add('active');
                  const iso = toISO(yr, monIndex, val);
                  dateInput.value = iso;
                  const ev = new Event('change',{bubbles:true});
                  dateInput.dispatchEvent(ev);
                  if (selDateEl) selDateEl.textContent = iso;
                } else if (!dateInput && selDateEl && !selDateEl.textContent.trim()){
                  // if no input exists, set sel-date to today without changing anything else
                  selDateEl.textContent = toISO(todayY,todayM,todayD);
                }
              }
            }
          }
        }
      });
    }

    // Observe changes to the dates grid so we can mark today after each render
    const observer = new MutationObserver(()=> markTodayAndDefaultSelection());
    const datesGrid = calWrap.querySelector('.cal-dates');
    if (datesGrid) observer.observe(datesGrid, { childList:true, subtree:true });

    // Also call once immediately in case the grid is already rendered
    markTodayAndDefaultSelection();

    // Also re-run after month nav clicks (safer)
    const prevBtn = calWrap.querySelector('.cal-prev'), nextBtn = calWrap.querySelector('.cal-next');
    [prevBtn, nextBtn].forEach(btn => { if (btn) btn.addEventListener('click', ()=> setTimeout(markTodayAndDefaultSelection, 60)); });

    // Keep a small handler for hover visual — not strictly necessary but ensures interaction
    calWrap.addEventListener('mouseover', (e) => {
      const el = e.target;
      if (el && el.matches && el.matches('.cal-dates span.today')) {
        // optional: you could also set some dynamic CSS vars here for glow
        // document.documentElement.style.setProperty('--qit-today-glow','1');
      }
    });
  })();


  (function(){
    /* --- Clock: markers + hands update --- */
    const clockCard = document.querySelector('.status-left .clock-card');
    const clockLabel = document.querySelector('.status-left .clock-large');
    const clockMeta = document.getElementById('statusClockMeta');

    if (clockCard && clockLabel) {
      // ensure markers container (only once)
      if (!clockCard.querySelector('.qit-clock-markers')) {
        const markers = document.createElement('div');
        markers.className = 'qit-clock-markers';
        clockCard.appendChild(markers);
        // create hour numbers
        for (let i=0;i<12;i++){
          const n = i===0?12:i;
          const el = document.createElement('div');
          el.className = 'qit-hour-marker';
          const angle = i*30;
          el.style.transform = `rotate(${angle}deg) translateY(-${(parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--qit-clock-size')||180)*0.4325)||-77}px) rotate(-${angle}deg)`;
          el.style.left = '50%';
          el.style.top = '50%';
          el.style.margin = '0';
          el.textContent = n;
          markers.appendChild(el);
        }
        // minute ticks (skip multiples of 5)
        for (let i=0;i<60;i++){
          if (i%5===0) continue;
          const tick = document.createElement('div');
          tick.className = 'qit-minute-tick';
          const angle = i*6;
          tick.style.transform = `rotate(${angle}deg) translateY(-${(parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--qit-clock-size')||180)*0.4325)||-77}px)`;        markers.appendChild(tick);
        }
      }

      // pivot dot
      if (!clockCard.querySelector('.pivot-dot')) {
        const dot = document.createElement('div');
        dot.className = 'pivot-dot';
        clockCard.appendChild(dot);
      }

      // update function
      function updateClock(){
        const now = new Date();
        const s = now.getSeconds() + now.getMilliseconds()/1000;
        const m = now.getMinutes() + s/60;
        const h = (now.getHours()%12) + m/60;
        const sdeg = s * 6;
        const mdeg = m * 6;
        const hdeg = h * 30;
        // set CSS vars
        clockCard.style.setProperty('--sdeg', sdeg);
        clockLabel.style.setProperty('--mdeg', mdeg);
        clockLabel.style.setProperty('--hdeg', hdeg);
        // digital time
        const hh = String(now.getHours()).padStart(2,'0');
        const mm = String(now.getMinutes()).padStart(2,'0');
        const ss = String(now.getSeconds()).padStart(2,'0');
        clockLabel.textContent = `${hh}:${mm}:${ss}`;
        if (clockMeta) clockMeta.textContent = now.toDateString();
      }
      updateClock();
      setInterval(updateClock, 60); // smooth enough (CSS transitions give fluid look)
    }

    /* --- Calendar: build month UI inside calendar-wrap --- */
    const calWrap = document.querySelector('.status-center .calendar-wrap');
    const dateInput = document.getElementById('statusDate');
    const selDateEl = document.querySelector('.status-center .sel-date');

    if (calWrap) {
      // create container structure only if not present
      if (!calWrap.querySelector('.cal-header')) {
        calWrap.innerHTML = `
          <div class="cal-header">
            <div class="cal-title"></div>
            <div class="cal-nav">
              <button class="cal-btn cal-prev" aria-label="Previous month">&lt;</button>
              <button class="cal-btn cal-next" aria-label="Next month">&gt;</button>
            </div>
          </div>
          <div class="cal-days"></div>
          <div class="cal-dates"></div>
          <div class="date-display">Selected: <span class="sel-date">—</span></div>
        `;
      }

      const headerTitle = calWrap.querySelector('.cal-title');
      const prevBtn = calWrap.querySelector('.cal-prev');
      const nextBtn = calWrap.querySelector('.cal-next');
      const daysRow = calWrap.querySelector('.cal-days');
      const datesGrid = calWrap.querySelector('.cal-dates');

      // week day labels
      const labels = ['SU','MO','TU','WE','TH','FR','SA'];
      daysRow.innerHTML = labels.map(l=>`<div style="flex:1;text-align:center">${l}</div>`).join('');

      // month state
      let current = dateInput && dateInput.value ? new Date(dateInput.value) : new Date();
      current.setDate(1);

      function renderMonth(dateObj){
        const y = dateObj.getFullYear();
        const m = dateObj.getMonth();
        headerTitle.textContent = dateObj.toLocaleString(undefined, { month:'long', year: 'numeric' });
        datesGrid.innerHTML = '';
        // first day index and days count
        const firstIndex = new Date(y,m,1).getDay();
        const daysCount = new Date(y,m+1,0).getDate();
        // prev month tail
        const prevTail = new Date(y,m,0).getDate();
        for (let i=0;i<firstIndex;i++){
          const el = document.createElement('span');
          el.className = 'disable';
          el.textContent = prevTail - firstIndex + 1 + i;
          datesGrid.appendChild(el);
        }
        // this month days
        for (let d=1; d<=daysCount; d++){
          const el = document.createElement('span');
          el.textContent = d;
          const thisDate = new Date(y,m,d);
          // active today's selected
          const sel = dateInput && dateInput.value ? new Date(dateInput.value) : null;
          if (sel && sel.getFullYear()===y && sel.getMonth()===m && sel.getDate()===d) {
            el.classList.add('active');
          }
          el.addEventListener('click', ()=> {
            if (dateInput) {
              // set input and dispatch change
              const iso = `${y}-${String(m+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
              dateInput.value = iso;
              const ev = new Event('change',{bubbles:true});
              dateInput.dispatchEvent(ev);
            } else {
              if (selDateEl) selDateEl.textContent = `${y}-${String(m+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
            }
            // update active classes
            datesGrid.querySelectorAll('span').forEach(s => s.classList.remove('active'));
            el.classList.add('active');
          });
          datesGrid.appendChild(el);
        }
        // next month head (fill trailing)
        const totalCells = firstIndex + daysCount;
        const trailing = (7 - (totalCells % 7)) % 7;
        for (let t=1;t<=trailing;t++){
          const el = document.createElement('span');
          el.className = 'disable';
          el.textContent = t;
          datesGrid.appendChild(el);
        }
      }

      prevBtn.addEventListener('click', ()=> { current.setMonth(current.getMonth()-1); renderMonth(current); });
      nextBtn.addEventListener('click', ()=> { current.setMonth(current.getMonth()+1); renderMonth(current); });

      // sync with existing date input
      if (dateInput) {
        // initialize from input value if present
        if (dateInput.value) {
          const parsed = new Date(dateInput.value);
          if (!isNaN(parsed)) { current = new Date(parsed.getFullYear(), parsed.getMonth(), 1); }
        }
        // update selected label when input changes
        dateInput.addEventListener('change', function(){
          const v = this.value || '—';
          if (selDateEl) selDateEl.textContent = v;
          // animate card
          calWrap.classList.remove('anim-flip'); void calWrap.offsetWidth; calWrap.classList.add('anim-flip');
          // re-render month to mark active
          if (this.value) {
            const p = new Date(this.value);
            current = new Date(p.getFullYear(), p.getMonth(), 1);
          }
          renderMonth(current);
          setTimeout(()=>calWrap.classList.remove('anim-flip'),700);
        });
        // set initial selected text
        selDateEl.textContent = dateInput.value || (new Date()).toISOString().slice(0,10);
      } else {
        selDateEl.textContent = (new Date()).toISOString().slice(0,10);
      }

      // initial render
      renderMonth(current);
    }
  })();


      // show/hide sections
      function showSection(sectionId) {
        const sections = ['employeeTable','operatorStatusSection','operatorOverviewSection','operatorMailingSection'];
        sections.forEach(s => { const el=document.getElementById(s); if(el) el.classList.add('hidden'); });
        const target=document.getElementById(sectionId);
        if(target){ target.classList.remove('hidden'); target.classList.add('section-visible'); }
        if (sectionId === 'operatorOverviewSection') loadOverview('',1);
      }

      function getCurrentSearchParam() {
        try { const url = new URL(window.location.href); return url.searchParams.get('search') || ''; } catch(e){ return ''; }
      }

      // Updated: loadOverview now includes bank param read from UI
      function loadOverview(filter='', page=1) {
        const placeholder = document.getElementById('overviewTablePlaceholder');
        const search = getCurrentSearchParam();
        const bankSel = getSelectedBank();
        const params = new URLSearchParams();
        params.set('ajax','1');
        if (filter) params.set('filter', filter);
        if (page) params.set('page', page);
        if (search) params.set('search', search);
        if (bankSel) params.set('bank', bankSel);
        const url = 'em_verfi.php?' + params.toString();
        if (placeholder) placeholder.innerHTML = '<div class="k-card">Loading…</div>';
        $.ajax({
          url: url, method: 'GET', dataType: 'html', cache: false,
          success: function(html) {
            if (placeholder) placeholder.innerHTML = html;
            const exportEl = document.getElementById('topExport');
            if (exportEl) {
              const p = new URLSearchParams();
              p.set('export','1');
              if (filter) p.set('filter',filter);
              if (search) p.set('search',search);
              if (bankSel) p.set('bank', bankSel);
              exportEl.href = 'em_verfi.php?' + p.toString();
            }
            initAutoScrollAll();
             initTopScrollbars(); 
            // bind row export buttons after fragment load
            if (typeof bindRowExports === 'function') bindRowExports();
          },
          error: function(xhr, status, err) {
            if (placeholder) placeholder.innerHTML = '<div class="k-card">Error loading overview table</div>';
            console.error('Overview fetch error', status, err, xhr && xhr.responseText);
          }
        });
      }

      // small helpers (AJAX posts)
      function toast(msg){ alert(msg); }

      // --- UPDATED updateStatus: safer cell update ---
      function updateStatus(id, status) {
        $.post('update_status.php', { id:id, status: status }, function(res){
          if (res && res.success) {
            const tr = document.getElementById('row-'+id);
            if (tr) {
              const sCell = tr.querySelector('td[data-col="status"]');
              if (sCell) {
                const inner = sCell.querySelector('strong, span') || sCell;
                inner.textContent = status;
              }
            }
            toast(res.message || 'Updated');
          } else {
            toast('Update failed: ' + (res && res.message ? res.message : 'unknown'));
          }
        }, 'json').fail(function(xhr, status, err){ toast('Request failed'); console.error('updateStatus fail', status, err, xhr && xhr.responseText); });
      }

      // --- UPDATED setWork: updates <strong> inside work cell and panel ---
      function setWork(id, work) {
        $.post('update_status.php', { id: id, work_status: work }, function(res){
          if (res && res.success) {
            const workWrap = document.getElementById('work-' + id);
            if (workWrap) {
              const strong = workWrap.querySelector('strong');
              if (strong) strong.textContent = work;
              else workWrap.textContent = work;
            }

            // if panel open for this id, update its work status display
            const panel = document.getElementById('opDetailPanel');
            if (panel && panel.dataset.opId == String(id)) {
              const docsStage = panel.querySelector('.stage[data-stage="docs"]');
              if (docsStage) {
                const rows = docsStage.querySelectorAll('.op-row');
                rows.forEach(r => {
                  const k = r.querySelector('.k');
                  const v = r.querySelector('.v');
                  if (k && v && /work status/i.test(k.textContent || '')) {
                    v.textContent = work;
                  }
                });
              }
            }

            toast(res.message || 'Work updated');
          } else {
            toast('Update failed: ' + (res && res.message ? res.message : 'unknown'));
            console.warn('setWork failed', res);
          }
        }, 'json').fail(function(xhr, status, err){
          toast('Request failed — check console');
          console.error('setWork failed', status, err, xhr && xhr.responseText);
        });
      }

      function saveReview(id) {
        const el = document.getElementById('review-'+id); if (!el) return;
        $.post('update_review.php', { id: id, review_notes: el.value }, function(res){ if (res && res.success) toast(res.message || 'Saved'); else toast('Save failed'); }, 'json').fail(function(){ toast('Request failed'); });
      }
      function makeRowEditable(id) {
        const tr = document.getElementById('row-'+id);
        if (!tr) return;
        tr.querySelectorAll('td[data-col]').forEach(td=>{
          const col = td.getAttribute('data-col'); if (!col) return;
          if (col.endsWith('_file') || col === 'created_at') return;
          const cur = td.textContent.trim();
          const input = document.createElement('input'); input.value = cur; input.setAttribute('data-edit','1'); input.style.width = '100%'; input.className = 'input-compact';
          td.innerHTML = ''; td.appendChild(input);
        });
        toast('Row editable — edit and click Save Row');
      }
      function saveRow(id) {
        const tr = document.getElementById('row-'+id); if (!tr) return;
        const payload = { id: id };
        tr.querySelectorAll('td[data-col]').forEach(td=>{ const col = td.getAttribute('data-col'); if (!col) return; const input = td.querySelector('input[data-edit]'); if (input) payload[col] = input.value; });
        $.post('update_row.php', payload, function(res){
          if (res && res.success) { toast(res.message || 'Saved'); if (!document.getElementById('operatorOverviewSection').classList.contains('hidden')) loadOverview('',1); else location.reload(); }
          else toast('Save failed');
        }, 'json').fail(function(){ toast('Request failed'); });
      }

      // DOM ready
      document.addEventListener('DOMContentLoaded', function(){
        feather.replace();
        showSection('operatorStatusSection');

        document.querySelectorAll('[data-section]').forEach(el=>{
          el.addEventListener('click', function(e){ e.preventDefault(); const section = el.getAttribute('data-section'); const fil = el.getAttribute('data-filter') || ''; showSection(section); document.querySelectorAll('[data-section]').forEach(x=>x.classList.remove('is-active')); el.classList.add('is-active'); if (section === 'operatorOverviewSection') loadOverview(fil,1); });
        });

        // header search
        const topBtn = document.getElementById('topSearchBtn'), topInp = document.getElementById('topSearch');
        if (topBtn && topInp) topBtn.addEventListener('click', function(){
          const q = topInp.value.trim();
          const url = new URL(window.location.href);
          const params = url.searchParams;
          if (q) params.set('search', q); else params.delete('search');
          // preserve bank filter in URL
          const selBank = getSelectedBank();
          if (selBank) params.set('bank', selBank); else params.delete('bank');
          window.location = window.location.pathname + '?' + params.toString();
        });

        // bank filter change -> refresh overview if overview visible, else update URL param
        const bankEl = document.getElementById('bankFilter');
        if (bankEl) {
          bankEl.addEventListener('change', function(){
            // if in overview section, reload via AJAX, else update URL to remember filter
            const overviewVisible = !document.getElementById('operatorOverviewSection').classList.contains('hidden');
            if (overviewVisible) {
              loadOverview('', 1);
            } else {
              const url = new URL(window.location.href);
              const params = url.searchParams;
              if (this.value) params.set('bank', this.value);
              else params.delete('bank');
              window.history.replaceState({}, '', window.location.pathname + '?' + params.toString());
            }
          });
        }

        // initialize donut
        initCharts();

        // calendar input binding + animated style
        const dateInput = document.getElementById('statusDate');
        const dateDisplay = document.querySelector('.sel-date');
        if (dateInput && dateDisplay) {
          dateInput.addEventListener('change', function(){ dateDisplay.textContent = this.value || '—'; });
          const now = new Date(); const s = now.toISOString().slice(0,10); dateInput.value = s; dateDisplay.textContent = s;
        }

   

        // resizable sidebar + toggle
        makeSidebarResizable();
        bindSidebarToggle();

        // clock
        startSmoothClock();

        // bind row exports for server-rendered initial table
        if (typeof bindRowExports === 'function') bindRowExports();

        // bulk export (top control)
  var bulkBtn = document.getElementById('bulkExportBtn');
  if (bulkBtn) {
    bulkBtn.addEventListener('click', function(){
      var sel = document.getElementById('bulkExportSelect');
      var doc = sel ? sel.value : '';
      if (!doc) { alert('Choose a document to export'); return; }
      var params = new URLSearchParams();
      params.set('doc', doc);

      // preserve current filter/search/bank (read from URL or UI)
      var urlp = new URL(window.location.href).searchParams;
      if (urlp.get('filter')) params.set('filter', urlp.get('filter'));
      if (urlp.get('search')) params.set('search', urlp.get('search'));
      var bankSel = getSelectedBank();
      if (bankSel) params.set('bank', bankSel);

      window.location.href = 'export_all.php?' + params.toString();
    });
  }


      });

      // CHARTS: only status donut
      function initCharts() {
        try {
          const statusDonutCtx = document.getElementById('statusDonut');
          if (statusDonutCtx && !statusDonutCtx.__chartInited) {
            const statusData = [<?= $operatorFilledCount ?>, <?= $operatorPendingCount ?>];
            new Chart(statusDonutCtx, {
              type: 'doughnut',
              data: {
                labels: ['Accepted','Pending'],
                datasets: [{ data: statusData, backgroundColor: ['#3BAFDA','#FFB020','#EF4444'], hoverOffset: 8 }]
              },
              options: {
                responsive: true, maintainAspectRatio: false,
                animation: { animateRotate: true, duration: 900, easing: 'easeOutCubic' },
                plugins: {
                  legend: { position: 'bottom' },
                  datalabels: {
                    color: '#222',
                    formatter: function(value, ctx) {
                      const dataArr = ctx.chart.data.datasets[0].data;
                      const sum = dataArr.reduce((a,b)=>a+b,0);
                      if (!sum) return '';
                      return Math.round((value/sum)*100) + '%';
                    },
                    font: { weight: '700', size: 12 }, anchor: 'center'
                  }
                }
              },
              plugins: [ChartDataLabels]
            });
            statusDonutCtx.__chartInited = true;
          }
        } catch(e){ console.warn(e); }
      }

      // Smooth animated digital clock + visual ring pulse
      function startSmoothClock() {
        const el = document.getElementById('statusClock'); const meta = document.getElementById('statusClockMeta');
        if (!el) return;
        function update() {
          const now = new Date();
          const hh = now.getHours().toString().padStart(2,'0');
          const mm = now.getMinutes().toString().padStart(2,'0');
          const ss = now.getSeconds().toString().padStart(2,'0');
          el.classList.remove('tick-anim'); void el.offsetWidth;
          el.textContent = `${hh}:${mm}:${ss}`;
          el.classList.add('tick-anim');
          if (meta) meta.textContent = now.toDateString();
        }
        update(); setInterval(update, 1000);
      }

      // AUTO-SCROLL for wide table + thumb sync (left <-> right)
      (function(){
        // requestAnimationFrame loop holders for each wrap
        const loops = new Map();

        window.initAutoScrollAll = function() {
          document.querySelectorAll('.auto-scroll-wrap').forEach(wrap => {
            const inner = wrap.querySelector('.table-scroll-inner');
            const indicator = wrap.querySelector('.scroll-indicator');
            const thumb = indicator ? indicator.querySelector('.scroll-thumb') : null;
            if (!inner) return;
            inner.style.animation = ''; inner.style.transform = '';
            if (loops.has(inner)) {
              cancelAnimationFrame(loops.get(inner));
              loops.delete(inner);
            }
            const visibleW = wrap.clientWidth;
            const contentW = inner.scrollWidth;
            if (contentW > visibleW + 8) {
              const distance = contentW - visibleW;
              const duration = Math.max(8, Math.min(45, Math.round(distance/20))); // seconds
              inner.style.animation = `auto-scroll ${duration}s linear infinite alternate`;
              wrap.classList.add('auto-scrolling');

              // indicator and thumb sizing
              if (indicator && thumb) {
                indicator.style.display = 'block';
                const ratio = visibleW / contentW;
                const thumbW = Math.max(40, Math.round(ratio * indicator.clientWidth));
                thumb.style.width = thumbW + 'px';
                thumb.style.left = '0px';
              }

              // pause on hover/wheel
              wrap.addEventListener('mouseenter', ()=> inner.style.animationPlayState='paused');
              wrap.addEventListener('mouseleave', ()=> inner.style.animationPlayState='running');
              wrap.addEventListener('wheel', ()=> { inner.style.animationPlayState='paused'; setTimeout(()=> inner.style.animationPlayState='running', 1200); }, {passive:true});

              // sync thumb position with transform using RAF
              function sync() {
                try {
                  const cs = window.getComputedStyle(inner);
                  const m = cs.transform || cs.webkitTransform || cs.msTransform;
                  let translateX = 0;
                  if (m && m !== 'none') {
                    // matrix(a, b, c, d, tx, ty)
                    const nums = m.match(/matrix.*\((.+)\)/);
                    if (nums && nums[1]) {
                      const parts = nums[1].split(',').map(p => parseFloat(p.trim()));
                      // tx is parts[4] for 2d matrix
                      translateX = parts[4] || 0;
                    }
                  }
                  // translateX is negative as it moves left
                  const progress = Math.min(1, Math.max(0, (-translateX) / (contentW - visibleW)));
                  if (indicator && thumb) {
                    const maxLeft = indicator.clientWidth - thumb.clientWidth;
                    thumb.style.left = Math.round(progress * maxLeft) + 'px';
                  }
                } catch(e){ /* fail silently */ }
                const id = requestAnimationFrame(sync);
                loops.set(inner, id);
              }
              sync();
            } else {
              // not wide enough
              if (indicator) indicator.style.display = 'none';
              wrap.classList.remove('auto-scrolling');
            }
          });
        };
      })();

      // SIDEBAR RESIZE (drag handle)
      function makeSidebarResizable() {
        const sidebar = document.getElementById('resizableSidebar');
        const handle = document.getElementById('sidebarDragHandle');
        if (!sidebar || !handle) return;
        let dragging = false;
        handle.addEventListener('mousedown', (e) => { dragging = true; document.body.style.cursor = 'ew-resize'; e.preventDefault(); });
        document.addEventListener('mouseup', ()=> { dragging = false; document.body.style.cursor = ''; });
        document.addEventListener('mousemove', (e) => {
          if (!dragging) return;
          const min = 56, max = Math.min(480, window.innerWidth - 280);
          const rect = sidebar.getBoundingClientRect();
          let newW = e.clientX - rect.left;
          newW = Math.max(min, Math.min(max, newW));
          sidebar.style.width = newW + 'px';
          document.documentElement.style.setProperty('--sidebar-w', newW + 'px');
          setTimeout(initAutoScrollAll, 120);
        });
        // touch support
        handle.addEventListener('touchstart', (e)=> { dragging = true; }, {passive:true});
        document.addEventListener('touchend', ()=> dragging=false);
        document.addEventListener('touchmove', (e)=> {
          if (!dragging) return;
          const t = e.touches[0];
          const rect = sidebar.getBoundingClientRect();
          let newW = t.clientX - rect.left;
          const min = 56, max = Math.min(480, window.innerWidth - 280);
          newW = Math.max(min, Math.min(max, newW));
          sidebar.style.width = newW + 'px';
          document.documentElement.style.setProperty('--sidebar-w', newW + 'px');
          setTimeout(initAutoScrollAll, 120);
        }, {passive:true});
      }

      // COLLAPSE / EXPAND sidebar via circular button
      function bindSidebarToggle(){
        const sidebar = document.getElementById('resizableSidebar');
        const btn = document.getElementById('sidebarToggle');
        if(!sidebar || !btn) return;
        btn.addEventListener('click', function(){
          sidebar.classList.toggle('sidebar-collapsed');
          // update ARIA
          const collapsed = sidebar.classList.contains('sidebar-collapsed');
          btn.setAttribute('aria-pressed', collapsed ? 'true' : 'false');
          // recompute table scroll after a short delay
          setTimeout(initAutoScrollAll, 260);
        });
      }

      // expose helpers
      window.initCharts = initCharts;
      window.initAutoScrollAll = window.initAutoScrollAll || function(){}; 

      /* ===== Export UI binding (per-row + bulk) ===== */

      // Bind per-row export buttons (call after fragment load or initial render)
      function bindRowExports(){
    document.querySelectorAll('.rowExportBtn').forEach(function(btn){
      if (btn._bound) return;
      btn._bound = true;
      btn.addEventListener('click', function(){
        var id = this.getAttribute('data-id') || this.dataset.id;
        if (!id) { alert('Missing operator id'); return; }
        var sel = this.parentElement.querySelector('.rowExportSelect');
        var doc = sel ? sel.value : '';
        if (!doc) { alert('Choose document to export for this operator'); return; }

        // include current filter/search/bank
        var params = new URLSearchParams();
        params.set('id', id);
        params.set('doc', doc);
        var urlp = new URL(window.location.href).searchParams;
        if (urlp.get('filter')) params.set('filter', urlp.get('filter'));
        if (urlp.get('search')) params.set('search', urlp.get('search'));
        var bankSel = getSelectedBank();
        if (bankSel) params.set('bank', bankSel);

        var url = 'export_operator.php?' + params.toString();
        window.location.href = url;
      });
    });
  }

      // expose globally
      window.bindRowExports = bindRowExports;

      // Bulk export fragment button (if fragment uses different ids)
      var bulkFragBtn = document.getElementById('bulkExportBtnFrag');
      if (bulkFragBtn) {
        bulkFragBtn.addEventListener('click', function(){
          var sel = document.getElementById('bulkExportSelectFrag');
          var doc = sel ? sel.value : '';
          if (!doc) { alert('Choose a document to export'); return; }
          var params = new URLSearchParams();
          params.set('doc', doc);
          // preserve active fragment filters (they come from current URL)
          var urlp = new URL(window.location.href).searchParams;
          if (urlp.get('filter')) params.set('filter', urlp.get('filter'));
          if (urlp.get('search')) params.set('search', urlp.get('search'));
          var bankSel = getSelectedBank();
          if (bankSel) params.set('bank', bankSel);
          window.location.href = 'export_all.php?' + params.toString();
        });
      }

  (function(){
    /* Helper: create panel and preview elements once */
    function createUI() {
      if (document.getElementById('opDetailPanel')) return;
      // Panel
      const panel = document.createElement('div');
      panel.id = 'opDetailPanel';
      panel.innerHTML = `
        <div class="hdr">
            <div class="title" data-top="Op's Data" data-bottom=""></div>
          <div>
            <button class="close" aria-label="Close">✕</button>
          </div>
        </div>
        <div class="tabs">
          <button data-stage="basic" class="active">Basic</button>
          <button data-stage="contact">Contact</button>
          <button data-stage="docs">Docs & Status</button>
        </div>
        <div class="content">
          <div class="stage active" data-stage="basic"></div>
          <div class="stage" data-stage="contact"></div>
          <div class="stage" data-stage="docs"></div>
        </div>
      `;
      document.body.appendChild(panel);
// optional helper — toggles container state when a button with data-stage is clicked
document.querySelectorAll('#opDetailPanel .accordion-btn, #opDetailPanel .tabs button').forEach(btn=>{
  btn.addEventListener('click', ()=> {
    const panel = document.getElementById('opDetailPanel') || document.querySelector('.operator-panel');
    if (!panel) return;
    // clear any existing show- classes
    panel.classList.remove('show-basic','show-contact','show-docs');
    const stage = btn.dataset.stage || btn.getAttribute('data-stage');
    if (stage === 'basic') panel.classList.add('show-basic');
    if (stage === 'contact') panel.classList.add('show-contact');
    if (stage === 'docs') panel.classList.add('show-docs');
  });
});

      // Preview hover tooltip
      const preview = document.createElement('div');
      preview.id = 'opRowPreview';
      preview.innerHTML = '<div class="line"><span class="b preview-name">—</span></div><div class="line preview-sub">—</div>';
      document.body.appendChild(preview);

      // events: close and tabs
      panel.querySelector('.close').addEventListener('click', ()=> hidePanel());
    panel.querySelectorAll('.tabs button').forEach(btn=>{
    btn.addEventListener('click', (e)=>{
      const stage = btn.getAttribute('data-stage');
      // set active class on tabs
      panel.querySelectorAll('.tabs button').forEach(b=>b.classList.remove('active'));
      btn.classList.add('active');
      // show the right stage content
      panel.querySelectorAll('.stage').forEach(s => s.classList.toggle('active', s.getAttribute('data-stage')===stage));
      // persist choice so future renderIntoPanel() calls restore this tab
      try { panel.dataset.activeStage = stage; } catch(e) { /* ignore if not settable */ }
    });
  });


      // close on ESC
      document.addEventListener('keydown', (e)=>{ if (e.key==='Escape') hidePanel(); });
    }

    function showPanel() {
      const p = document.getElementById('opDetailPanel'); if(!p) return;
      p.classList.add('visible');
    }
    function hidePanel() {
      const p = document.getElementById('opDetailPanel'); if(!p) return;
      p.classList.remove('visible');
    }

    function renderIntoPanel(data){
    createUI();
    const panel = document.getElementById('opDetailPanel');
    if(!panel) return;

    // store op id for actions
    const opId = data.id || data['id'] || null;
    panel.dataset.opId = opId || '';

    panel.querySelector('.title').textContent = data.operator_full_name || `Operator ${opId || ''}`;

    
    // BASIC stage
    const basic = panel.querySelector('.stage[data-stage="basic"]');
    basic.innerHTML = '';
    const basicRows = [
        ['Operator ID','operator_id'],
        ['Full name','operator_full_name'],
        ['Email','email'],
      
        ['Branch','branch_name'],
        ['Joining','joining_date'],
        ['Aadhaar','aadhar_number'],
        ['PAN','pan_number'],
        ['Voter ID','voter_id_no']
    ];
    basicRows.forEach(([k,key])=>{
      const row = document.createElement('div'); row.className='op-row';


      // --- REPLACEMENT START ---
row.dataset.field = key;
const rawVal = (data[key] !== undefined && data[key] !== null) ? data[key] : '';
const displayVal = escapeHTML(rawVal !== '' ? rawVal : '—');
const inputVal = (rawVal === null || rawVal === undefined) ? '' : rawVal;
row.innerHTML = '<div class="k">' + k + '</div>' +
  '<div class="v">' +
    '<span class="view">' + displayVal + '</span>' +
    '<input class="panel-input" data-field="' + key + '" id="panel-' + key + '" value="' + escapeHTML(String(inputVal)) + '" readonly style="display:none;width:100%;" />' +
  '</div>';
// --- REPLACEMENT END ---
      basic.appendChild(row);
    });

    // CONTACT stage
    const contact = panel.querySelector('.stage[data-stage="contact"]');
    contact.innerHTML = '';
    const contactRows = [
      ['Mobile','operator_contact_no'],
        ['Father','father_name'],
        ['DOB','dob'],
        ['Gender','gender'],
        ['Current HNo / Street','current_hno_street'],
        ['Current Town','current_village_town'],
        ['Current Pincode','current_pincode'],
        ['Permanent HNo / Street','permanent_hno_street'],
        ['Permanent Town','permanent_village_town'],
        ['Permanent Pincode','permanent_pincode']
    ];
    contactRows.forEach(([k,key])=>{
      const row = document.createElement('div'); row.className='op-row';
      // --- REPLACEMENT START ---
row.dataset.field = key;
const rawVal = (data[key] !== undefined && data[key] !== null) ? data[key] : '';
const displayVal = escapeHTML(rawVal !== '' ? rawVal : '—');
const inputVal = (rawVal === null || rawVal === undefined) ? '' : rawVal;
row.innerHTML = '<div class="k">' + k + '</div>' +
  '<div class="v">' +
    '<span class="view">' + displayVal + '</span>' +
    '<input class="panel-input" data-field="' + key + '" id="panel-' + key + '" value="' + escapeHTML(String(inputVal)) + '" readonly style="display:none;width:100%;" />' +
  '</div>';
// --- REPLACEMENT END ---
      contact.appendChild(row);
    });

    // DOCS & STATUS stage (includes action buttons + review textarea + attachments)
    const docs = panel.querySelector('.stage[data-stage="docs"]');
    docs.innerHTML = '';

    // action bar (wire to your existing global functions)
    const actions = document.createElement('div');
    actions.className = 'op-actions';
    actions.style = 'display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;';
    const safeId = opId ? parseInt(opId,10) : null;

  function mkBtn(label, onClick, cls='small-btn') {
    const btn = document.createElement('button');
    btn.className = cls + ' panel-btn';
    btn.type = 'button';
    btn.textContent = label;
    btn.style.minWidth = '140px';
    btn.style.padding = '10px 12px';

    if (!safeId) { btn.disabled = true; return btn; }

    if (typeof onClick === 'function') {
      btn.addEventListener('click', onClick);
    } else if (typeof onClick === 'string') {
      // keep backward compatibility for code that passes JS-as-string
      btn.addEventListener('click', function(){ (new Function(onClick))(); });
    } else {
      console.warn('mkBtn: unsupported onClick type', onClick);
    }
    return btn;
  }


    // Accept / Pending / Reject
    actions.appendChild(mkBtn('Accept', `updateStatus(${safeId},'accepted')`));
    actions.appendChild(mkBtn('Pending', `updateStatus(${safeId},'pending')`));
  

    // Working toggles
    actions.appendChild(mkBtn('Working', `setWork(${safeId},'working')`));
    actions.appendChild(mkBtn('Not Working', `setWork(${safeId},'not working')`));

    // Request Resubmission (new)
    actions.appendChild(mkBtn('Request Resubmission', function(){ openResubmitModal(safeId); }, 'small-btn'));

    // Edit / Save Row
 actions.appendChild(mkBtn('Edit Row', function(){ panelMakeEditable(safeId); }, 'small-btn'));
actions.appendChild(mkBtn('Save Row', function(){ panelSaveRow(safeId); }, 'small-btn'));


    // Save Review (will POST from panel textarea)
    const saveRevBtn = mkBtn('Save Review', `panelSaveReview(${safeId})`);
    saveRevBtn.style.fontWeight = '800';
    actions.appendChild(saveRevBtn);

    docs.appendChild(actions);

    // review textarea (panel-local)
    const revWrap = document.createElement('div');
    revWrap.style = 'margin-bottom:12px;';
    revWrap.innerHTML = `
      <div style="font-weight:700;color:var(--muted);margin-bottom:6px">Review notes</div>
      <textarea id="panel-review" style="width:100%;height:96px;border:1px solid rgba(15,23,42,0.06);border-radius:8px;padding:8px;font-size:13px">${escapeHTML(data.review_notes||'')}</textarea>
    `;
    docs.appendChild(revWrap);

    // status/work rows
    const rows3 = [
      ['Status','status'],
      ['Work status','work_status']
    ];
    rows3.forEach(([k,key])=>{
      const row = document.createElement('div'); row.className='op-row';
      // --- REPLACEMENT START ---
row.dataset.field = key;
const rawVal = (data[key] !== undefined && data[key] !== null) ? data[key] : '';
const displayVal = escapeHTML(rawVal !== '' ? rawVal : '—');
const inputVal = (rawVal === null || rawVal === undefined) ? '' : rawVal;
row.innerHTML = '<div class="k">' + k + '</div>' +
  '<div class="v">' +
    '<span class="view">' + displayVal + '</span>' +
    '<input class="panel-input" data-field="' + key + '" id="panel-' + key + '" value="' + escapeHTML(String(inputVal)) + '" readonly style="display:none;width:100%;" />' +
  '</div>';
// --- REPLACEMENT END ---
      docs.appendChild(row);
    });

    // --- Enhanced attachments UI (per-doc accept/reject/replace + Mailer) ---
    const attWrap = document.createElement('div'); attWrap.className='op-attachments';
    let anyAttach=false;

    // list of doc keys to manage (complete list from DB)
  const docKeys = [
    'aadhar_file',
    'pan_file',
    'voter_file',
    'ration_file',
    'consent_file',
    'gps_selfie_file',
    'police_verification_file',
    'permanent_address_proof_file',
    'parent_aadhar_file',
    'nseit_cert_file',
    'self_declaration_file',
    'non_disclosure_file',
    'edu_10th_file',
    'edu_12th_file',
    'edu_college_file'
  ];

  // label map for human friendly names
  const labelMap = {
    'aadhar_file': 'Aadhaar Card',
    'pan_file': 'PAN Card',
    'voter_file': 'Voter ID',
    'ration_file': 'Ration Card',
    'consent_file': 'Consent',
    'gps_selfie_file': 'GPS Selfie',
    'police_verification_file': 'Police Verification',
    'permanent_address_proof_file': 'Permanent Address Proof',
    'parent_aadhar_file': 'Parent Aadhaar',
    'nseit_cert_file': 'NSEIT Certificate',
    'self_declaration_file': 'Self Declaration',
    'non_disclosure_file': 'Non-Disclosure Agreement',
    'edu_10th_file': '10th Certificate',
    'edu_12th_file': '12th Certificate',
    'edu_college_file': 'College Certificate'
  };

  // expose doc keys globally so modal builder can use them
  window.DOC_KEYS = docKeys;
  window.LABEL_MAP = labelMap;


    function mkDocRow(k, url) {
      const lbl = labelMap[k] || k;
      const row = document.createElement('div');
      row.className = 'doc-row';
      const viewHtml = url ? `<a href="${escapeHTML(url)}" target="_blank">${prettifyFilename(url)}</a>` : '—';
      row.innerHTML = `
        <div class="k">${lbl}</div>
        <div class="v">
          <div><span class="doc-link">${viewHtml}</span></div>
          <div class="doc-actions">
            <button class="small-btn btn-accept"  data-doc="${k}">Accept</button>
            <button class="small-btn btn-reject"  data-doc="${k}">Reject</button>
            
            <label class="small-btn btn-upload"  style="cursor:pointer;">Replace<input type="file" accept=".pdf,image/*" style="display:none" data-doc="${k}"></label>
            <button class="small-btn btn-download" data-doc="${k}" ${!url ? 'disabled' : ''}>Download</button>
          </div> 
          <div class="doc-reject-reason" style="display:none;margin-top:6px;">
            <input class="input-compact reason-input" placeholder="Reason for rejection" data-doc="${k}" style="  margin-right:18px ">
            <button class="small-btn btn-save-reason" data-doc="${k}">Save</button>
            <button class="small-btn btn-cancel-reason" data-doc="${k}">Cancel</button>
          </div>
        </div>
      `;
      return row;
    }

    docKeys.forEach(k=>{
      const val = data[k] || '';
      if (val) anyAttach = true;
      const row = mkDocRow(k, val);
      attWrap.appendChild(row);
    });

    if(!anyAttach) {
      const row = document.createElement('div'); row.className='op-row';
      row.innerHTML = `<div class="k">Attachments</div><div class="v">—</div>`;
      docs.appendChild(row);
    } else {
      docs.appendChild(document.createElement('hr'));
      const row = document.createElement('div'); row.className='op-row';
      row.innerHTML = `<div class="k">Attachments</div><div class="v"></div>`;
      row.querySelector('.v').appendChild(attWrap);
      docs.appendChild(row);
    }

    // Mailer button
    const mailWrap = document.createElement('div');
    mailWrap.style = 'margin-top:12px';
    mailWrap.innerHTML = `<button class="small-btn" id="panel-mailer">Send Rejection Mail</button>`;
    docs.appendChild(mailWrap);

    // wire events for accept/reject/replace
    attWrap.querySelectorAll('.btn-accept').forEach(btn=>{
      btn.addEventListener('click', function(){
        const doc = this.dataset.doc;
        if (!confirm('Mark this document as ACCEPTED?')) return;
        docAction(opId, doc, 'accept', '');
      });
    });

    attWrap.querySelectorAll('.btn-reject').forEach(btn=>{
      btn.addEventListener('click', function(){
        const p = this.closest('.v');
        const reasonBox = p.querySelector('.doc-reject-reason');
        if (reasonBox) reasonBox.style.display = 'block';
      });
    });

    attWrap.querySelectorAll('.btn-cancel-reason').forEach(btn=>{
      btn.addEventListener('click', function(){
        this.closest('.doc-reject-reason').style.display = 'none';
      });
    });

    attWrap.querySelectorAll('.btn-save-reason').forEach(btn=>{
      btn.addEventListener('click', function(){
        const doc = this.dataset.doc;
        const p = this.closest('.doc-reject-reason');
        const input = p.querySelector('.reason-input');
        const reason = (input && input.value.trim()) || '';
        if (!reason) { alert('Please enter reason'); return; }
        docAction(opId, doc, 'reject', reason);
      });
    });

    attWrap.querySelectorAll('input[type=file]').forEach(fi=>{
      fi.addEventListener('change', function(){
        const doc = this.dataset.doc;
        if (!this.files || !this.files[0]) return;
        if (!confirm('Upload replacement file?')) { this.value=''; return; }
        uploadDoc(opId, doc, this);
      });
    });

    // *** Download button wiring: opens download.php with id & doc_key
    attWrap.querySelectorAll('.btn-download').forEach(btn=>{
      btn.addEventListener('click', function(){
        const doc = this.dataset.doc;
        if (!opId) { alert('Missing operator id'); return; }
        // open download endpoint in new tab so dashboard stays intact
        const dlUrl = 'download.php?id=' + encodeURIComponent(opId) + '&doc_key=' + encodeURIComponent(doc);
        window.open(dlUrl, '_blank');
      });
    });

    mailWrap.querySelector('#panel-mailer').addEventListener('click', function(){
      if (!confirm('Send rejection mail to operator listing rejected documents?')) return;
      // if a resubmission token exists for this operator (session/server), you may pass it here.
      sendRejectionMail(opId);
    });

  // show panel and restore previously selected tab (fall back to 'basic')
  showPanel();
  let restore = panel.dataset.activeStage || 'basic';
  const restoreBtn = panel.querySelector('.tabs button[data-stage="'+restore+'"]');
  if (restoreBtn) {
    restoreBtn.click();
  } else {
    // fallback to basic if stored value missing
    const b = panel.querySelector('.tabs button[data-stage="basic"]');
    if (b) b.click();
  }

  }

  // Unified save functions — drop-in replacement (paste once)
  function toast(msg){ alert(msg); } // keep your existing toast if you have one

  function saveReview(id) {
    const el = document.getElementById('review-' + id);
    if (!el) { console.warn('no review input for', id); return; }
    const notes = el.value;
    $.ajax({
      url: 'update_review.php',
      method: 'POST',
      dataType: 'json',
      data: { id: id, review_notes: notes },
      success: function(res) {
        console.log('update_review response', res);
        if (res && res.success) {
          // reflect changed value in UI (if needed)
          if (document.getElementById('review-' + id)) document.getElementById('review-' + id).value = notes;
          toast(res.message || 'Saved');
        } else {
          toast('Save failed: ' + (res && res.message ? res.message : 'unknown'));
          console.warn('saveReview failed', res);
        }
      },
      error: function(xhr, status, err) {
        console.error('Request failed', status, err, xhr && xhr.responseText);
        toast('Request failed — check console');
      }
    });
  }

  function panelSaveReview(id) {
    if (!id) { toast('Missing id'); return; }
    const ta = document.getElementById('panel-review');
    const notes = ta ? ta.value.trim() : '';
    toast('Saving review…');
    $.ajax({
      url: 'update_review.php',
      method: 'POST',
      dataType: 'json',
      data: { id: id, review_notes: notes },
      success: function(res) {
        console.log('panelSaveReview response', res);
        if (res && res.success) {
          // update table input if present
          const tableInput = document.getElementById('review-' + id);
          if (tableInput) tableInput.value = notes;
          toast(res.message || 'Review saved');
        } else {
          toast('Save failed: ' + (res && res.message ? res.message : 'unknown'));
          console.warn('panelSaveReview failed', res);
        }
      },
      error: function(xhr, status, err) {
        console.error('panel save failed', xhr, status, err);
        toast('Request failed — check console');
      }
    });
  }

  window.saveReview = saveReview;
  window.panelSaveReview = panelSaveReview;

  /* Safe text -> HTML helper */
  function escapeHTML(s){ if(s===null||s===undefined) return ''; return String(s).replace(/[&<>"]/g, (c)=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;' }[c])); }

  function prettifyFilename(u){ try{ return u.split('/').pop().split('?')[0]; }catch(e){ return 'file'; } }

  function gatherRowData(tr){
    const data = {};
    tr.querySelectorAll('td[data-col]').forEach(td=>{
      const key = td.getAttribute('data-col');
      let val = td.textContent || '';
      const a = td.querySelector('a');
      if (a && a.href) val = a.href;
      data[key] = val.trim();
    });
    // capture id and live review/work cells if present
    const id = tr.id ? tr.id.replace('row-','') : null;
    if (id) {
      data.id = id;
      const rv = document.getElementById('review-'+id);
      if(rv) data['review_notes'] = rv.value;
      const wsEl = document.getElementById('work-'+id);
      if(wsEl) data['work_status'] = wsEl.textContent.trim();
    }
    return data;
  }

  /* ---- New helper JS functions for doc actions, uploads, mailer ---- */

  function docAction(opId, docKey, action, reason) {
    $.post('update_doc_review.php', { id: opId, doc_key: docKey, action: action, reason: reason }, function(res){
      if (res && res.success) {
        alert(res.message || 'Updated');
        // refresh panel if open
        const trElem = document.getElementById('row-' + opId);
        if (trElem) renderIntoPanel(gatherRowData(trElem));
      } else {
        alert(res && res.message ? res.message : 'Error');
      }
    }, 'json').fail(function(){ alert('Request failed'); });
  }

  function uploadDoc(opId, docKey, fileInputEl) {
    const f = fileInputEl.files[0];
    const fd = new FormData();
    fd.append('id', opId);
    fd.append('doc_key', docKey);
    fd.append('file', f);
    $.ajax({
      url: 'upload_docs.php',
      method: 'POST',
      data: fd,
      processData: false,
      contentType: false,
      dataType: 'json',
      success: function(res) {
        if (res && res.success) {
          alert('Uploaded');
          const trElem = document.getElementById('row-' + opId);
          if (trElem) renderIntoPanel(gatherRowData(trElem));
          else location.reload();
        } else alert(res && res.message ? res.message : 'Upload failed');
      },
      error: function(){ alert('Upload request failed'); }
    });
  }

  /* sendRejectionMail now optionally accepts a token — if token is provided, it's included in the request
    so send_rejection_mail.php can include a resubmission link in the email. */
  function sendRejectionMail(opId, token) {
    const payload = { id: opId };
    if (token) payload.token = token;
    $.post('send_rejection_mail.php', payload, function(res){
      if (res && res.success) alert(res.message || 'Mail sent');
      else alert(res && res.message ? res.message : 'Mail failed');
    }, 'json').fail(function(){ alert('Mail request failed'); });
  }

  /* ---- RESUBMISSION MODAL + flow ---- */

  // Opens the modal to pick docs for resubmission for a given operator id

/* ---- RESUBMISSION MODAL + flow (NEW) ---- */
const RESUBMIT_DOCS = [
  {key:'aadhaar_file', label:'Aadhaar Card'},
  {key:'pan_file', label:'PAN Card'},
  {key:'voter_file', label:'Voter ID'},
  {key:'ration_file', label:'Ration Card'},
  {key:'consent_file', label:'Consent'},
  {key:'gps_selfie_file', label:'GPS Selfie'},
  {key:'police_verification_file', label:'Police Verification'},
  {key:'permanent_address_proof_file', label:'Permanent Address Proof'},
  {key:'parent_aadhar_file', label:"Parent Aadhaar"},
  {key:'nseit_cert_file', label:'NSEIT Certificate'},
  {key:'self_declaration_file', label:'Self Declaration'},
  {key:'non_disclosure_file', label:'Non-Disclosure Agreement'},
  {key:'edu_10th_file', label:'10th Certificate'},
  {key:'edu_12th_file', label:'12th Certificate'},
  {key:'edu_college_file', label:'College Certificate'}
];

function ensureResubmitOverlay() {
  let overlay = document.getElementById('resubmitOverlay');
  if (overlay) return overlay;

  overlay = document.createElement('div');
  overlay.id = 'resubmitOverlay';
  overlay.className = 'resubmit-overlay';
  overlay.style.display = 'none';
  overlay.innerHTML = `
    <div class="resubmit-modal" role="dialog" aria-modal="true">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
        <h3 style="margin:0;font-weight:600">Request resubmission</h3>
        <div><button id="resubmitClose" class="small-btn">Close</button></div>
      </div>
      <div style="font-size:13px;color:#445">Select documents you want the operator to re-upload. Optionally set token validity (days).</div>
      <div class="resubmit-list" id="resubmitList"></div>
      <div style="margin-top:8px">
        <label style="font-size:13px">Expires in (days):
          <input id="resubmitDays" type="number" value="7" min="1" style="width:80px;margin-left:8px;padding:6px;border-radius:6px;border:1px solid #ddd">
        </label>
      </div>
      <div class="resubmit-footer">
        <div style="display:flex;gap:8px">
          <button id="resubmitCreateBtn" class="small-btn">Create & Send</button>
          <button id="resubmitCreateOnlyBtn" class="small-btn">Create (no email)</button>
        </div>
        <div id="resubmitResult"></div>
      </div>
    </div>
  `;
  document.body.appendChild(overlay);
  document.getElementById('resubmitClose').addEventListener('click', ()=> closeResubmitModal());
  return overlay;
}

function openResubmitModal(opId){
  if (!opId) return alert('Missing operator id');
  const overlay = ensureResubmitOverlay();

  // populate list
  const list = overlay.querySelector('#resubmitList');
  list.innerHTML = '';
  RESUBMIT_DOCS.forEach(d=>{
    const id = 'rs_' + d.key;
    const wrapper = document.createElement('label');
    wrapper.style = 'display:flex;gap:10px;align-items:center;padding:6px;border-radius:6px;background:#fbfbfb';
    wrapper.innerHTML = `<input type="checkbox" id="${id}" name="docs[]" value="${d.key}"> <span style="font-weight:600">${d.label}</span> <small style="color:#666;margin-left:6px">(${d.key})</small>`;
    list.appendChild(wrapper);
  });

  // wire buttons
  const createBtn = overlay.querySelector('#resubmitCreateBtn');
  const createOnlyBtn = overlay.querySelector('#resubmitCreateOnlyBtn');
  const resultEl = overlay.querySelector('#resubmitResult');

  createBtn.onclick = () => submitResubmissionRequest(opId, true);
  createOnlyBtn.onclick = () => submitResubmissionRequest(opId, false);

  overlay.style.display = 'flex';
  document.body.classList.add('resubmit-open');
  resultEl.textContent = '';
}

function closeResubmitModal() {
  const overlay = document.getElementById('resubmitOverlay');
  if (!overlay) return;
  overlay.style.display = 'none';
  document.body.classList.remove('resubmit-open');
  const resultEl = overlay.querySelector('#resubmitResult');
  if (resultEl) resultEl.textContent = '';
}

function submitResubmissionRequest(opId, emailNow){
  const overlay = document.getElementById('resubmitOverlay');
  if (!overlay) return;
  const checkboxes = overlay.querySelectorAll('input[name="docs[]"]:checked');
  const docs = Array.from(checkboxes).map(cb => cb.value);
  if (!docs.length) return alert('Select at least one document for resubmission.');
  const days = parseInt((overlay.querySelector('#resubmitDays')||{value:7}).value,10) || 7;
  overlay.querySelector('#resubmitResult').textContent = 'Creating token…';

  const payload = { id: opId, docs: docs, expires_days: days, email_now: (emailNow ? 1 : 0) };
  $.ajax({
    url: 'create_resubmission.php',
    method: 'POST',
    dataType: 'json',
    data: payload,
    success: function(res){
      const resultEl = overlay.querySelector('#resubmitResult');
      if (res && res.success) {
        const url = res.url || ('duplicateoperator.php?token=' + encodeURIComponent(res.token || ''));
        resultEl.innerHTML = `Resubmission created. Link: <a href="${escapeHtml(url)}" target="_blank">${escapeHtml(url)}</a> &nbsp; <button id="copyResubmitLink" class="small-btn">Copy</button>`;
        overlay.querySelector('#copyResubmitLink').addEventListener('click', function(){
          try { navigator.clipboard.writeText(url); alert('Copied'); } catch(e){ prompt('Copy link', url); }
        });
        if (emailNow && !res.emailed) {
          sendRejectionMail(opId, res.token);
        }
      } else {
        resultEl.textContent = (res && res.message) ? res.message : 'Failed to create resubmission';
      }
    },
    error: function(xhr,status,err){
      overlay.querySelector('#resubmitResult').textContent = 'Request failed';
      console.error('create_resubmission failed', status, err, xhr && xhr.responseText);
    }
  });
}

/* helper: minimal escape for html injection in results (not DB) */
function escapeHtml(s){ return String(s||'').replace(/[&<>"']/g, function(m){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]; }); }



  /* Hover preview behaviour (small tooltip near pointer) */
  let previewTimer = null;
  const preview = document.getElementById ? null : null; // ensure linter ok
  function showPreviewAt(x,y, name, extra){
    createUI();
    const p = document.getElementById('opRowPreview');
    if(!p) return;
    p.style.left = (x+14)+'px';
    p.style.top = (y+14)+'px';
    p.querySelector('.preview-name').textContent = name || '—';
    p.querySelector('.preview-sub').textContent = extra || '';
    p.style.display = 'block';
  }
  function hidePreview(){
    const p = document.getElementById('opRowPreview');
    if(p) p.style.display = 'none';
  }

  /* Event delegation: clicks on any row with id row-<id> open details */
  document.addEventListener('click', function(e){
    // ignore clicks inside the panel
    if (e.target.closest && e.target.closest('#opDetailPanel')) return;
    const tr = e.target.closest && e.target.closest('tr[id^="row-"]');
    if(tr){
      const data = gatherRowData(tr);
      renderIntoPanel(data);
    }
  }, false);

  /* Hover preview using mouseover/mouseout (mouseenter doesn't bubble) */
  document.addEventListener('mouseover', function(e){
    const tr = e.target.closest && e.target.closest('tr[id^="row-"]');
    if(!tr) return;
    // prevent if target is within panel or input etc.
    previewTimer = setTimeout(()=>{
      const name = (tr.querySelector('td[data-col="operator_full_name"]')||{textContent:''}).textContent.trim();
      const idcell = (tr.querySelector('td[data-col="operator_id"]')||{textContent:''}).textContent.trim();
      const phone = (tr.querySelector('td[data-col="operator_contact_no"]')||{textContent:''}).textContent.trim();
      const htmlExtra = `${idcell} · ${phone}`;
      // position near mouse if available else near row bounding box
      const rc = tr.getBoundingClientRect();
      const x = (window._lastMouseX || rc.right);
      const y = (window._lastMouseY || rc.top);
      createUI();
      const p = document.getElementById('opRowPreview');
      if(p){
        p.querySelector('.preview-name').textContent = name || '—';
        p.querySelector('.preview-sub').textContent = htmlExtra;
        p.style.left = (x + 14) + 'px';
        p.style.top = (y + 14) + 'px';
        p.style.display = 'block';
      }
    }, 220); // small delay so quick mouse move doesn't pop everything
  }, false);

  document.addEventListener('mouseout', function(e){
    const tr = e.target.closest && e.target.closest('tr[id^="row-"]');
    if(previewTimer){ clearTimeout(previewTimer); previewTimer = null; }
    hidePreview();
  }, false);

  // track mouse position for preview placement
  document.addEventListener('mousemove', function(e){ window._lastMouseX = e.clientX; window._lastMouseY = e.clientY; });

  // ensure UI exists if user clicks programmatically
  createUI();

  // Make sure panel hides if user clicks outside (but not when clicking table rows)
  document.addEventListener('click', function(e){
    const panel = document.getElementById('opDetailPanel');
    if (!panel) return;
    if (panel.classList.contains('visible')) {
      const clickedInside = e.target.closest && (e.target.closest('#opDetailPanel') || e.target.closest('tr[id^="row-"]'));
      if (!clickedInside) hidePanel();
    }
  }, true);

  })();

  (function(){
    const sel = document.getElementById('bankFilter');
    if(!sel) return;
    sel.addEventListener('focus', ()=> sel.classList.add('open'));
    sel.addEventListener('blur',  ()=> sel.classList.remove('open'));
    // Some browsers fire 'change' when user opens then cancels — keep class removal defensive
    sel.addEventListener('change', ()=> sel.classList.remove('open'));
  })();


  var bulkBtn = document.getElementById('bulkExportBtn');
  if (bulkBtn) {
    bulkBtn.addEventListener('click', function(){
      var sel = document.getElementById('bulkExportSelect');
      var doc = sel ? sel.value : '';
      if (!doc) { alert('Choose a document to export'); return; }
      var params = new URLSearchParams();
      params.set('doc', doc);
      var urlp = new URL(window.location.href).searchParams;
      if (urlp.get('filter')) params.set('filter', urlp.get('filter'));
      if (urlp.get('search')) params.set('search', urlp.get('search'));
      var bankSel = getSelectedBank();
      if (bankSel) params.set('bank', bankSel);
      window.location.href = 'export_all.php?' + params.toString();
    });
  }

  /* ===== Custom dropdown builder
    Converts <select class="qit-select"> and <select.form-select> into animated dropdowns.
    Keeps original select in DOM (hidden) and syncs value.
  */

  (function(){
    'use strict';

    // query selects to convert (bank + bulk export + per-row selects)
    const selectorList = ['select.qit-select', 'select.form-select'];

    function createDropdownFromSelect(sel) {
      if (!sel || sel.dataset.cdDone === '1') return;
      sel.dataset.cdDone = '1';

      // wrapper
      const wrap = document.createElement('div');
      wrap.className = 'custom-dropdown';
      // create visible toggle
      const toggle = document.createElement('button');
      toggle.type = 'button';
      toggle.className = 'cd-toggle';
      toggle.setAttribute('aria-haspopup','listbox');
      toggle.setAttribute('aria-expanded','false');

      const labelSpan = document.createElement('span');
      labelSpan.className = 'cd-label';
      labelSpan.textContent = (sel.options[sel.selectedIndex] && sel.options[sel.selectedIndex].text) || 'Select…';

      const caret = document.createElement('span');
      caret.className = 'cd-caret';

      toggle.appendChild(labelSpan);
      toggle.appendChild(caret);

      // menu
      const menu = document.createElement('div');
      menu.className = 'cd-menu';
      menu.setAttribute('role','listbox');
      menu.setAttribute('tabindex','-1');

      // Build items from options
      Array.from(sel.options).forEach((opt, idx) => {
        const item = document.createElement('div');
        item.className = 'cd-item';
        item.setAttribute('role','option');
        item.dataset.value = opt.value;
        item.tabIndex = 0;
        item.innerHTML = '<span class="text">' + opt.text + '</span>';
        // if you want metadata (e.g. value) show to right
        // const meta = document.createElement('span'); meta.className = 'meta'; meta.textContent = opt.value; item.appendChild(meta);
        if (opt.disabled) item.setAttribute('aria-disabled','true');
        if (opt.selected) {
          item.setAttribute('aria-selected','true');
          item.classList.add('selected');
        } else {
          item.setAttribute('aria-selected','false');
        }
        menu.appendChild(item);
      });

      // Insert wrapper before the select, then move select inside wrapper
      sel.parentNode.insertBefore(wrap, sel);
      wrap.appendChild(toggle);
      wrap.appendChild(menu);
      wrap.appendChild(sel); // original select kept, but it's visually hidden by CSS earlier

      // hide original visually but keep in DOM (already handled by CSS override if you pasted earlier)
      sel.style.position = 'absolute';
      sel.style.opacity = '0';
      sel.style.pointerEvents = 'none';

      // state helpers
      function open() {
        wrap.classList.add('open');
        toggle.setAttribute('aria-expanded','true');
        menu.focus();
        // animate children with slight stagger
        const items = menu.querySelectorAll('.cd-item');
        items.forEach((it, i) => {
          it.style.transitionDelay = (i*14) + 'ms';
        });
        document.addEventListener('click', docClick);
      }
      function close() {
        wrap.classList.remove('open');
        toggle.setAttribute('aria-expanded','false');
        document.removeEventListener('click', docClick);
        // clear focused class
        menu.querySelectorAll('.cd-item').forEach(i => i.classList.remove('focused'));
      }

      function docClick(e){
        if (!wrap.contains(e.target)) close();
      }

      // toggle click
      toggle.addEventListener('click', function(e){
        e.stopPropagation();
        if (wrap.classList.contains('open')) close(); else open();
      });

      // item click
      menu.addEventListener('click', function(e){
        const it = e.target.closest('.cd-item');
        if (!it) return;
        if (it.getAttribute('aria-disabled') === 'true') return;
        selectItem(it);
        close();
      });

      // keyboard support
      let focusIndex = -1;
      menu.addEventListener('keydown', function(e){
        const items = Array.from(menu.querySelectorAll('.cd-item'));
        if (e.key === 'ArrowDown') {
          e.preventDefault();
          focusIndex = Math.min(items.length - 1, Math.max(0, focusIndex + 1));
          updateFocus(items);
        } else if (e.key === 'ArrowUp') {
          e.preventDefault();
          focusIndex = Math.max(0, focusIndex - 1);
          updateFocus(items);
        } else if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          if (focusIndex >= 0 && items[focusIndex]) {
            selectItem(items[focusIndex]);
            close();
          }
        } else if (e.key === 'Escape') {
          close();
          toggle.focus();
        }
      });

      function updateFocus(items) {
        items.forEach((it, idx) => {
          it.classList.toggle('focused', idx === focusIndex);
          if (idx === focusIndex) it.scrollIntoView({ block: 'nearest' });
        });
      }

      function selectItem(it) {
        const v = it.dataset.value;
        // set visual label
        labelSpan.textContent = it.querySelector('.text').textContent;
        // update aria-selected states
        menu.querySelectorAll('.cd-item').forEach(x => x.setAttribute('aria-selected','false'));
        it.setAttribute('aria-selected','true');
        // update original select value and trigger change
        sel.value = v;
        const ev = new Event('change', { bubbles:true });
        sel.dispatchEvent(ev);
      }

      // sync if original select changed programmatically elsewhere
      sel.addEventListener('change', function(){
        const cur = sel.value;
        const itm = menu.querySelector('.cd-item[data-value="'+CSS.escape(cur)+'"]');
        if (itm) {
          labelSpan.textContent = itm.querySelector('.text').textContent;
          menu.querySelectorAll('.cd-item').forEach(x => x.setAttribute('aria-selected','false'));
          itm.setAttribute('aria-selected','true');
        }
      });

      // pre-select index on open for keyboard nav
      menu.addEventListener('mouseover', function(e){
        const it = e.target.closest('.cd-item');
        if (!it) return;
        const items = Array.from(menu.querySelectorAll('.cd-item'));
        focusIndex = items.indexOf(it);
        updateFocus(items);
      });

      // clicking the original disabled select (in some browsers) should open our UI
      sel.addEventListener('focus', function(){ open(); });

    } // createDropdownFromSelect

    // initialize all matching selects
    function initAll() {
      const nodes = document.querySelectorAll(selectorList.join(','));
      nodes.forEach(s => createDropdownFromSelect(s));
    }

    // run on DOM ready
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', initAll);
    } else initAll();

    // expose for debugging
    window._cd_init = initAll;

  })();
/* ===== Pagination click wiring (fragment & server links) ===== */
document.addEventListener('click', function(e){
  // AJAX fragment page click
  const frag = e.target.closest && e.target.closest('.overview-page');
  if (frag) {
    e.preventDefault();
    const page = parseInt(frag.dataset.page || frag.getAttribute('data-page') || '1', 10) || 1;
    const filter = frag.dataset.filter || '';
    loadOverview(filter, page);
    return;
  }

  // Server-rendered pagination (links that reload page)
  const srv = e.target.closest && e.target.closest('.overview-page-server');
  if (srv) {
    // allow normal link navigation (works because href has page + preserved params)
    // but intercept to loadOverview via AJAX if overview panel already open
    const page = parseInt(srv.dataset.page || '1', 10) || 1;
    const overviewVisible = !document.getElementById('operatorOverviewSection').classList.contains('hidden');
    if (overviewVisible) {
      e.preventDefault();
      // preserve currently active filter from URL
      const urlp = new URLSearchParams(window.location.search);
      const filter = urlp.get('filter') || '';
      loadOverview(filter, page);
    }
    return;
  }
}, false);


(function(){
  // keep references to previous implementations (if any)
  const oldMakeRowEditable = window.makeRowEditable;
  const oldSaveRow = window.saveRow;

  function panelMakeEditable(opId) {
    const panel = document.getElementById('opDetailPanel');
    if (!panel || String(panel.dataset.opId) !== String(opId)) {
      // Panel not open for this operator - attempt to open it if there's a function
      try { if (typeof renderOperatorIntoPanel === 'function') renderOperatorIntoPanel(opId); } catch(e){}
      return;
    }
    panel.querySelectorAll('.op-row').forEach(row=>{
      const key = row.dataset.field || '';
      const v = row.querySelector('.v');
      if (!v) return;
      let inp = v.querySelector('input.panel-input, textarea.panel-input, select.panel-input');
      const span = v.querySelector('.view');
      const curText = span ? span.textContent.trim() : v.textContent.trim();
      if (!inp) {
        // create input by default (text)
        inp = document.createElement('input');
        inp.type = 'text';
        inp.className = 'panel-input';
        inp.dataset.field = key;
        inp.value = (curText === '—' ? '' : curText);
        inp.style.display = 'block';
        inp.style.width = '100%';
        v.appendChild(inp);
      } else {
        inp.style.display = 'block';
      }
      if (span) span.style.display = 'none';
      inp.readOnly = false;
    });
    panel.dataset.editing = '1';
    try { toast('Panel editable — change values then Save Row'); } catch(e) { console.log('Panel editable'); }
  }

  function panelSaveRow(opId) {
    const panel = document.getElementById('opDetailPanel');
    if (!panel || String(panel.dataset.opId) !== String(opId)) {
      if (typeof oldSaveRow === 'function') return oldSaveRow(opId);
      return;
    }
    const payload = { id: opId };
    // collect inputs
    panel.querySelectorAll('input.panel-input, textarea.panel-input, select.panel-input').forEach(inp=>{
      const f = inp.dataset.field || inp.name || inp.id.replace(/^panel-/, '');
      if (!f) return;
      payload[f] = inp.value;
    });

    // send to server (uses jQuery existing in the page)
    $.post('update_row.php', payload, function(res){
      if (res && res.success) {
        const updated = res.updated_fields || payload;
        // update table cells
        Object.keys(updated).forEach(k=>{
          if (k === 'id' || k === 'operator_id') return;
          const td = document.getElementById('cell-' + k + '-' + opId);
          if (td) td.textContent = updated[k];
          else {
            const td2 = document.querySelector('td[data-field="' + k + '"][data-id="' + opId + '"]');
            if (td2) td2.textContent = updated[k];
          }
        });

        // reflect values back to panel view and hide inputs
        panel.querySelectorAll('.op-row').forEach(row=>{
          const v = row.querySelector('.v');
          if (!v) return;
          const inp = v.querySelector('input.panel-input, textarea.panel-input, select.panel-input');
          const span = v.querySelector('.view');
          if (inp) {
            const newVal = (updated && updated[row.dataset.field]) ? updated[row.dataset.field] : inp.value;
            if (span) span.textContent = newVal || '—';
            inp.style.display = 'none';
            inp.readOnly = true;
          }
          if (span) span.style.display = 'block';
        });

        panel.dataset.editing = '';
        try { toast(res.message || 'Saved'); } catch(e) { alert(res.message || 'Saved'); }
      } else {
        try { toast('Save failed: ' + (res && res.message ? res.message : 'unknown')); } catch(e) { alert('Save failed'); }
      }
    }, 'json').fail(function(){ try { toast('Save request failed'); } catch(e) { alert('Save request failed'); } });

  }

  // expose helpers
  window.panelMakeEditable = panelMakeEditable;
  window.panelSaveRow = panelSaveRow;

  // override existing global functions to prefer panel editing when panel is open
  window.makeRowEditable = function(id) {
    const panel = document.getElementById('opDetailPanel');
    if (panel && String(panel.dataset.opId) === String(id)) {
      return panelMakeEditable(id);
    }
    if (typeof oldMakeRowEditable === 'function') return oldMakeRowEditable(id);
  };
  window.saveRow = function(id) {
    const panel = document.getElementById('opDetailPanel');
    if (panel && String(panel.dataset.opId) === String(id)) {
      return panelSaveRow(id);
    }
    if (typeof oldSaveRow === 'function') return oldSaveRow(id);
  };

})();

</script>  </body>
  </html>
