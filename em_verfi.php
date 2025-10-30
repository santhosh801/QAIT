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
      // Columns to show in OP DATA (overview) tables
      $OVERVIEW_VISIBLE_COLS = [
        'sno'                 => 'S.No',
        'operator_full_name'  => 'Operator Name',
        'operator_id'         => 'Operator ID',
        'operator_contact_no' => 'Contact No',
        'branch_name'         => 'Branch',
        'aadhar_number'       => 'Aadhaar No',
        'bank_name'           => 'Bank',
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
      $page   = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
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
        if (in_array($filter, ['pending', 'accepted', 'rejected'])) {
          $whereClauses[] = "status = '" . $mysqli->real_escape_string($filter) . "'";
        } elseif (in_array($filter, ['working', 'not working'])) {
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
        // BEFORE you compute $total_rows / $total_pages
        $limit = 100000; // effectively "all"; adjust if you need a higher ceiling

        $countRes = $mysqli->query("SELECT COUNT(*) as total FROM operatordoc $where");
        $total_rows = ($countRes ? (int)($countRes->fetch_assoc()['total'] ?? 0) : 0);

        // No pagination while we’re in all-rows mode
        $total_pages = 1;
        $offset = 0;

        $sql = "SELECT * FROM operatordoc $where ORDER BY created_at DESC"; // 👈 no LIMIT/OFFSET
        $res = $mysqli->query($sql);

      ?>
        <div class="k-card table-fragment">
          <div class="table-actions">
            <div class="left">
              <?php
              // Read active filter / page mode
              $filter = '';
              if (isset($_GET['filter']) && $_GET['filter'] !== '') {
                $filter = ucfirst($_GET['filter']); // capitalize first letter (working, pending → Working, Pending)
              } elseif (isset($_GET['work']) && $_GET['work'] !== '') {
                $filter = ucfirst($_GET['work']);
              } else {
                $filter = 'All';
              }
              $filterParam = urlencode(strtolower($filter)); // 👈 this fixes the warning

              // Output: Showing X rows — Working
              echo '<span class="muted">Showing ' . (int)$total_rows . ' rows';
              if (!empty($filter)) {
                echo ' — <strong class="page-label">' . htmlspecialchars($filter) . '</strong>';
              }
              echo '</span>';

              echo '&nbsp;&nbsp;<a href="export_basic.php?filter=' . $filterParam . '" title="Download basic data as Excel" style="vertical-align:middle; text-decoration:none;">';
              echo '<img src="cloud-download.png" alt="Export" style="width:34px;height:34px;border-radius:50%;box-shadow:0 2px 6px rgba(0,0,0,0.12);"/>';
              echo '</a>';
              // Inline search (no button)
              echo '&nbsp;&nbsp;<input id="inlineSearch" class="inline-search" type="search" ';
              echo 'placeholder="Search all data…" aria-label="Search all data" />';

              ?>
            </div>
            <div class="right">
              <!-- your filter buttons / export buttons -->
            </div>
          </div>


          <div class="table-wrap auto-scroll-wrap">
            <div class="table-scroll-inner">
              <table class="data-table excel-like">
                <thead>
                  <tr>
                    <th class="sn">S.No</th>
                    <th>Operator Name</th>
                    <th>Operator ID</th>
                    <th>Contact No</th>
                    <th>Branch</th>
                    <th>Aadhaar No</th>
                    <th>Bank</th>
                  </tr>
                </thead>




                <tbody>
                  <?php
                  if ($res && $res->num_rows > 0) {
                    $sn = 0;

                    // hidden (panel) fields we still need, but do NOT show as columns
                    $HIDDEN_COLS = [
                      // Basic
                      'email',
                      'joining_date',
                      'pan_number',
                      'voter_id_no',
                      // Contact
                      'father_name',
                      'dob',
                      'gender',
                      'alt_contact_relation',
                      'alt_contact_number',
                      'current_hno_street',
                      'current_village_town',
                      'current_pincode',
                      'permanent_hno_street',
                      'permanent_village_town',
                      'permanent_pincode',
                      // Status/notes
                      'status',
                      'work_status',
                      'review_notes'
                    ];

                    // document columns (render as links if present)
                    $DOC_FILE_COLS = [
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

                    while ($row = $res->fetch_assoc()) {
                      $id   = (int)$row['id'];
                      $opId = htmlspecialchars($row['operator_id'] ?? '', ENT_QUOTES);

                      echo "<tr id='row-{$id}' data-operator-id=\"{$opId}\">";

                      // 7 visible
                      echo "<td class='sn'>" . (++$sn) . "</td>";
                      echo "<td data-col='operator_full_name'>" . htmlspecialchars($row['operator_full_name'] ?? '') . "</td>";
                      echo "<td data-col='operator_id'>" . htmlspecialchars($row['operator_id'] ?? '') . "</td>";
                      echo "<td data-col='operator_contact_no'>" . htmlspecialchars($row['operator_contact_no'] ?? '') . "</td>";
                      echo "<td data-col='branch_name'>" . htmlspecialchars($row['branch_name'] ?? '') . "</td>";
                      echo "<td data-col='aadhar_number'>" . htmlspecialchars($row['aadhar_number'] ?? '') . "</td>";
                      echo "<td data-col='bank_name'>" . htmlspecialchars($row['bank_name'] ?? '') . "</td>";
                     
                      // hidden text fields for the panel
                      foreach ($HIDDEN_COLS as $col) {
                        $val = $row[$col] ?? '';
                        echo "<td class='col-hidden' data-col='" . htmlspecialchars($col, ENT_QUOTES) . "'>"
                          . htmlspecialchars((string)$val)
                          . "</td>";
                      }

                      // hidden doc file fields (links)
                      foreach ($DOC_FILE_COLS as $col) {
                        $url = trim($row[$col] ?? '');
                        $display = $url !== '' ? '<a href="' . htmlspecialchars($url) . '" target="_blank" class="file-link">View</a>' : '';
                        echo "<td class='col-hidden' data-col='" . htmlspecialchars($col, ENT_QUOTES) . "'>" . $display . "</td>";
                      }

                      // also keep id hidden
                      echo "<td class='col-hidden' data-col='id'>{$id}</td>";

                      echo "</tr>";
                    }
                  } else {
                    echo "<tr><td colspan='7' class='center'>No records found</td></tr>";
                  }
                  ?>
                </tbody>





              </table>
              <!-- Server rendered pagination (preserve search/filter/bank) -->

              <?php if (false): ?>
                <div class="fragment-pagination"> ... </div>
              <?php endif; ?>

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
        // Counts (safe queries with error-checking)
        // -----------------------------
        function safe_count($mysqli, $sql)
        {
          $res = $mysqli->query($sql);
          if ($res === false) {
            error_log("DB count query failed: " . $mysqli->error . " -- SQL: " . $sql);
            return 0;
          }
          $row = $res->fetch_assoc();
          return (int)($row['total'] ?? 0);
        }

        // Use safe_count to avoid calling fetch_assoc() on boolean when a query fails
        $employeeMailCount    = safe_count($mysqli, "SELECT COUNT(*) AS total FROM employees");
        $operatorPendingCount = safe_count($mysqli, "SELECT COUNT(*) AS total FROM operatordoc WHERE status='pending'");
        $operatorFilledCount  = safe_count($mysqli, "SELECT COUNT(*) AS total FROM operatordoc WHERE status='accepted'");

        // Working count: prefer single SQL that covers variants
        $operatorWorkingCount = safe_count(
          $mysqli,
          "SELECT COUNT(*) AS total FROM operatordoc WHERE work_status='working' OR status IN ('accepted','verified','em_verified','ph_verified')"
        );

        $operatorNotWorkingCount = safe_count($mysqli, "SELECT COUNT(*) AS total FROM operatordoc WHERE work_status='not working'");

        // total rows for pagination (safe)
        $total_rows = safe_count($mysqli, "SELECT COUNT(*) as total FROM operatordoc $where");
        $total_pages = max(1, ceil($total_rows / $limit));

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
        $operatorNotWorkingCount = (int)$mysqli->query("SELECT COUNT(*) AS total FROM operatordoc WHERE work_status='not working'")->fetch_assoc()['total'] ?? 0;

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
            <link rel="stylesheet" href="emverfi.css?v=<?= file_exists('emverfi.css') ? filemtime('emverfi.css') : time() ?>" type="text/css">
            <!-- MOBILE: viewport + override stylesheet -->
            <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">

            <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
            <script src="https://unpkg.com/feather-icons"></script>
            <link rel="preconnect" href="https://fonts.googleapis.com">
            <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
            <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@200..800&display=swap" rel="stylesheet">
            <!-- Chart.js + datalabels -->
            <link rel="icon" type="image/png" href="favicon.png">
            <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">

            <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
            <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>

          </head>

          <body>
            <header class="qit-header">
              <div class="container">
                <!-- === Animated QIT Logo (copied from landing page) === -->
                <!-- Animated QIT Logo (fixed size and animation) -->
                <button id="logoBtn" class="logo-btn">
                  <div class="logo-wrap">
                    <img src="qit_logo.png" alt="QIT Logo" width="120" height="100">
                    <div class="logo-mask"></div>
                    <div class="logo-shine"></div>
                  </div>
                </button>



                <div class="header-search">


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

                  <a id="topExport" class="sidebar-export gold-export" href="em_verfi.php?export=1<?= $search ? '&search=' . urlencode($search) : '' ?><?= $filter ? '&filter=' . urlencode($filter) : '' ?><?= $bank ? '&bank=' . urlencode($bank) : '' ?>">Export CSV</a>

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
                    <a href="#" data-section="operatorOverviewSection" data-filter=""><span class="nav-label">All Operators</span><span class="nav-icon"></span></a>
                    <div class="k-subtitle">Working</div>
                    <a href="#" data-section="operatorOverviewSection" data-filter="working"><span class="nav-label" id="sss">Working</span><span class="nav-icon"></span><span class="badge"><?= $operatorWorkingCount ?></span></a>
                    <a href="#" data-section="operatorOverviewSection" data-filter="pending"><span class="nav-label">Pending</span><span class="nav-icon"></span><span class="badge"><?= $operatorPendingCount ?></span></a>
                    <a href="#" data-section="operatorOverviewSection" data-filter="accepted"><span class="nav-label">Accepted</span><span class="nav-icon"></span><span class="badge"><?= $operatorFilledCount ?></span></a>
                    <div class="k-subtitle">Not Working</div>
                    <a href="#" data-section="operatorOverviewSection" data-filter="not working"><span class="nav-label">Not Working</span><span class="nav-icon"></span><span class="badge"><?= $operatorNotWorkingCount ?></span></a>
                    <div class="k-sb-footer">
                      <a href="#" data-section="operatorMailingSection"><span class="nav-label">Operator Mailing</span><span class="nav-icon"></span></a>
                      <a href="#" data-section="existingOperatorUploadSection"><span class="nav-label">Existing Operator Upload</span><span class="nav-icon"></span></a>

                    </div>
                  </nav>
                </div>
                <!-- circular emoji collapse/expand button -->
              </aside>

              <!-- START: Existing Operator Upload Panel (REPLACEMENT) -->
              <section id="existingOperatorUploadSection" class="content-section qb-panel" style="padding:20px; display:block;">
                <div class="qb-grid">
                  <!-- LEFT: Upload Form -->
                  <div class="qb-left">
                    <h3>Existing Operator Upload</h3>

                    <!-- SINGLE OPERATOR -->
                    <form id="singleOperatorForm" class="qb-form" data-action="single_operator" enctype="multipart/form-data" method="post">
                      <label class="qb-label">Operator ID (Single Operator)<span class="qb-required">*</span></label>
                      <input id="operatorId" name="operator_id" type="text" required class="qb-input" placeholder="e.g. OP123">

                      <label class="qb-label">Operator Excel (CSV/XLSX)</label>
                      <div class="qb-row">
                        <input id="operatorExcel" name="operator_excel" type="file" accept=".csv,.xlsx,.xls" class="qb-file" />
                        <button type="button" onclick="location.href='OperatorBulkUploadDocumentsText.php?download_template=1'">Download template</button>

                      </div>

                      <label class="qb-label">Operator Docs (ZIP or folder)</label>
                      <input id="operatorDocs" name="operator_docs[]" webkitdirectory directory multiple type="file" accept=".zip,.pdf,.jpg,.jpeg,.png" />
                      <div id="operatorDocsFileList" class="qb-filelist"></div>
                      <div class="qb-hint">If you upload a ZIP, the top-level folder **must** be the Operator ID (e.g. <code>OP123/</code>)</div>

                      <div class="qb-actions">
                        <button type="submit" class="submit-btn qb-submit-icon" title="Upload">Upload</button>
                      </div>

                      <div id="uploadResult" class="qb-result" aria-live="polite"></div>
                    </form>

                    <hr class="qb-hr">

                    <!-- BULK OPERATORS Excel (header-only) -->
                    <form id="bulkExcelForm" class="qb-form" enctype="multipart/form-data" method="post" data-action="bulk_text">
                      <label class="qb-label">Bulk Operators Excel (Text Only)</label>
                      <div class="qb-row">
                        <input id="bulkExcel" name="bulk_excel" type="file" accept=".csv,.xlsx,.xls" required class="qb-file" />
                        <button type="button" onclick="location.href='OperatorBulkUploadDocumentsText.php?download_template=1'">Download template</button>


                      </div>

                      <div class="qb-actions">
                        <button type="submit" class="submit-btn">Submit</button>
                      </div>
                    </form>

                    <hr class="qb-hr">

                    <!-- BULK DOCS ZIP -->
                    <form id="bulkDocsForm" class="qb-form" enctype="multipart/form-data" method="post" data-action="bulk_docs">
                      <label class="qb-label">Bulk Docs ZIP (subfolders named by Operator ID)</label>
                      <input id="bulkDocs" name="bulk_docs" type="file" accept=".zip" required class="qb-file" />
                      <div class="qb-hint">Each subfolder inside the ZIP must be named with the Operator ID (e.g. <code>OP123/</code>)</div>
                      <div class="qb-actions">
                        <button type="submit" class="submit-btn">Submit</button>
                      </div>
                    </form>

                    <div id="uploadResultBottom" style="margin-top:10px;"></div>
                  </div>

                  <!-- RIGHT: Rule Book -->
                  <aside class="qb-right">
                    <h3>Upload Rules & Examples</h3>

                    <div class="rule-card">
                      <div class="rule-header">
                        <strong>Rule 1 — Single Operator Upload (Excel & Docs)</strong>
                        <button class="rule-toggle" data-target="#rule1">Show</button>
                      </div>

                      <div id="rule1" class="rule-body" hidden>
                        <p>
                          Use this form to upload data for <strong>one operator at a time</strong>.
                          You may upload a small Excel/CSV row for the operator <em>AND/OR</em> a folder/ZIP of documents for that same operator.
                        </p>

                        <h4>Required — Operator ID</h4>
                        <p>
                          The <code>Operator ID</code> field is mandatory (<code>operator_id</code>).
                          Use the exact operator ID format used in your system (e.g. <code>OP123</code>). The server will sanitize names to [A–Z a–z 0–9 _ -].
                        </p>

                        <h4>Operator Excel (CSV / XLSX)</h4>
                        <p>
                          - Prefer <strong>CSV (UTF-8)</strong> for reliability. If you upload XLSX the server must support XLSX parsing (PhpSpreadsheet) — otherwise export to CSV first.
                          - Template contains header-only row. Click <em>Download template</em> to get the canonical header. Do not modify header names.
                          - Only text fields go in the CSV — keep any file-column fields blank (e.g. <code>aadhar_file</code>, <code>pan_file</code>, <code>voter_file</code>). Files must be uploaded via the Docs input or ZIP.
                        </p>

                        <h4>Operator Docs (ZIP or folder)</h4>
                        <p>
                          - Accepts: <code>.zip</code>, <code>.pdf</code>, <code>.jpg</code>, <code>.jpeg</code>, <code>.png</code>.
                          - If uploading a ZIP: the top-level folder **must** be exactly the Operator ID (e.g. <code>OP123/</code>). No nested ZIP files; no extra top-level folders.
                          - If uploading a directory (webkitdirectory), the same rule applies: top-level path equals Operator ID.
                        </p>

                        <pre class="qb-code">
Example ZIP layout (good)
OP123/ aadhar.pdf, pan.jpg, address.png, consent.pdf

Bad: OP123/docs/aadhar.pdf  → top level must be OP123/
Bad: OP123.zip inside another folder → uploader ignores nested zips
    </pre>

                        <h4>Filename keywords & auto-mapping</h4>
                        <p>
                          Filenames that include these keywords will be auto-mapped into DB columns:
                          <code>aadhar</code>, <code>aadhaar</code>, <code>pan</code>, <code>voter</code>, <code>ration</code>, <code>consent</code>, <code>gps</code>, <code>police</code>, <code>nda</code>, <code>10th</code>, <code>12th</code>, <code>college</code>.
                          Keep file names descriptive and keyword-rich (e.g. <code>OP123_aadhar.jpg</code>, <code>OP123_pan.pdf</code>).
                        </p>

                        <h4>Quick Troubleshooting</h4>
                        <ul>
                          <li>If upload fails: check server response in DevTools Network tab — look for JSON errors (e.g. <code>Missing bulk_excel</code> / <code>Invalid caseType</code>).</li>
                          <li>If CSV rows aren’t picked up: ensure header row is present and operator_id column exists (system expects <code>operator_id</code> header).</li>
                          <li>Files not appearing? confirm <code>uploads/operators/&lt;OPID&gt;/docs/</code> is writable by the webserver user.</li>
                        </ul>

                        <h4>Notes for admins</h4>
                        <ul>
                          <li>The PHP already provided handles ZIP extraction and filename keyword mapping. Don’t change column names in CSV template or it will break mapping.</li>
                          <li>Use the canonical <code>download_template.php</code> endpoint to provide the exact header to end-users — avoids mismatches.</li>
                        </ul>
                      </div>
                    </div>






                    <div class="rule-card">
                      <div class="rule-header">
                        <strong>Rule 2 — Bulk Operator Upload (ZIP or Excel/CSV)</strong>
                        <button class="rule-toggle" data-target="#rule4">Show</button>
                      </div>
                      <div id="rule4" class="rule-body" hidden>

                        <p>
                          Upload either of the following:
                          <br>(A) A <strong>ZIP</strong> containing operator folders <code>OPID/</code> (for documents), or
                          <br>(B) A <strong>CSV</strong> (or Excel exported as CSV UTF-8) file containing operator details.
                        </p>

                        <h4>ZIP Upload — for Documents</h4>
                        <p>
                          Each subfolder inside the ZIP must be named exactly as the Operator ID (e.g. <code>OP1001/</code>).
                          The folder should only contain related document files — <strong>no other folders, zips, or unrelated files</strong>.
                        </p>
                        <pre class="qb-code">
OP1001/ aadhar.pdf, pan.jpg, address.png, consent.pdf
OP1002/ voter.jpg, gps.png, police.pdf
    </pre>
                        <p>
                          Filenames that include keywords such as
                          <code>aadhar</code>, <code>pan</code>, <code>voter</code>, <code>consent</code>, <code>gps</code>,
                          <code>police</code>, <code>nda</code>, <code>10th</code>, <code>12th</code>, <code>college</code>
                          are automatically mapped to database columns.
                        </p>

                        <h4>CSV Upload — for Operator Details</h4>
                        <p>
                          Use the <strong>Bulk Operators Excel (Text Only)</strong> upload for creating operator rows.
                          The CSV must contain all header fields exactly as in the system template.
                          <br>Each row represents one operator record.
                          <br><strong>Important:</strong> No file upload columns (like <code>aadhar_file</code>) should contain any data — keep them blank.
                        </p>

                        <pre class="qb-code">
id,operator_id,operator_full_name,email,branch_name,joining_date,operator_contact_no,father_name,dob,gender,
aadhar_number,pan_number,voter_id_no,ration_card,nseit_number,bank_name,status,work_status
,OP1001,Ravi Kumar,ravi.kumar@example.com,Salem,2024-05-01,9876543210,Kumar,1998-02-10,Male,1111-2222-3334,ABCDE1234F,TNVOTE1,RC1,NSEIT1,Indian Bank,Accepted,Working
,OP1002,Meera Nair,meera.nair@example.com,Chennai,2024-06-15,9876501234,Suresh,1999-07-21,Female,1111-2222-3335,ABCDE1235F,TNVOTE2,RC2,NSEIT2,Indian Bank,Accepted,Working
    </pre>

                        <h4>Notes</h4>
                        <ul>
                          <li>ZIP adds or updates document files for operators.</li>
                          <li>CSV adds or updates text data for operators (no files inside CSV).</li>
                          <li>Operator folder names must exactly match the IDs inside the CSV.</li>
                          <li>Do not include any extra nested folders or other file types in the ZIP.</li>
                          <li>Operator IDs are case-sensitive (use uppercase format like <code>OP1001</code>).</li>
                          <li>If you use Excel, always export it as <strong>CSV (UTF-8)</strong> before upload.</li>
                          <li>All uploads automatically sanitize filenames — only A–Z, 0–9, underscore, and dash are allowed.</li>
                          <li>Template download will always include the latest approved 51-field header.</li>
                        </ul>
                      </div>
                    </div>
                    <div class="rule-card">
                      <div class="rule-header">
                        <strong>Rule 3 — Bulk Docs ZIP Upload</strong>
                        <button class="rule-toggle" data-target="#rule3">Show</button>
                      </div>

                      <div id="rule3" class="rule-body" hidden>
                        <p>
                          Upload a single <strong>ZIP file</strong> that contains all operator folders.
                          Each operator’s folder must be named exactly by their <strong>Operator ID</strong> — for example, <code>OP1001/</code>.
                          Inside each folder, include only that operator’s required documents.
                        </p>

                        <h4>ZIP Folder Rules</h4>
                        <ul>
                          <li>Each subfolder must be named exactly as the Operator ID (e.g. <code>OP1001/</code>).</li>
                          <li>No spaces, special symbols, or mixed case — only <code>A–Z</code>, <code>0–9</code>, <code>_</code>, and <code>-</code> are allowed.</li>
                          <li>Do not nest multiple ZIPs inside another ZIP — the system ignores nested archives.</li>
                          <li>Do not include Excel or text files inside the ZIP. This upload is strictly for documents only.</li>
                          <li>Each operator’s folder should contain PDF, JPG, JPEG, or PNG documents only.</li>
                        </ul>

                        <h4>Correct ZIP Structure (Example)</h4>
                        <pre class="qb-code">
WS.zip
│
├── OP1001/
│   ├── aadhar.pdf
│   ├── pan_card.jpg
│   ├── voter_id.png
│   └── consent.pdf
│
├── OP1002/
│   ├── aadhar.jpg
│   ├── gps_selfie.png
│   ├── police_verification.pdf
│   └── address_proof.jpg
    </pre>

                        <h4>Filename Auto-Mapping</h4>
                        <p>
                          The system automatically detects document types based on filename keywords.
                          For example:
                        </p>
                        <ul>
                          <li><code>aadhar</code> / <code>aadhaar</code> → Aadhar File</li>
                          <li><code>pan</code> → PAN File</li>
                          <li><code>voter</code> → Voter File</li>
                          <li><code>ration</code> / <code>familycard</code> → Ration Card File</li>
                          <li><code>consent</code> / <code>agreement</code> → Consent File</li>
                          <li><code>gps</code> / <code>selfie</code> / <code>geotag</code> → GPS Selfie</li>
                          <li><code>police</code> / <code>verification</code> → Police Verification File</li>
                          <li><code>address</code> / <code>proof</code> → Permanent Address Proof</li>
                          <li><code>nseit</code> / <code>cert</code> → NSEIT Certificate</li>
                          <li><code>nda</code> / <code>non_disclosure</code> → Non-Disclosure File</li>
                          <li><code>10th</code> / <code>sslc</code> / <code>matric</code> → 10th Certificate</li>
                          <li><code>12th</code> / <code>hsc</code> / <code>plus2</code> → 12th Certificate</li>
                          <li><code>college</code> / <code>degree</code> / <code>btech</code> → College Certificate</li>
                        </ul>

                        <h4>Important Notes</h4>
                        <ul>
                          <li>The ZIP is automatically extracted into <code>uploads/operators/&lt;OperatorID&gt;/docs/</code>.</li>
                          <li>File names are sanitized — only [A–Z, a–z, 0–9, _, -] are allowed. Invalid characters are replaced with underscores.</li>
                          <li>If any folder name doesn’t match an Operator ID, it’s skipped (and logged).</li>
                          <li>Duplicate file names are automatically renamed with timestamps to avoid overwriting.</li>
                          <li>The database updates each operator’s record with web-accessible file paths.</li>
                        </ul>

                        <h4>Quick Validation Tips</h4>
                        <ul>
                          <li>Before uploading, open your ZIP — confirm folders are named correctly (no “docs/” or “compressed folder/” prefixes).</li>
                          <li>Make sure the ZIP size is within your server’s limit (<code>upload_max_filesize</code> and <code>post_max_size</code> in PHP.ini).</li>
                          <li>Never upload multiple operators’ files inside the same subfolder — keep each operator isolated.</li>
                          <li>To re-upload updated docs, just upload the new ZIP — existing operator folders will be updated automatically.</li>
                        </ul>

                        <h4>Summary</h4>
                        <p>
                          ✅ <strong>Goal:</strong> One ZIP → Many operators → Each folder = one operator.
                          ❌ <strong>Don’t include:</strong> Excel files, nested ZIPs, random images, or misnamed folders.
                          💡 <strong>Tip:</strong> Always verify the folder naming before compressing — saves debugging time.
                        </p>

                      </div>
                    </div>


                  </aside>
                </div>
              </section>
              <!-- END: Existing Operator Upload Panel -->

              <main>
                <div class="k-container">
                  <div class="k-page-head">
                    <div class="k-page-title">
                      <h1 class="gold-title">KYC Dashboard</h1>
                      <div class="k-subtitle">&nbsp;</div>
                    </div>
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
                  <!-- Mailing form -->
                  <section id="operatorMailingSection" class="k-section hidden">
                    <div class="signup mail-form">
                      <form method="post" action="employee.php">
                        <label for="chk" aria-hidden="true">Employee → Operator Mail</label>

                        <!-- Row 1 -->
                        <div class="mail-row">
                          <input type="text" name="employee_name" placeholder="Employee Name" required>
                          <input type="email" name="employee_email" placeholder="Employee Email" required>
                        </div>

                        <!-- Row 2 -->
                        <div class="mail-row">
                          <input type="email" name="operator_email" placeholder="Operator Email" required>
                          <input type="text" name="operator_id" placeholder="Operator ID" required>
                        </div>

                        <!-- Row 3 -->
                        <div class="mail-row">
                          <input type="text" name="aadhaar_id" placeholder="Aadhaar ID" required>
                          <input type="text" name="unique_id" placeholder="EMPLOYEE ID" required>
                        </div>

                        <!-- Row 4 -->
                        <div class="mail-row">
                          <input type="text" name="mobile_number" placeholder="Mobile Number" required>
                        </div>

                        <button class="btn-effect3" type="submit">Submit</button>
                      </form>
                    </div>
                  </section>



                  <!-- Server-rendered main table (initial fallback) -->
                  <section id="employeeTable" class="k-section">
                    <div class="table-wrap auto-scroll-wrap">
                      <div class="table-scroll-inner">
                        <table class="data-table excel-like ">
                          <thead>
                            <tr>
                              <th class="sn">S.No</th>
                              <th>Operator Name</th>
                              <th>Operator ID</th>
                              <th>Contact No</th>
                              <th>Branch</th>
                              <th>Aadhaar No</th>
                              <th>Bank</th>
                            </tr>
                          </thead>



                          <tbody>
                            <?php
                            if ($res && $res->num_rows > 0) {
                              $sn = 0;

                              $HIDDEN_COLS = [
                                'email',
                                'joining_date',
                                'pan_number',
                                'voter_id_no',
                                'father_name',
                                'dob',
                                'gender',
                                'alt_contact_relation',
                                'alt_contact_number',
                                'current_hno_street',
                                'current_village_town',
                                'current_pincode',
                                'permanent_hno_street',
                                'permanent_village_town',
                                'permanent_pincode',
                                'status',
                                'work_status',
                                'review_notes'
                              ];

                              $DOC_FILE_COLS = [
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

                              while ($row = $res->fetch_assoc()) {
                                $id   = (int)$row['id'];
                                $opId = htmlspecialchars($row['operator_id'] ?? '', ENT_QUOTES);

                                echo "<tr id='row-{$id}' data-operator-id=\"{$opId}\">";

                                echo "<td class='sn'>" . (++$sn) . "</td>";
                                echo "<td data-col='operator_full_name'>" . htmlspecialchars($row['operator_full_name'] ?? '') . "</td>";
                                echo "<td data-col='operator_id'>" . htmlspecialchars($row['operator_id'] ?? '') . "</td>";
                                echo "<td data-col='operator_contact_no'>" . htmlspecialchars($row['operator_contact_no'] ?? '') . "</td>";
                                echo "<td data-col='branch_name'>" . htmlspecialchars($row['branch_name'] ?? '') . "</td>";
                                echo "<td data-col='aadhar_number'>" . htmlspecialchars($row['aadhar_number'] ?? '') . "</td>";
                                echo "<td data-col='bank_name'>" . htmlspecialchars($row['bank_name'] ?? '') . "</td>";

                                foreach ($HIDDEN_COLS as $col) {
                                  $val = $row[$col] ?? '';
                                  echo "<td class='col-hidden' data-col='" . htmlspecialchars($col, ENT_QUOTES) . "'>"
                                    . htmlspecialchars((string)$val)
                                    . "</td>";
                                }

                                foreach ($DOC_FILE_COLS as $col) {
                                  $url = trim($row[$col] ?? '');
                                  $display = $url !== '' ? '<a href="' . htmlspecialchars($url) . '" target="_blank" class="file-link">View</a>' : '';
                                  echo "<td class='col-hidden' data-col='" . htmlspecialchars($col, ENT_QUOTES) . "'>" . $display . "</td>";
                                }

                                echo "<td class='col-hidden' data-col='id'>{$id}</td>";

                                echo "</tr>";
                              }
                            } else {
                              echo "<tr><td colspan='7' class='center'>No records found</td></tr>";
                            }
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

              (function() {
                const btn = document.getElementById('logoBtn');
                const wrap = btn && btn.querySelector('.logo-wrap');
                if (!wrap) return;

                function playOnce() {
                  wrap.classList.remove('running', 'settle');
                  void wrap.offsetWidth; // reflow
                  wrap.classList.add('running');
                  setTimeout(() => {
                    wrap.classList.add('settle');
                    wrap.classList.remove('running');
                  }, 1150);
                }

                if (document.readyState === 'complete' || document.readyState === 'interactive') {
                  setTimeout(playOnce, 220);
                } else {
                  document.addEventListener('DOMContentLoaded', () => setTimeout(playOnce, 220));
                }

                btn.addEventListener('click', function(e) {
                  e.preventDefault();
                  playOnce();
                });
              })();
            </script>

            <script src="em_verfi.js"></script>
          </body>

          </html>