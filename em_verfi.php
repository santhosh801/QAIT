<?php
session_start();

// ✅ 1. Ensure login
if (!isset($_SESSION['employee_email'])) {
    header("Location: employee_login.php");
    exit;
}

// ✅ 2. Database connection
$mysqli = new mysqli("localhost", "root", "", "qmit_system");
if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}

// ✅ 4. Search & Pagination (search now lives in HEADER, same GET param)
$limit  = 10;
$page   = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$search = isset($_GET['search']) ? $mysqli->real_escape_string($_GET['search']) : '';
$offset = ($page - 1) * $limit;

$where = "";
if ($search) {
    $where = "WHERE operator_full_name LIKE '%$search%' OR email LIKE '%$search%' OR operator_id LIKE '%$search%'";
}

// ✅ 7. Export CSV on demand (preserves search filter)
if (isset($_GET['export']) && $_GET['export'] === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=operator_details.csv');

    $out = fopen('php://output', 'w');
    // fetch columns
    $colsRes = $mysqli->query("SELECT * FROM operatordoc $where LIMIT 1");
    if ($colsRes && $colsRes->num_rows) {
        $headers = array_keys($colsRes->fetch_assoc());
        fputcsv($out, array_merge($headers, ['Actions'])); // keep parity with table
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

// ✅ 3. Counts for cards
$employeeMailCount     = $mysqli->query("SELECT COUNT(*) AS total FROM employees")->fetch_assoc()['total'] ?? 0;
$operatorPendingCount  = $mysqli->query("SELECT COUNT(*) AS total FROM operatordoc WHERE status='pending'")->fetch_assoc()['total'] ?? 0;
$operatorFilledCount   = $mysqli->query("SELECT COUNT(*) AS total FROM operatordoc WHERE status='accepted'")->fetch_assoc()['total'] ?? 0;
$operatorRejectedCount = $mysqli->query("SELECT COUNT(*) AS total FROM operatordoc WHERE status='rejected'")->fetch_assoc()['total'] ?? 0;

// Count rows for pagination
$total_result = $mysqli->query("SELECT COUNT(*) as total FROM operatordoc $where")->fetch_assoc();
$total_rows   = $total_result['total'] ?? 0;
$total_pages  = max(1, ceil($total_rows / $limit));

// Fetch rows for table
$sql    = "SELECT * FROM operatordoc $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset";
$result = $mysqli->query($sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Employee KYC Portal</title>

  <!-- Your existing base stylesheet -->
  <link rel="stylesheet" href="em_verfi.css">

  <!-- Metronic-like light theme + Q•IT header + search + button + fixes -->
  <link rel="stylesheet" href="css/em_verfi.metronic.css">

  <script src="https://unpkg.com/feather-icons"></script>
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>

  <!-- ============ Q•IT HEADER (replaces previous topbar) ============ -->
  <header class="qit-header">
    <div class="container">
      <!-- Logo -->
      <div class="logo">
        <div class="logo-grid">
          <span class="sq1"></span>
          <span class="sq2"></span>
          <span class="sq3"></span>
          <span class="sq4"></span>
        </div>
        <span class="logo-text">
          <span class="big-q">Q</span><span class="small-it">IT</span>
        </span>
      </div>

      <!-- Navigation -->
      <nav class="qit-nav">
        <ul>
          <li><a href="#features">How it Works</a></li>
          <li><a href="#contact">Contact</a></li>
          <li><a href="#support">Support</a></li>
          <li class="dropdown">
            <a href="#login-section">Login ▾</a>
            <ul class="dropdown-menu"></ul>
          </li>
        </ul>
      </nav>

      <!-- Animated search (controls the same ?search=) -->
      <form class="qit-search" action="em_verfi.php" method="get">
        <input type="search" name="search" placeholder="Search operator…" value="<?= htmlspecialchars($search) ?>"
               pattern=".{1,}" required />
        <button class="search-btn" type="submit" aria-label="Search"><span>Search</span></button>
      </form>
    </div>
  </header>

  <!-- ============ Shell Layout (light sidebar smaller) ============ -->
  <div class="k-shell">
    <!-- Sidebar -->
    <aside class="k-sidebar">
      <div class="k-sb-wrap">
        <div class="k-sb-group">
          <div class="k-sb-title">Dashboards</div>
          <nav class="k-nav">
            <a href="#" data-section="dashboard" class="is-active"><i data-feather="layout"></i> Light Sidebar</a>
            <a href="#"><i data-feather="moon"></i> Dark Sidebar <span class="badge">Soon</span></a>
          </nav>
        </div>

        <div class="k-sb-group">
          <div class="k-sb-title">User</div>
          <nav class="k-nav">
            <a href="#" data-section="operatorStatus"><i data-feather="activity"></i> Operator Status</a>
            <a href="#" data-section="operatorProfile"><i data-feather="user"></i> Operator Profile</a>
            <a href="#"><i data-feather="edit-3"></i> Op Details Editing <span class="badge">Soon</span></a>
            <!-- Removed: Store – Client, AI Prompt (per task 6) -->
          </nav>
        </div>
      </div>
    </aside>

    <!-- Content -->
    <main class="k-content">
      <div class="k-container">

        <!-- Page head -->
        <div class="k-page-head">
          <div>
            <h1 class="k-h1">KYC Dashboard</h1>
            <div class="k-subtitle">Central Hub for Personal Customization</div>
          </div>
          <div class="k-cta">
            <!-- Buttons use the requested Button-54 style -->
            <a href="em_verfi.php?export=1<?= $search ? '&search='.urlencode($search) : '' ?>" class="button-54">Export</a>
            <button type="button" class="button-54" onclick="alert('Saved!')">Save Changes</button>
          </div>
        </div>

        <!-- Operator Status cards -->
        <div id="operatorStatusSection" class="hidden k-section">
          <div class="k-cards">
            <div class="k-card"><div class="k-value timer" data-to="<?= $employeeMailCount ?>" data-speed="2000">0</div><div class="k-sub">Employee Send Mail</div></div>
            <div class="k-card"><div class="k-value timer" data-to="<?= $operatorPendingCount ?>" data-speed="2000">0</div><div class="k-sub">Operator Pending</div></div>
            <div class="k-card"><div class="k-value timer" data-to="<?= $operatorFilledCount ?>" data-speed="2000">0</div><div class="k-sub">Operator Accepted</div></div>
            <div class="k-card"><div class="k-value timer" data-to="<?= $operatorRejectedCount ?>" data-speed="2000">0</div><div class="k-sub">Rejected</div></div>
          </div>
        </div>

        <!-- Removed the old in-page search form (per task 8) -->

        <!-- Table -->
        <section class="k-section" id="employeeTable">
          <div class="scrollable rounded-2xl shadow-lg">
            <table class="min-w-full text-left text-gray-700 border-collapse">
              <thead>
                <tr>
                  <?php
                    $columns = $result ? $result->fetch_fields() : [];
                    foreach ($columns as $col) {
                        echo "<th class='px-6 py-3 border-b'>{$col->name}</th>";
                    }
                  ?>
                  <th class="px-6 py-3 border-b">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php
                if ($result && $result->num_rows > 0):
                  $result->data_seek(0);
                  while ($row = $result->fetch_assoc()): ?>
                    <tr id="row-<?= $row['id'] ?>">
                      <?php foreach ($row as $col => $val): ?>
                        <td class="px-6 py-3 border-b">
                          <?php if (str_ends_with($col, '_file') && $val): ?>
                            <a href="<?= htmlspecialchars($val) ?>" target="_blank">View</a>
                          <?php else: ?>
                            <?= htmlspecialchars($val) ?>
                          <?php endif; ?>
                        </td>
                      <?php endforeach; ?>
                      <td class="px-6 py-3 border-b">
                        <button class="btn btn-outline-success" onclick="updateStatus(<?= $row['id'] ?>,'accepted')">Accept</button>
                        <button class="btn btn-outline-warning" onclick="updateStatus(<?= $row['id'] ?>,'pending')">Pending</button>
                        <button class="btn btn-outline-danger" onclick="updateStatus(<?= $row['id'] ?>,'rejected')">Reject</button>
                      </td>
                    </tr>
                <?php endwhile; else: ?>
                    <tr><td colspan="100%" class="text-center py-4">No records found</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <!-- Pagination -->
          <div class="k-pagination">
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
              <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>"
                 class="k-page <?= $i == $page ? 'is-current' : '' ?>">
                 <?= $i ?>
              </a>
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
    function updateStatus(id, status) {
      $.post("update_status.php", { id: id, status: status }, function(response) {
        alert(response.message);
        location.reload();
      }, "json");
    }

    function showSection(sectionId) {
      document.getElementById("employeeTable").classList.add("hidden");
      document.getElementById("operatorStatusSection").classList.add("hidden");
      document.getElementById("operatorProfileSection").classList.add("hidden");
      document.getElementById(sectionId).classList.remove("hidden");

      if (sectionId === "operatorStatusSection") {
        const counters = document.querySelectorAll(".timer");
        counters.forEach(counter => {
          let target = +counter.getAttribute("data-to");
          let speed  = +counter.getAttribute("data-speed");
          let count = 0;
          let step = target / (speed / 50);
          let interval = setInterval(() => {
            count += step;
            if (count >= target) {
              counter.textContent = target;
              clearInterval(interval);
            } else {
              counter.textContent = Math.floor(count);
            }
          }, 50);
        });
      }
    }

    // Sidebar nav switching + active highlight
    document.querySelectorAll('.k-nav a[data-section]').forEach(a=>{
      a.addEventListener('click', e=>{
        e.preventDefault();
        document.querySelectorAll('.k-nav a').forEach(x=>x.classList.remove('is-active'));
        a.classList.add('is-active');
        const id = a.getAttribute('data-section');
        if(id==='dashboard'){ showSection('employeeTable'); }
        else if(id==='operatorStatus'){ showSection('operatorStatusSection'); }
        else if(id==='operatorProfile'){ showSection('operatorProfileSection'); }
      });
    });

    feather.replace();
  </script>
</body>
</html>
