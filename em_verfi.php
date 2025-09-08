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
$filter = isset($_GET['filter']) ? $_GET['filter'] : ''; // e.g. 'working','not working','pending','accepted','rejected'

// Build where clause (search + filter)
$whereClauses = [];
if ($search !== '') {
    $s = $mysqli->real_escape_string($search);
    $whereClauses[] = "(operator_full_name LIKE '%$s%' OR email LIKE '%$s%' OR operator_id LIKE '%$s%')";
}
if ($filter !== '') {
    // filter can be status or work_status
    if (in_array($filter, ['pending','accepted','rejected'])) {
        $whereClauses[] = "status = '" . $mysqli->real_escape_string($filter) . "'";
    } elseif (in_array($filter, ['working','not working'])) {
        $whereClauses[] = "work_status = '" . $mysqli->real_escape_string($filter) . "'";
    }
}

$where = '';
if (!empty($whereClauses)) {
    $where = 'WHERE ' . implode(' AND ', $whereClauses);
}

// ---------- AJAX fragment endpoint (table only) ----------
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    // Recompute counts and pagination for filtered set
    $countRes = $mysqli->query("SELECT COUNT(*) as total FROM operatordoc $where");
    $total_rows = ($countRes ? ($countRes->fetch_assoc()['total'] ?? 0) : 0);
    $total_pages = max(1, ceil($total_rows/$limit));
    // fetch rows
    $sql = "SELECT * FROM operatordoc $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset";
    $res = $mysqli->query($sql);
    // Output only the HTML fragment used inside "overview"
    ?>
    <div class="k-card" style="overflow:auto">
      <table class="table table-bordered" style="width:100%;border-collapse:collapse">
        <thead style="background:#f8fafc">
          <tr>
            <?php
              // get columns for header
              $colsRes = $mysqli->query("SELECT * FROM operatordoc LIMIT 1");
              $colNames = [];
              if ($colsRes && $colsRes->num_rows) {
                  $fields = $colsRes->fetch_fields();
                  foreach ($fields as $f) { $colNames[] = $f->name; echo "<th style='border-bottom:1px solid #e6e9ef;padding:8px'>{$f->name}</th>"; }
              }
            ?>
            <th style="border-bottom:1px solid #e6e9ef;padding:8px">Review</th>
            <th style="border-bottom:1px solid #e6e9ef;padding:8px">Work</th>
            <th style="border-bottom:1px solid #e6e9ef;padding:8px">Actions</th>
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
                      $display = '';
                      if (str_ends_with($col, '_file') && $val) {
                          $display = '<a href="'.htmlspecialchars($val).'" target="_blank">View</a>';
                      } else {
                          $display = htmlspecialchars((string)$val);
                      }
                      // data-col attribute required for inline editing
                      echo "<td data-col=\"" . htmlspecialchars($col, ENT_QUOTES) . "\" style='padding:8px;border-bottom:1px solid #eef2f6;max-width:200px;overflow:hidden;text-overflow:ellipsis'>{$display}</td>";
                  }

                  // review notes
                  $rv = htmlspecialchars($row['review_notes'] ?? '', ENT_QUOTES);
                  echo "<td style='padding:8px;border-bottom:1px solid #eef2f6'><input id='review-{$id}' value=\"{$rv}\" style='width:200px;padding:6px;border-radius:4px;border:1px solid #ddd' /><br><button onclick='saveReview({$id})' class='small-btn'>Save</button></td>";

                  // work status
                  $ws = htmlspecialchars($row['work_status'] ?? 'working', ENT_QUOTES);
                  echo "<td style='padding:8px;border-bottom:1px solid #eef2f6'><div id='work-{$id}'><strong>{$ws}</strong></div>";
                  echo "<div style='margin-top:6px'><button class='small-btn' onclick=\"setWork({$id},'working')\">Working</button>";
                  echo "<button class='small-btn' onclick=\"setWork({$id},'not working')\">Not Working</button></div></td>";

                  // actions: accept/pending/reject + edit + save row
                  echo "<td class='col-actions' style='padding:8px;border-bottom:1px solid #eef2f6'>";
                  echo "<button class='small-btn' onclick=\"updateStatus({$id},'accepted')\">Accept</button>";
                  echo "<button class='small-btn' onclick=\"updateStatus({$id},'pending')\">Pending</button>";
                  echo "<button class='small-btn' onclick=\"updateStatus({$id},'rejected')\">Reject</button>";
                  echo "<button class='small-btn' onclick=\"makeRowEditable({$id})\">Edit</button>";
                  echo "<button class='small-btn' onclick=\"saveRow({$id})\">Save Row</button>";
                  echo "</td>";

                  echo "</tr>";
              }
          } else {
              echo "<tr><td colspan='200' style='padding:14px;text-align:center'>No records found</td></tr>";
          }
          ?>
        </tbody>
      </table>

      <!-- pagination inside fragment -->
      <div style="margin-top:10px">
        <?php for ($p = 1; $p <= $total_pages; $p++): ?>
          <a href="#" class="overview-page" data-page="<?= $p ?>"><?= $p ?></a>
        <?php endfor; ?>
      </div>
    </div>
    <?php
    exit;
}

