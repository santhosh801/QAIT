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

// ✅ 3. Example counts for operatorStatusSection
// ✅ Counts
$employeeMailCount = $mysqli->query("SELECT COUNT(*) AS total FROM employees")->fetch_assoc()['total'] ?? 0;
$operatorPendingCount = $mysqli->query("SELECT COUNT(*) AS total FROM operatordoc WHERE status='pending'")->fetch_assoc()['total'] ?? 0;
$operatorFilledCount = $mysqli->query("SELECT COUNT(*) AS total FROM operatordoc WHERE status='accepted'")->fetch_assoc()['total'] ?? 0;
$operatorRejectedCount = $mysqli->query("SELECT COUNT(*) AS total FROM operatordoc WHERE status='rejected'")->fetch_assoc()['total'] ?? 0;


// ✅ 4. Search & Pagination
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$search = isset($_GET['search']) ? $mysqli->real_escape_string($_GET['search']) : '';
$offset = ($page - 1) * $limit;

$where = "";
if ($search) {
    $where = "WHERE operator_full_name LIKE '%$search%' OR email LIKE '%$search%' OR operator_id LIKE '%$search%'";
}

// Count rows

$total_result = $mysqli->query("SELECT COUNT(*) as total FROM operatordoc $where")->fetch_assoc();
$total_rows = $total_result['total'];
$total_pages = ceil($total_rows / $limit);

// Fetch rows
$sql = "SELECT * FROM operatordoc $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset";
$result = $mysqli->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Employee KYC Portal</title>
  <link rel="stylesheet" href="css/em_verfi.css">
  <script src="https://unpkg.com/feather-icons"></script>
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>

<body>

<div class="flex h-screen text-gray-900">
  <!-- Sidebar -->
  <ul class="w-100 sidebar-nav">
  <aside class="hidden lg:block w-80 flex-shrink-0">
    <header class="mt-16 flex justify-center">
      <div class="flex justify-between items-center w-56">
        <span class="text-3xl font-bold pb-1">KYC Portal</span>
        <span class="bg"></span>
        <button class="bg-purple-10 text-blue-800 w-14 h-5 ml-8 rounded-full flex items-center justify-center">
          <i data-feather="database"></i>
        </button>
      </div>
    </header>

    <nav class="mt-20 flex justify-center">
      <div class="flex justify-between items-center w-56">
        <ul class="w-full">
          <li class="active">
            <a href="#" data-section="dashboard" class="flex rounded-xl p-1 items-center">
              <span class="icon-container text-purple-700 p-4 rounded-xl">
                <i data-feather="layout" width="24" height="24"></i>
              </span>
              <span class="bg"></span>
              <span class="ml-6 font-medium">Em Dashboard</span>
            </a>
          </li>
          <li class="mt-3">
            <a href="#" data-section="operatorStatus" class="flex rounded-xl p-1 items-center">
              <span class="icon-container text-purple-500 p-4 rounded-xl">
                <i data-feather="user" width="24" height="24"></i>
              </span>
              <span class="bg"></span>
              <span class="ml-6 font-medium text-gray-600">Op Status</span>
            </a>
          </li>
          <li class="mt-3">
            <a href="#" class="flex rounded-xl p-1 items-center">
              <span class="icon-container text-purple-500 p-4 rounded-xl">
                <i data-feather="edit-3" width="24" height="24"></i>
              </span>
              <span class="bg"></span>
              <span class="ml-6 font-medium text-gray-600">Op Details Editing</span>
            </a>
          </li>
          <li class="mt-3">
            <a href="#" data-section="operatorProfile" class="flex rounded-xl p-1 items-center">
              <span class="icon-container text-purple-500 p-4 rounded-xl">
                <i data-feather="users" width="24" height="24"></i>
              </span>
              <span class="bg"></span>
              <span class="ml-6 font-medium text-gray-600">Em logins</span>
            </a>
          </li>
        </ul>
      </div>
    </nav>
  </aside>
  </ul>

<div id="particle-container">
  <?php for ($i=0;$i<30;$i++): ?><div class="particle"></div><?php endfor; ?>
</div>

