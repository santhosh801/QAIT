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
// params
// -----------------------------
$limit  = 10;
$page   = isset($_GET['page']) ? max(1,(int)$_GET['page']) : 1;
$search = isset($_GET['search']) ? $mysqli->real_escape_string($_GET['search']) : '';
$offset = ($page - 1) * $limit;
$filter = isset($_GET['filter']) ? $mysqli->real_escape_string($_GET['filter']) : '';

$whereClauses = [];
if ($search !== '') {
    $s = $mysqli->real_escape_string($search);
    $whereClauses[] = "(operator_full_name LIKE '%$s%' OR email LIKE '%$s%' OR operator_id LIKE '%$s%')";
}
if ($filter !== '') {
    if (in_array($filter, ['pending','accepted'])) {
        $whereClauses[] = "status = '" . $mysqli->real_escape_string($filter) . "'";
    } elseif (in_array($filter, ['working','not working'])) {
        $whereClauses[] = "work_status = '" . $mysqli->real_escape_string($filter) . "'";
    }
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
        <div class="right"><a class="sidebar-export fragment-export" href="em_verfi.php?export=1<?= $search ? '&search='.urlencode($search) : '' ?><?= $filter ? '&filter='.urlencode($filter) : '' ?>">Export CSV</a></div>
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
                      echo "<td class='col-actions nowrap'><button class='small-btn' onclick=\"updateStatus({$id},'accepted')\">Accept</button><button class='small-btn' onclick=\"updateStatus({$id},'pending')\">Pending</button><button class='small-btn' onclick=\"updateStatus({$id},'rejected')\">Reject</button><button class='small-btn' onclick=\"makeRowEditable({$id})\">Edit</button><button class='small-btn' onclick=\"saveRow({$id})\">Save Row</button></td>";
                      echo "</tr>";
                  }
              } else {
                  echo "<tr><td colspan='200' style='padding:10px;text-align:center'>No records found</td></tr>";
              }
              ?>
            </tbody>
          </table>
        </div><!-- .table-scroll-inner -->
        <div class="scroll-indicator" aria-hidden="true"><div class="scroll-thumb"></div></div>
      </div><!-- .table-wrap -->

      <div class="fragment-pagination">
        <?php for ($p = 1; $p <= $total_pages; $p++): ?>
          <a href="#" class="overview-page" data-page="<?= $p ?>"><?= $p ?></a>
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
$operatorRejectedCount  = (int)$mysqli->query("SELECT COUNT(*) AS total FROM operatordoc WHERE status='non'")->fetch_assoc()['total'] ?? 0;
$operatorWorkingCount   = (int)$mysqli->query("SELECT COUNT(*) AS total FROM operatordoc WHERE work_status='working'")->fetch_assoc()['total'] ?? 0;
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

      <div class="header-search">
        <input id="topSearch" type="search" placeholder="Search operator..." value="<?= htmlspecialchars($search) ?>">
        <button id="topSearchBtn">Search</button>
        <a id="topExport" class="sidebar-export" href="em_verfi.php?export=1<?= $search ? '&search='.urlencode($search) : '' ?><?= $filter ? '&filter='.urlencode($filter) : '' ?>">Export CSV</a>
      </div>

      <nav class="qit-nav">
        <ul>
          <li><a href="#" onclick="showSection('operatorStatusSection')">Home</a></li>
          <li><a href="#" onclick="showSection('operatorOverviewSection')">Overview</a></li>
        </ul>
      </nav>
    </div>
  </header>

  <div class="k-shell">
    <aside id="resizableSidebar">
      <div class="k-card sidebar-inner">
        <div class="k-card-title">Operator</div>
        <nav class="sidebar-nav">
          <a href="#" data-section="operatorStatusSection" class="is-active"><span class="nav-label">Operator Status</span><span class="nav-icon">📊</span></a>
          <a href="#" data-section="operatorOverviewSection" data-filter=""><span class="nav-label">OP DATA (All)</span><span class="nav-icon">📋</span></a>

          <div class="k-subtitle">Working</div>
          <a href="#" data-section="operatorOverviewSection" data-filter="working"><span class="nav-label" id="sss">Working</span><span class="nav-icon">🟢</span><span class="badge"><?= $operatorWorkingCount ?></span></a>
          <a href="#" data-section="operatorOverviewSection" data-filter="pending"><span class="nav-label">Pending</span><span class="nav-icon">⏳</span><span class="badge"><?= $operatorPendingCount ?></span></a>
          <a href="#" data-section="operatorOverviewSection" data-filter="accepted"><span class="nav-label">Accepted</span><span class="nav-icon">✅</span><span class="badge"><?= $operatorFilledCount ?></span></a>

          <div class="k-subtitle">Not Working</div>
          <a href="#" data-section="operatorOverviewSection" data-filter="not working"><span class="nav-label">Not Working</span><span class="nav-icon">🔴</span><span class="badge"><?= $operatorNotWorkingCount ?></span></a>

          <div class="k-sb-footer">
            <a href="#" data-section="operatorMailingSection"><span class="nav-label">Operator Mailing</span><span class="nav-icon">✉️</span></a>
          </div>
        </nav>
      </div>

      <!-- circular emoji collapse/expand button -->
      <button id="sidebarToggle" class="sidebar-toggle" aria-label="Toggle sidebar">☰</button>

      <div id="sidebarDragHandle" title="Drag to resize"></div>
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
              <div class="dash-icon">📧</div>
              <div class="k-value"><?= $employeeMailCount ?></div>
              <div class="k-card-sub">Employee Send Mail</div>
            </div>

            <div class="k-card status-card dash-colored dash-orange">
              <div class="dash-icon">⏳</div>
              <div class="k-value"><?= $operatorPendingCount ?></div>
              <div class="k-card-sub">Pending</div>
            </div>

            <div class="k-card status-card dash-colored dash-blue">
              <div class="dash-icon">✅</div>
              <div class="k-value"><?= $operatorFilledCount ?></div>
              <div class="k-card-sub">Accepted</div>
            </div>

            <div class="k-card status-card dash-colored dash-red">
              <div class="dash-icon">❌</div>
              <div class="k-value"><?= $operatorRejectedCount ?></div>
              <div class="k-card-sub">non</div>
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

             <?php /* center-hub-cards removed per request — original lines commented out
<div class="center-hub-cards">
  <div class="hub-card"><div class="hub-num"><?= $operatorWorkingCount ?></div><div class="hub-label">Working</div></div>
  <div class="hub-card"><div class="hub-num"><?= $operatorPendingCount ?></div><div class="hub-label">Pending</div></div>
  <div class="hub-card"><div class="hub-num"><?= $operatorFilledCount ?></div><div class="hub-label">Accepted</div></div>
  <div class="hub-card"><div class="hub-num"><?= $operatorNotWorkingCount ?></div><div class="hub-label">Not Working</div></div>
  <div class="hub-card"><div class="hub-num"><?= $total_rows ?></div><div class="hub-label">Total</div></div>
</div>
*/ ?>

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
          <?php /* overview-top-cards removed per request — original lines commented out
<div class="k-card overview-top-cards">
  <div class="overview-card"><div class="card-num"><?= $operatorPendingCount ?></div><div class="card-label">Pending</div></div>
  <div class="overview-card"><div class="card-num"><?= $operatorFilledCount ?></div><div class="card-label">Accepted</div></div>
  <div class="overview-card"><div class="card-num"><?= $operatorRejectedCount ?></div><div class="card-label">Rejected</div></div>
  <div class="overview-card"><div class="card-num"><?= $total_rows ?></div><div class="card-label">Total Operators</div></div>
</div>
*/ ?>


          <div id="overviewTablePlaceholder" style="margin-top:12px"></div>
        </section>

        <!-- Mailing form -->
        <section id="operatorMailingSection" class="k-section hidden">
          <div class="signup mail-form">
            <form method="post" action="employee.php">
              <label for="chk" aria-hidden="true">Employee → Operator Mail</label>
              <div class="mail-row"><input type="text" name="employee_name" placeholder="Employee Name" required><input type="email" name="employee_email" placeholder="Employee Email" required></div>
              <div class="mail-row"><input type="email" name="operator_email" placeholder="Operator Email" required><input type="text" name="aadhaar_id" placeholder="Aadhaar ID" required></div>
              <div class="mail-row"><input type="text" name="unique_id" placeholder="Unique ID" required><input type="text" name="mobile_number" placeholder="Mobile Number" required></div>
              <button class="btn-effect3" type="submit">Submit</button>
            </form>
          </div>
        </section>

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
                      echo "<td class='col-actions nowrap'><button class='small-btn' onclick=\"updateStatus({$id},'accepted')\">Accept</button><button class='small-btn' onclick=\"updateStatus({$id},'pending')\">Pending</button><button class='small-btn' onclick=\"makeRowEditable({$id})\">Edit</button><button class='small-btn' onclick=\"saveRow({$id})\">Save Row</button></td>";
                      echo "</tr>";
                    endwhile;
                  else:
                    echo "<tr><td colspan='200' class='center'>No records found</td></tr>";
                  endif;
                  ?>
                </tbody>
              </table>
            </div>
            <div class="scroll-indicator" aria-hidden="true"><div class="scroll-thumb"></div></div>
          </div>
        </section>

        <footer class="k-footer">
          <span>2025©</span> <span>KeenThemes Inc.</span>
          <div class="spacer"></div>
          <a href="#">Docs</a><a href="#">Purchase</a><a href="#">FAQ</a><a href="#">Support</a><a href="#">License</a>
        </footer>
      </div>
    </main>
  </div>

  <script>
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
        tick.style.transform = `rotate(${angle}deg) translateY(-${(parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--qit-clock-size')||180)*0.4325)||-77}px)`;
        markers.appendChild(tick);
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

    function loadOverview(filter='', page=1) {
      const placeholder = document.getElementById('overviewTablePlaceholder');
      const search = getCurrentSearchParam();
      const params = new URLSearchParams();
      params.set('ajax','1');
      if (filter) params.set('filter', filter);
      if (page) params.set('page', page);
      if (search) params.set('search', search);
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
            exportEl.href = 'em_verfi.php?' + p.toString();
          }
          initAutoScrollAll();
        },
        error: function(xhr, status, err) {
          if (placeholder) placeholder.innerHTML = '<div class="k-card">Error loading overview table</div>';
          console.error('Overview fetch error', status, err, xhr && xhr.responseText);
        }
      });
    }

    // small helpers (AJAX posts)
    function toast(msg){ alert(msg); }
    function updateStatus(id, status) {
      $.post('update_status.php', { id:id, status: status }, function(res){
        if (res && res.success) { const tr = document.getElementById('row-'+id); if (tr) { const sCell = tr.querySelector('td[data-col="status"]'); if (sCell) sCell.textContent = status; } toast(res.message || 'Updated'); }
        else toast('Update failed');
      }, 'json').fail(function(){ toast('Request failed'); });
    }
    function setWork(id, work) {
      $.post('update_status.php', { id:id, work_status: work }, function(res){
        if (res && res.success) { const el = document.getElementById('work-'+id); if (el) el.textContent = work; toast(res.message || 'Work updated'); }
        else toast('Failed');
      }, 'json').fail(function(){ toast('Request failed'); });
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
      if (topBtn && topInp) topBtn.addEventListener('click', function(){ const q = topInp.value.trim(); const url = new URL(window.location.href); const params = url.searchParams; if (q) params.set('search', q); else params.delete('search'); window.location = window.location.pathname + '?' + params.toString(); });

      // initialize donut
      initCharts();

      // calendar input binding + animated style
      const dateInput = document.getElementById('statusDate');
      const dateDisplay = document.querySelector('.sel-date');
      if (dateInput && dateDisplay) {
        dateInput.addEventListener('change', function(){ dateDisplay.textContent = this.value || '—'; });
        const now = new Date(); const s = now.toISOString().slice(0,10); dateInput.value = s; dateDisplay.textContent = s;
      }

      // auto-scroll tables
      initAutoScrollAll();

      // resizable sidebar + toggle
      makeSidebarResizable();
      bindSidebarToggle();

      // clock
      startSmoothClock();
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
 
(function(){
  /* Helper: create panel and preview elements once */
  function createUI() {
    if (document.getElementById('opDetailPanel')) return;
    // Panel
    const panel = document.createElement('div');
    panel.id = 'opDetailPanel';
    panel.innerHTML = `
      <div class="hdr">
        <div class="title">Operator details</div>
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
        panel.querySelectorAll('.tabs button').forEach(b=>b.classList.remove('active'));
        btn.classList.add('active');
        panel.querySelectorAll('.stage').forEach(s => s.classList.toggle('active', s.getAttribute('data-stage')===stage));
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
      ['PAN','pan_number'],
      ['Voter ID','voter_id_no']
  ];
  basicRows.forEach(([k,key])=>{
    const row = document.createElement('div'); row.className='op-row';
    row.innerHTML = `<div class="k">${k}</div><div class="v">${escapeHTML(data[key]||'—')}</div>`;
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
      ['Aadhaar','aadhar_number'],
      ['Current HNo / Street','current_hno_street'],
      ['Current Town','current_village_town'],
      ['Current Pincode','current_pincode'],
      ['Permanent HNo / Street','permanent_hno_street'],
      ['Permanent Town','permanent_village_town'],
      ['Permanent Pincode','permanent_pincode']
  ];
  contactRows.forEach(([k,key])=>{
    const row = document.createElement('div'); row.className='op-row';
    row.innerHTML = `<div class="k">${k}</div><div class="v">${escapeHTML(data[key]||'—')}</div>`;
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
  btn.className = cls;
  btn.type = 'button';
  btn.textContent = label;

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

  // Edit / Save Row
  actions.appendChild(mkBtn('Edit Row', `makeRowEditable(${safeId})`));
  actions.appendChild(mkBtn('Save Row', `saveRow(${safeId})`));

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
    row.innerHTML = `<div class="k">${k}</div><div class="v">${escapeHTML(data[key]||'—')}</div>`;
    docs.appendChild(row);
  });

  // attachments: detect keys ending with _file
  const attWrap = document.createElement('div'); attWrap.className='op-attachments';
  let anyAttach=false;
  Object.keys(data).forEach(k=>{
    if(k.endsWith('_file') && data[k]) {
      anyAttach=true;
      const a=document.createElement('a'); a.href=data[k]; a.target='_blank'; a.textContent = prettifyFilename(data[k]); a.style.marginRight='6px';
      attWrap.appendChild(a);
    }
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

  // show panel and default tab
  showPanel();
  panel.querySelector('.tabs button[data-stage="basic"]').click();
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


</script>

</body>
</html>