// -----------------------------
// Export CSV (existing behavior keeps 'Actions' header appended) - unchanged
// -----------------------------
if (isset($_GET['export']) && $_GET['export'] === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=operator_details.csv');

    $out = fopen('php://output', 'w');
    // fetch columns
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
// Dashboard counts
// -----------------------------
$employeeMailCount     = $mysqli->query("SELECT COUNT(*) AS total FROM employees")->fetch_assoc()['total'] ?? 0;
$operatorPendingCount  = $mysqli->query("SELECT COUNT(*) AS total FROM operatordoc WHERE status='pending'")->fetch_assoc()['total'] ?? 0;
$operatorFilledCount   = $mysqli->query("SELECT COUNT(*) AS total FROM operatordoc WHERE status='accepted'")->fetch_assoc()['total'] ?? 0;
$operatorRejectedCount = $mysqli->query("SELECT COUNT(*) AS total FROM operatordoc WHERE status='rejected'")->fetch_assoc()['total'] ?? 0;

// count rows / pages for main non-ajax page
$total_result = $mysqli->query("SELECT COUNT(*) as total FROM operatordoc $where")->fetch_assoc();
$total_rows   = $total_result['total'] ?? 0;
$total_pages  = max(1, ceil($total_rows / $limit));

// fetch rows for main page table (first page or current page)
$sql_main = "SELECT * FROM operatordoc $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset";
$result = $mysqli->query($sql_main);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>Employee KYC Portal</title>
<!-- CSS: canonical, cache-busted -->
<link rel="stylesheet" href="em_verfi.css?v=<?=file_exists('em_verfi.css') ? filemtime('em_verfi.css') : time()?>" type="text/css">
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://unpkg.com/feather-icons"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

 
</head>
<body>
  <!-- header -->
  <header class="qit-header">
    <div class="container">
      <div class="logo">Q•IT</div>

      <form action="em_verfi.php" method="get" style="display:inline-block;margin-left:12px">
        <input type="search" name="search" placeholder="Search operator…" value="<?= htmlspecialchars($search) ?>" />
        <button type="submit">Search</button>
      </form>

      <div style="float:right">
        <a href="em_verfi.php?export=1<?= $search ? '&search='.urlencode($search) : '' ?>">Export CSV</a>
      </div>
    </div>
  </header>

  <div class="k-shell" style="display:flex;gap:12px;padding:12px">
    <aside style="width:220px">
      <div class="k-card">
        <div style="font-weight:700;margin-bottom:6px">Operator</div>

        <nav>
          <a href="#" data-section="operatorStatusSection" class="is-active">Operator Status</a><br>
          <a href="#" data-section="operatorOverviewSection" data-filter="">OP DATA (All)</a><br>

          <div style="margin-top:8px;font-weight:600">Working</div>
          <a href="#" data-section="operatorOverviewSection" class="k-subitem" data-filter="working">Working</a><br>
          <a href="#" data-section="operatorOverviewSection" class="k-subitem" data-filter="pending">Pending</a><br>
          <a href="#" data-section="operatorOverviewSection" class="k-subitem" data-filter="accepted">Accepted</a><br>

          <div style="margin-top:8px;font-weight:600">Not Working</div>
          <a href="#" data-section="operatorOverviewSection" class="k-subitem" data-filter="not working">Not Working</a><br>

          <div style="margin-top:10px">
            <a href="#" data-section="operatorMailingSection">Operator Mailing</a>
          </div>
        </nav>
      </div>
    </aside>

    <main style="flex:1">
      <div class="k-container">
        <div class="k-page-head" style="display:flex;justify-content:space-between;align-items:center">
          <div><h1 style="margin:0">KYC Dashboard</h1><div style="color:#6b7280">Central Hub</div></div>
          <div>
            <button onclick="alert('placeholder')">Save Changes</button>
          </div>
        </div>

        <!-- status cards -->
        <section id="operatorStatusSection" class="k-section" style="margin-top:12px">
          <div style="display:flex;gap:12px">
            <div class="k-card"><?= (int)$employeeMailCount ?><div>Employee Send Mail</div></div>
            <div class="k-card"><?= (int)$operatorPendingCount ?><div>Pending</div></div>
            <div class="k-card"><?= (int)$operatorFilledCount ?><div>Accepted</div></div>
            <div class="k-card"><?= (int)$operatorRejectedCount ?><div>Rejected</div></div>
          </div>
        </section>

        <!-- overview (AJAX loaded area) -->
        <section id="operatorOverviewSection" class="k-section hidden" style="margin-top:14px">
          <div class="k-card"><h2 style="margin:0">Operator Overview</h2></div>
          <div id="overviewFilterInfo" style="color:#6b7280;margin-top:8px"></div>
          <div id="lightDashboard" class="soft-fade-in" style="margin-top:8px">
            <div class="k-card">Click "OP DATA" or filters on the left to load the operator table here.</div>
          </div>
        </section>

        <!-- mailing -->
        <section id="operatorMailingSection" class="k-section hidden" style="margin-top:12px">
          <div class="k-card">
            <h3>Operator Mailing</h3>
            <form method="post" action="employee.php">
              <input name="employee_name" placeholder="Employee name" required><br>
              <input name="employee_email" placeholder="Employee email" required><br>
              <button>Send</button>
            </form>
          </div>
        </section>

        <!-- main table (non-AJAX initial view) -->
        <section id="employeeTable" class="k-section" style="margin-top:12px">
          <div class="k-card" style="overflow:auto">
            <table border="0" cellpadding="6" cellspacing="0" style="width:100%;border-collapse:collapse">
              <thead style="background:#f8fafc">
                <tr>
                  <?php
                    // headers
                    $cols = $result ? $result->fetch_fields() : [];
                    $colNames = [];
                    foreach ($cols as $c) { $colNames[] = $c->name; echo "<th style='padding:8px;border-bottom:1px solid #e6e9ef'>" . htmlspecialchars($c->name) . "</th>"; }
                  ?>
                  <th style='padding:8px;border-bottom:1px solid #e6e9ef'>Review</th>
                  <th style='padding:8px;border-bottom:1px solid #e6e9ef'>Work</th>
                  <th style='padding:8px;border-bottom:1px solid #e6e9ef'>Actions</th>
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
                        if (str_ends_with($col, '_file') && $val) $display = '<a href="'.htmlspecialchars($val).'" target="_blank">View</a>';
                        else $display = htmlspecialchars((string)$val);
                        echo "<td data-col=\"" . htmlspecialchars($col, ENT_QUOTES) . "\" style='padding:8px;border-bottom:1px solid #eef2f6;max-width:200px;overflow:hidden;text-overflow:ellipsis'>{$display}</td>";
                    }
                    // review
                    $rv = htmlspecialchars($row['review_notes'] ?? '', ENT_QUOTES);
                    echo "<td style='padding:8px;border-bottom:1px solid #eef2f6'><input id='review-{$id}' value=\"{$rv}\" style='width:200px;padding:6px;border-radius:4px;border:1px solid #ddd' /><br><button class='small-btn' onclick='saveReview({$id})'>Save</button></td>";
                    // work
                    $ws = htmlspecialchars($row['work_status'] ?? 'working', ENT_QUOTES);
                    echo "<td style='padding:8px;border-bottom:1px solid #eef2f6'><div id='work-{$id}'><strong>{$ws}</strong></div>";
                    echo "<div style='margin-top:6px'><button class='small-btn' onclick=\"setWork({$id},'working')\">Working</button>";
                    echo "<button class='small-btn' onclick=\"setWork({$id},'not working')\">Not Working</button></div></td>";
                    // actions
                    echo "<td class='col-actions' style='padding:8px;border-bottom:1px solid #eef2f6'>";
                    echo "<button class='small-btn' onclick=\"updateStatus({$id},'accepted')\">Accept</button>";
                    echo "<button class='small-btn' onclick=\"updateStatus({$id},'pending')\">Pending</button>";
                    echo "<button class='small-btn' onclick=\"updateStatus({$id},'rejected')\">Reject</button>";
                    echo "<button class='small-btn' onclick=\"makeRowEditable({$id})\">Edit</button>";
                    echo "<button class='small-btn' onclick=\"saveRow({$id})\">Save Row</button>";
                    echo "</td>";
                    echo "</tr>";
                  endwhile;
                else:
                  echo "<tr><td colspan='200' style='padding:14px;text-align:center'>No records found</td></tr>";
                endif;
                ?>
              </tbody>
            </table>
          </div>

          <!-- pagination -->
          <div style="margin-top:10px">
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
              <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>" style="margin-right:6px;padding:6px 8px;border-radius:6px;<?= $i==$page ? 'background:#eef' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
          </div>
        </section>

        <!-- Operator Profile -->
        <div id="operatorProfileSection" class="hidden k-section">
          <h2 class="k-h2">Operator Profile</h2>
          <p class="k-muted">Profile details will go here.</p>
        </div>

        <!-- Footer mimic -->
        <footer class="k-footer">
          <span>2025©</span> <span>KeenThemes Inc.</span>
          <div class="spacer"></div>
          <a href="#">Docs</a><a href="#">Purchase</a><a href="#">FAQ</a><a href="#">Support</a><a href="#">License</a>
        </footer>
      </div>
    </main>
  </div>

  <script>
    // small toast replacement
    function toast(msg){ alert(msg); }

    // update status (status = accepted|pending|rejected)
    function updateStatus(id, status) {
      $.post('update_status.php', { id:id, status: status }, function(res){
        if (res && res.success) {
          // update cell
          const tr = document.getElementById('row-'+id);
          if (tr) {
            const sCell = tr.querySelector('td[data-col="status"]');
            if (sCell) sCell.textContent = status;
          }
          toast(res.message || 'Updated');
        } else {
          toast('Update failed: ' + (res && res.message ? res.message : 'unknown'));
        }
      }, 'json').fail(function(){ toast('Request failed'); });
    }

    // set work status (working | not working)
    function setWork(id, work) {
      $.post('update_status.php', { id:id, work_status: work }, function(res){
        if (res && res.success) {
          const el = document.getElementById('work-'+id);
          if (el) el.textContent = work;
          // optionally refresh overview counts (not implemented)
          toast(res.message || 'Work status updated');
        } else {
          toast('Failed');
        }
      }, 'json').fail(function(){ toast('Request failed'); });
    }

    // save review text
    function saveReview(id) {
      const el = document.getElementById('review-'+id);
      if (!el) return;
      const notes = el.value;
      $.post('update_review.php', { id: id, notes: notes }, function(res){
        if (res && res.success) toast(res.message || 'Saved');
        else toast('Save failed: ' + (res && res.message ? res.message : 'unknown'));
      }, 'json').fail(function(){ toast('Request failed'); });
    }

    // make row editable
    function makeRowEditable(id) {
      const tr = document.getElementById('row-'+id);
      if (!tr) return;
      tr.querySelectorAll('td[data-col]').forEach(td=>{
        const col = td.getAttribute('data-col');
        if (!col) return;
        // don't make file columns or created_at editable
        if (col.endsWith('_file') || col === 'created_at') return;
        const cur = td.textContent.trim();
        const input = document.createElement('input');
        input.value = cur;
        input.setAttribute('data-edit','1');
        input.style.width = '100%';
        td.innerHTML = '';
        td.appendChild(input);
      });
      toast('Row editable — make changes and click Save Row');
    }

    // save edited row
    function saveRow(id) {
      const tr = document.getElementById('row-'+id);
      if (!tr) return;
      const payload = { id: id };
      tr.querySelectorAll('td[data-col]').forEach(td=>{
        const col = td.getAttribute('data-col');
        if (!col) return;
        const input = td.querySelector('input[data-edit]');
        if (input) payload[col] = input.value;
      });
      // send
      $.post('update_row.php', payload, function(res){
        if (res && res.success) {
          toast(res.message || 'Saved');
          // simplest: reload that fragment or whole page. We'll reload overview fragment if visible else reload page.
          if (!document.getElementById('operatorOverviewSection').classList.contains('hidden')) {
            // reload current overview filter
            const info = document.getElementById('overviewFilterInfo');
            // use the same filter used to load overview — for simplicity reload entire page
            location.reload();
          } else {
            location.reload();
          }
        } else {
          toast('Save failed: ' + (res && res.message ? res.message : 'unknown'));
        }
      }, 'json').fail(function(){ toast('Request failed'); });
    }

    // loadOverview uses this page's AJAX endpoint
    function getCurrentSearchParam() {
      try { const url = new URL(window.location.href); return url.searchParams.get('search') || ''; } catch(e){ return ''; }
    }
    function loadOverview(filter='', page=1) {
      const dashboard = document.getElementById('lightDashboard');
      const info = document.getElementById('overviewFilterInfo');
      const search = getCurrentSearchParam();
      const params = new URLSearchParams();
      if (filter) params.set('filter', filter);
      if (page) params.set('page', page);
      if (search) params.set('search', search);
      params.set('ajax', '1');
      const url = 'em_verfi.php?' + params.toString();
      if (info) info.textContent = filter ? 'Filter: ' + filter : 'Showing: Full Operator Overview';
      if (dashboard) dashboard.innerHTML = '<div class="k-card">Loading…</div>';
      $.ajax({
        url: url, method: 'GET', dataType: 'html', cache: false,
        success: function(html) {
          if (dashboard) dashboard.innerHTML = html;
          // bind overview-page links in the loaded fragment
          if (dashboard) {
            dashboard.querySelectorAll('.overview-page').forEach(a=>{
              a.addEventListener('click', function(e){
                e.preventDefault();
                const p = this.getAttribute('data-page') || 1;
                loadOverview(filter, p);
              });
            });
          }
        },
        error: function(xhr, status, err) {
          if (dashboard) dashboard.innerHTML = '<div class="k-card">Error loading overview</div>';
          console.error('Overview fetch error', status, err, xhr && xhr.responseText);
        }
      });
    }

    // sidebar behaviour + initial bindings
    document.addEventListener('DOMContentLoaded', function(){
      feather.replace();
      // default: show status section
      showSection('operatorStatusSection');

      document.querySelectorAll('[data-section]').forEach(el=>{
        el.addEventListener('click', function(e){
          e.preventDefault();
          const section = el.getAttribute('data-section');
          const fil = el.getAttribute('data-filter') || '';
          showSection(section);
          document.querySelectorAll('[data-section]').forEach(x=>x.classList.remove('is-active'));
          el.classList.add('is-active');
          if (section === 'operatorOverviewSection') {
            loadOverview(fil, 1);
          }
        });
      });
    });

    // helper to show/hide sections
    function showSection(sectionId) {
      const sections = ['employeeTable','operatorStatusSection','operatorProfileSection','operatorOverviewSection','operatorMailingSection'];
      sections.forEach(s => {
        const el = document.getElementById(s);
        if (el) el.classList.add('hidden');
      });
      const target = document.getElementById(sectionId);
      if (target) target.classList.remove('hidden');
    }

  </script>
</body>
</html>