<!-- Main Content -->
<section class="bg-gray-200 flex-grow rounded-3xl p-8">
  <header class="flex justify-between items-center">
    <h1 class="text-4xl font-bold animated rubberBand">Employee KYC Dashboard</h1>
    <div>
      <button class="bg-purple-700 text-white px-6 py-2 rounded-xl mr-2 animated rubberBand">Download</button>
      <button class="bg-green-600 text-white px-6 py-2 rounded-xl animated rubberBand">Save Changes</button>
    </div>
  </header>

  <!-- Operator Status Section -->
  <div id="operatorStatusSection" class="hidden mt-6">
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
      <div class="bg-purple-100 rounded-xl shadow-lg p-6 text-center">
        <h3 class="text-3xl font-bold text-purple-700 timer" data-to="<?= $employeeMailCount ?>" data-speed="2000">0</h3>
        <p class="mt-2 font-medium text-gray-700">Employee Send Mail</p>
      </div>

      <div class="bg-yellow-100 rounded-xl shadow-lg p-6 text-center">
        <h3 class="text-3xl font-bold text-yellow-600 timer" data-to="<?= $operatorPendingCount ?>" data-speed="2000">0</h3>
        <p class="mt-2 font-medium text-gray-700">Operator Pending</p>
      </div>
      
      <div class="bg-green-100 rounded-xl shadow-lg p-6 text-center">
        <h3 class="text-3xl font-bold text-green-600 timer" data-to="<?= $operatorFilledCount ?>" data-speed="2000">0</h3>
        <p class="mt-2 font-medium text-gray-700">Operator Accepted</p>
      </div>
      <div class="bg-red-100 rounded-xl shadow-lg p-6 text-center">
  <h3 class="text-3xl font-bold text-red-600 timer" data-to="<?= $operatorRejectedCount ?>" data-speed="2000">0</h3>
  <p class="mt-2 font-medium text-gray-700">Rejected</p>
</div>

    </div>
  </div>

  <!-- Search -->
  <form method="get" class="mt-6 flex">
    <input type="text" name="search" placeholder="Search operator..."
           value="<?= htmlspecialchars($search) ?>"
           class="px-4 py-2 border rounded-l-lg w-64">
    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-r-lg">Search</button>
  </form>

  <!-- Table -->
  <main class="mt-10" id="employeeTable">
    <div class="overflow-x-auto bg-white rounded-2xl shadow-lg p-6">
      <div class="scrollable">
        <div class="mask">
          <table class="min-w-full text-left text-gray-700 border-collapse">
            <thead>
              <tr>
                <?php
                $columns = $result->fetch_fields();
                foreach ($columns as $col) {
                    echo "<th class='px-6 py-3 border-b'>{$col->name}</th>";
                }
                ?>
                <th class="px-6 py-3 border-b">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php
              $result->data_seek(0);
              if ($result->num_rows > 0):
                while ($row = $result->fetch_assoc()): ?>
                  <tr id="row-<?= $row['id'] ?>">
                    <?php foreach ($row as $col => $val): ?>
                      <td class="px-6 py-4 border-b">
                        <?php if (str_ends_with($col, '_file') && $val): ?>
                          <a href="<?= htmlspecialchars($val) ?>" target="_blank">View</a>
                        <?php else: ?>
                          <?= htmlspecialchars($val) ?>
                        <?php endif; ?>
                      </td>
                    <?php endforeach; ?>
                    <td class="px-6 py-4 border-b">
                      <button class="btn btn-outline-success" onclick="updateStatus(<?= $row['id'] ?>,'accepted')">Accept</button>
                      <button class="btn btn-outline-warning" onclick="updateStatus(<?= $row['id'] ?>,'pending')">Pending</button>
                      <button class="btn btn-outline-danger" onclick="updateStatus(<?= $row['id'] ?>,'rejected')">Reject</button>
                    </td>
                  </tr>
                <?php endwhile;
              else: ?>
                <tr><td colspan="100%" class="text-center py-4">No records found</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Pagination -->
    <div class="mt-4 flex space-x-2">
      <?php for ($i = 1; $i <= $total_pages; $i++): ?>
        <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>"
           class="px-4 py-2 border rounded <?= $i == $page ? 'bg-blue-600 text-white' : 'bg-white' ?>">
           <?= $i ?>
        </a>
      <?php endfor; ?>
    </div>
  </main>

  <!-- Operator Profile -->
  <div id="operatorProfileSection" class="hidden mt-6">
    <h2 class="text-2xl font-bold">Operator Profile</h2>
    <p class="mt-2 text-gray-700">Profile details will go here.</p>
  </div>
</section>
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
      let speed = +counter.getAttribute("data-speed");
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

// Nav event listeners
document.querySelector("a[data-section='dashboard']").addEventListener("click", e => {
  e.preventDefault();
  showSection("employeeTable");
});

document.querySelector("a[data-section='operatorStatus']").addEventListener("click", e => {
  e.preventDefault();
  showSection("operatorStatusSection");
});

document.querySelector("a[data-section='operatorProfile']").addEventListener("click", e => {
  e.preventDefault();
  showSection("operatorProfileSection");
});

feather.replace();
</script>
</body>

</html>
