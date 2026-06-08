<?php
require_once __DIR__ . '/inc/functions.php';

$sections = [];
$departments = [];
$saveError = '';
$saveSuccess = '';

$conn = db_connect();
if ($conn) {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS departments (" .
        "id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, " .
        "department_code VARCHAR(100) NOT NULL, " .
        "name VARCHAR(255) NOT NULL, " .
        "created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, " .
        "UNIQUE KEY unique_department_code (department_code)" .
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_department'])) {
        $deptCode = trim($_POST['dept_code'] ?? '');
        $deptName = trim($_POST['dept_name'] ?? '');
        if ($deptCode && $deptName) {
            $stmt = $conn->prepare("INSERT INTO departments (department_code, name) VALUES (?, ?)");
            if ($stmt) {
                $stmt->bind_param('ss', $deptCode, $deptName);
                if ($stmt->execute()) {
                    $stmt->close();
                    audit_log('create_department');
                    header('Location: ' . $_SERVER['PHP_SELF']);
                    exit;
                } else {
                    $saveError = 'Department save failed: ' . $stmt->error;
                    $stmt->close();
                }
            } else {
                $saveError = 'Department prepare failed: ' . $conn->error;
            }
        } else {
            $saveError = 'Department code and name are required.';
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_section'])) {
        $name = trim($_POST['sec_name'] ?? '');
        $department = trim($_POST['sec_department'] ?? '');
        $schoolYear = trim($_POST['sec_year'] ?? '');
        $semester = trim($_POST['sec_semester'] ?? '');
        $section_code = trim($_POST['sec_code'] ?? '');

        if ($name) {
            if (! $section_code) {
                $slug = preg_replace('/[^A-Za-z0-9]+/', '-', strtolower($name));
                $section_code = 'SEC-' . strtoupper(substr($slug, 0, 5)) . '-' . substr(time(), -4);
            }

            $stmt = $conn->prepare("INSERT INTO sections (section_code, name, department, school_year, semester) VALUES (?, ?, ?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param('sssss', $section_code, $name, $department, $schoolYear, $semester);
                if ($stmt->execute()) {
                    $stmt->close();
                    audit_log('create_section');
                    header('Location: ' . $_SERVER['PHP_SELF']);
                    exit;
                } else {
                    $saveError = 'Section save failed: ' . $stmt->error;
                    $stmt->close();
                }
            } else {
                $saveError = 'Section prepare failed: ' . $conn->error;
            }
        } else {
            $saveError = 'Section name is required.';
        }
    }


    foreach (['teacher_id' => "INT UNSIGNED DEFAULT NULL", 'school_year' => "VARCHAR(20) DEFAULT NULL", 'semester' => "VARCHAR(20) DEFAULT NULL"] as $column => $definition) {
        $check = $conn->query("SHOW COLUMNS FROM sections LIKE '{$column}'");
        if ($check && $check->num_rows === 0) {
            $conn->query("ALTER TABLE sections ADD COLUMN {$column} {$definition}");
        }
        if ($check) {
            $check->free();
        }
    }

    $res = $conn->query("SELECT id, department_code, name FROM departments ORDER BY created_at DESC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $departments[] = $row;
        }
        $res->free();
    }

    $res = $conn->query(
        "SELECT section_code, name, department, school_year, semester " .
        "FROM sections " .
        "ORDER BY created_at DESC"
    );
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $sections[] = $row;
        }
        $res->free();
    }
    $conn->close();
}
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>CSCQC Portal</title>
  <link rel="icon" type="image/png" href="https://cscqcph.com/images/bg/cscqcph.png" />

  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
<style>
  :root {
      --bg-color: #f1f4f2;
      --sidebar-bg: #ffffff;
      --text-color: #333333;
      --text-muted: #666666;
      --primary-green: #0e4429;
      --primary-green-hover: #165c39;
      --light-green-bg: #d8ebd4;
      --active-nav-bg: #b9deb3;
      --border-color: #e5e7eb;
      --card-bg: #ffffff;
      --danger-red: #dc2626;
      --edit-blue: #059669;
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
      font-family: "Inter", sans-serif;
    }

    body {
      background-color: var(--bg-color);
      color: var(--text-color);
      display: grid;
      grid-template-columns: 260px 1fr;
      min-height: 100vh;
    }

    /* --- MOBILE HEADER & TOGGLE --- */
    .mobile-header {
      display: none;
      background-color: var(--sidebar-bg);
      border-bottom: 1px solid var(--border-color);
      padding: 12px 20px;
      align-items: center;
      justify-content: space-between;
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      z-index: 100;
      height: 60px;
    }

    .menu-toggle-btn {
      font-size: 1.3rem;
      color: var(--primary-green);
      cursor: pointer;
      padding: 4px 8px;
    }

    #sidebar-toggle {
      display: none;
    }

    /* --- SIDEBAR STYLE --- */
    .sidebar {
      background-color: var(--sidebar-bg);
      border-right: 1px solid var(--border-color);
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      padding: 20px 0;
      height: 100vh;
      position: sticky;
      top: 0;
      z-index: 90;
    }

    .brand {
      display: flex;
      align-items: center;
      padding: 0 24px;
      margin-bottom: 30px;
    }

    .brand i {
      font-size: 1.8rem;
      color: var(--primary-green);
      margin-right: 12px;
    }

    .brand-text h2 {
      font-size: 1rem;
      font-weight: 700;
      color: #111827;
    }

    .brand-text p {
      font-size: 0.75rem;
      color: var(--text-muted);
    }

    .nav-menu {
      list-style: none;
      flex-grow: 1;
    }

    input[type="radio"].tab-switch {
      display: none;
    }

    .nav-menu label,
    .nav-menu a {
      display: flex;
      align-items: center;
      padding: 12px 24px;
      color: var(--text-muted);
      font-size: 0.9rem;
      font-weight: 500;
      cursor: pointer;
      transition: all 0.2s;
      margin-bottom: 4px;
      border-left: 4px solid transparent;
      text-decoration: none;
    }

    .nav-menu label i,
    .nav-menu a i {
      margin-right: 12px;
      font-size: 1.1rem;
      width: 20px;
      text-align: center;
    }

    .nav-menu label:hover,
    .nav-menu a:hover {
      background-color: #f9fafb;
      color: var(--primary-green);
    }

    .logout-btn {
      margin: 0 20px;
      padding: 12px;
      background-color: var(--primary-green);
      color: white;
      border: none;
      border-radius: 6px;
      font-weight: 500;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      text-decoration: none;
      font-size: 0.9rem;
      transition: background 0.2s;
    }

    .logout-btn:hover {
      background-color: var(--primary-green-hover);
    }

    /* --- MAIN CONTENT STYLE --- */
    .main-content {
      padding: 40px;
      min-width: 0;
    }

    .tab-content {
      display: none;
    }

    /* --- SCREENSHOT-MATCHED GLOBAL TERM CONTAINER --- */
    .global-term-container {
      display: flex;
      align-items: center;
      gap: 24px;
      background-color: #0c3e21;
      /* Match the exact dark forest green panel */
      padding: 16px 24px;
      border-radius: 12px;
      margin-bottom: 24px;
      box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
      flex-wrap: wrap;
    }

    .filter-group {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .global-term-container label {
      color: #ffffff;
      font-size: 0.9rem;
      font-weight: 700;
      display: flex;
      align-items: center;
      gap: 8px;
      white-space: nowrap;
    }

    .global-term-container label i {
      font-size: 1rem;
    }

    .global-select {
      padding: 8px 14px;
      border-radius: 6px;
      border: 1px solid transparent;
      font-size: 0.875rem;
      background-color: #ffffff;
      color: #1f2937;
      font-weight: 500;
      cursor: pointer;
      outline: none;
      min-width: 140px;
      transition:
        border-color 0.2s,
        box-shadow 0.2s;
    }

    .global-select:focus {
      border-color: #22c55e;
      box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.2);
    }

    /* --- CSS TAB SWITCHING LOGIC --- */
    #tab-dashboard:checked~.main-content #content-dashboard,
    #tab-teachers:checked~.main-content #content-teachers,
    #tab-sec-dept:checked~.main-content #content-sec-dept,
    #tab-students:checked~.main-content #content-students,
    #tab-assign:checked~.main-content #content-assign {
      display: block;
    }

    #tab-dashboard:checked~.sidebar label[for="tab-dashboard"],
    #tab-teachers:checked~.sidebar label[for="tab-teachers"],
    #tab-sec-dept:checked~.sidebar label[for="tab-sec-dept"],
    #tab-students:checked~.sidebar label[for="tab-students"],
    #tab-assign:checked~.sidebar label[for="tab-assign"] {
      background-color: var(--active-nav-bg);
      color: var(--primary-green);
      font-weight: 600;
    }

    /* --- UI COMPONENTS & TABLES --- */
    .view-title {
      font-size: 1.25rem;
      color: var(--primary-green);
      font-weight: 600;
      margin-bottom: 4px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .view-subtitle {
      font-size: 0.85rem;
      color: var(--text-muted);
      margin-bottom: 24px;
    }

    /* Dedicated Section View Filter Headers */
    .view-filter-row {
      display: flex;
      gap: 16px;
      margin-bottom: 24px;
      background-color: var(--card-bg);
      padding: 16px 20px;
      border-radius: 8px;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
      align-items: center;
      flex-wrap: wrap;
    }

    .filter-item {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .filter-item label {
      font-size: 0.85rem;
      font-weight: 600;
      color: var(--primary-green);
      white-space: nowrap;
    }

    .filter-select {
      padding: 6px 32px 6px 12px;
      border: 1px solid #d1d5db;
      border-radius: 6px;
      font-size: 0.85rem;
      color: #1f2937;
      background-color: #fff;
      outline: none;
      appearance: none;
      background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%234b5563' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
      background-repeat: no-repeat;
      background-position: right 10px center;
      background-size: 14px;
    }

    .filter-select:focus {
      border-color: var(--primary-green);
    }

    /* Dashboard Overview Cards */
    .cards-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 20px;
      margin-bottom: 30px;
    }

    .card {
      background-color: var(--light-green-bg);
      padding: 24px;
      border-radius: 8px;
    }

    .card-title {
      font-size: 0.85rem;
      color: #4b5563;
      font-weight: 500;
      margin-bottom: 8px;
    }

    .card-value {
      font-size: 2.2rem;
      font-weight: 700;
      color: var(--primary-green);
    }

    /* Section Block Wrapper */
    .panel-block {
      background-color: var(--card-bg);
      border-radius: 8px;
      padding: 24px;
      margin-bottom: 24px;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    }

    .block-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 16px;
      gap: 16px;
      flex-wrap: wrap;
    }

    .block-title {
      font-size: 1rem;
      color: var(--primary-green);
      font-weight: 600;
    }

    .header-actions {
      display: flex;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
    }

    /* Search Bar Styles */
    .search-wrapper {
      position: relative;
      display: flex;
      align-items: center;
    }

    .search-wrapper i {
      position: absolute;
      left: 12px;
      color: #9ca3af;
      font-size: 0.9rem;
      pointer-events: none;
    }

    .search-input {
      padding: 8px 12px 8px 34px;
      border: 1px solid #d1d5db;
      border-radius: 6px;
      font-size: 0.85rem;
      color: #1f2937;
      width: 220px;
      transition: all 0.2s;
    }

    .search-input:focus {
      outline: none;
      border-color: var(--primary-green);
      width: 260px;
      box-shadow: 0 0 0 3px rgba(14, 68, 41, 0.15);
    }

    .btn-add {
      background-color: var(--primary-green);
      color: white;
      border: none;
      padding: 8px 16px;
      border-radius: 6px;
      font-size: 0.85rem;
      font-weight: 500;
      cursor: pointer;
      display: flex;
      align-items: center;
      gap: 6px;
      white-space: nowrap;
    }

    .btn-add:hover {
      background-color: var(--primary-green-hover);
    }

    /* --- FIXED RESPONSIVE TABLE SYSTEM --- */
    .table-responsive {
      width: 100%;
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
      border: 1px solid var(--border-color);
      border-radius: 6px;
      background-color: #ffffff;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      text-align: left;
      font-size: 0.85rem;
      min-width: 650px;
    }

    th {
      color: #4b5563;
      background-color: #f9fafb;
      font-weight: 600;
      text-transform: uppercase;
      font-size: 0.75rem;
      padding: 12px 16px;
      border-bottom: 1px solid var(--border-color);
    }

    td {
      padding: 14px 16px;
      color: #1f2937;
      border-bottom: 1px solid #f3f4f6;
      vertical-align: middle;
    }

    th:last-child,
    .actions-cell {
      text-align: center;
      width: 100px;
      min-width: 100px;
    }

    .actions-cell {
      white-space: nowrap;
    }

    .actions-cell i {
      font-size: 1.15rem;
      cursor: pointer;
      margin: 0 6px;
      display: inline-block;
      transition: transform 0.2s ease;
    }

    .actions-cell i:hover {
      transform: scale(1.18);
    }

    .fa-pen-to-square {
      color: #10b981;
    }

    .fa-trash-can {
      color: #ef4444;
    }

    /* --- STRUCTURAL FORM PANEL FIELDS --- */
    .form-group {
      margin: 10px 5px 20px;
    }

    .form-group label {
      display: block;
      font-size: 0.85rem;
      color: var(--text-muted);
      margin-bottom: 8px;
      font-weight: 500;
    }

    .form-control {
      width: 100%;
      padding: 10px 12px;
      border: 1px solid #d1d5db;
      border-radius: 6px;
      background-color: #fff;
      font-size: 0.9rem;
      color: #1f2937;
      outline: none;
    }

    select.form-control {
      appearance: none;
      background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%234b5563' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
      background-repeat: no-repeat;
      background-position: right 12px center;
      background-size: 16px;
      padding-right: 36px;
    }

    .form-control:focus {
      border-color: var(--primary-green);
      box-shadow: 0 0 0 3px rgba(14, 68, 41, 0.15);
    }

    .btn-submit {
      background-color: var(--primary-green);
      color: white;
      border: none;
      margin-bottom: 8px;
      margin-left: 7px;
      padding: 10px 20px;
      border-radius: 6px;
      font-size: 0.9rem;
      font-weight: 500;
      cursor: pointer;
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .btn-submit:hover {
      background-color: var(--primary-green-hover);
    }

    .form-buttons-row {
      display: flex;
      gap: 12px;
      align-items: center;
    }

    .btn-cancel {
      background-color: #e5e7eb;
      color: #374151;
      border: none;
      padding: 10px 20px;
      border-radius: 6px;
      font-size: 0.9rem;
      font-weight: 500;
      cursor: pointer;
    }

    .grid-2col {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 20px;
    }

    .grid-3col {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 20px;
    }

    /* --- DEVICE RESPONSIVE SYSTEM MEDIA BREAKPOINTS --- */
    @media (max-width: 1024px) {
      body {
        grid-template-columns: 1fr;
        padding-top: 60px;
      }

      .mobile-header {
        display: flex;
      }

      .sidebar {
        position: fixed;
        top: 60px;
        left: 0;
        bottom: 0;
        transform: translateX(-100%);
        width: 260px;
        height: calc(100vh - 60px);
        box-shadow: 4px 0 10px rgba(0, 0, 0, 0.1);
        transition: transform 0.3s ease;
      }

      #sidebar-toggle:checked~.sidebar {
        transform: translateX(0);
      }

      .main-content {
        padding: 20px;
      }

      .cards-grid {
        grid-template-columns: repeat(2, 1fr);
      }
    }

    @media (max-width: 768px) {
      .cards-grid {
        grid-template-columns: 1fr;
      }

      .grid-2col,
      .grid-3col {
        grid-template-columns: 1fr;
        gap: 0;
      }

      .header-actions {
        width: 100%;
      }

      .search-wrapper,
      .search-input {
        width: 100% !important;
      }

      .view-filter-row {
        flex-direction: column;
        align-items: stretch;
        gap: 12px;
      }

      .filter-item {
        justify-content: space-between;
      }
    }

    @media (max-width: 480px) {
      .panel-block {
        padding: 16px;
      }

      .header-actions {
        flex-direction: column;
        align-items: stretch;
      }

      .btn-add {
        justify-content: center;
      }
    }
</style>
</head>
<body>
  <input type="checkbox" id="sidebar-toggle" />
<header class="mobile-header">
    <div class="brand" style="padding: 0; margin: 0">
      <i class="fa-solid fa-graduation-cap" style="font-size: 1.5rem"></i>
      <div class="brand-text">
        <h2 style="font-size: 0.9rem">Admin Panel</h2>
      </div>
    </div>
    <label for="sidebar-toggle" class="menu-toggle-btn">
      <i class="fa-solid fa-bars"></i>
    </label>
  </header>
  <input type="radio" name="nav-tabs" id="tab-dashboard" class="tab-switch" />
  <input type="radio" name="nav-tabs" id="tab-teachers" class="tab-switch" />
  <input type="radio" name="nav-tabs" id="tab-sec-dept" checked class="tab-switch" />
  <input type="radio" name="nav-tabs" id="tab-students" class="tab-switch" />
  <input type="radio" name="nav-tabs" id="tab-assign" class="tab-switch" />

  <aside class="sidebar">
    <div>
      <div class="brand">
        <i class="fa-solid fa-graduation-cap"></i>
        <div class="brand-text">
          <h2>Admin Panel</h2>
          <p>CSCQC</p>
        </div>
      </div>
      <nav class="nav-menu" aria-label="Main Navigation">
        <a href="admin.php" class="nav-link"><i class="fa-solid fa-table-cells-large"></i> Dashboard</a>
        <a href="teachers.php" class="nav-link"><i class="fa-solid fa-users"></i> Teachers</a>
        <a href="section.php" class="nav-link"><i class="fa-solid fa-book-open"></i> Section & Dept</a>
        <a href="students.php" class="nav-link"><i class="fa-solid fa-user-graduate"></i> Students</a>
        <a href="assign.php" class="nav-link"><i class="fa-solid fa-gear"></i> Assign Module</a>
      </nav>
    </div>
    <a href="#" class="logout-btn">
      <i class="fa-solid fa-right-from-bracket"></i> Logout
    </a>
  </aside>
  <div class="main-content">
    <div class="global-term-container">
      <div class="filter-group">
        <label for="global-filter-year">
          <i class="fa-solid fa-calendar-days" aria-hidden="true"></i>
          Academic Year:
        </label>
        <select id="global-filter-year" class="global-select">
          <option value="2025-2026">2025–2026</option>
          <option value="2024-2025">2024–2025</option>
        </select>
      </div>

      <div class="filter-group">
        <label for="global-filter-sem">
          <i class="fa-solid fa-clock" aria-hidden="true"></i> Semester:
        </label>
        <select id="global-filter-sem" class="global-select">
          <option value="1st Semester">1st Semester</option>
          <option value="2nd Semester">2nd Semester</option>
        </select>
      </div>
    </div>

    <div class="tab-content" style="display: block;">
      <h1 class="view-title">Section & Department</h1>
      <div class="view-subtitle">Institutional Divisions</div>

<?php if ($saveError): ?>
      <div style="background-color: #fde8e8; border: 1px solid #dc2626; color: #991b1b; padding: 16px; border-radius: 8px; margin-bottom: 24px;">
        <strong>Error:</strong> <?php echo htmlspecialchars($saveError); ?>
      </div>
<?php endif; ?>

      <!-- Departments Panel Block -->
      <div class="panel-block">
        <div class="block-header">
          <h2 class="block-title">Create / Manage Departments</h2>
        </div>
        <form id="department-form" method="post">
          <input type="hidden" name="add_department" value="1" />
          <div class="grid-2col">
            <div class="form-group">
              <label for="dept-code-input">Department Code</label>
              <input type="text" id="dept-code-input" name="dept_code" class="form-control" placeholder="Enter department code" required />
            </div>
            <div class="form-group">
              <label for="dept-name-input">Department Name Description</label>
              <input type="text" id="dept-name-input" name="dept_name" class="form-control" placeholder="Enter department name" required />
            </div>
          </div>
          <div class="form-buttons-row">
            <button type="submit" class="btn-submit">
              <i class="fa-solid fa-plus"></i> Save Department
            </button>
          </div>
        </form>

        <div class="table-responsive" style="margin-top: 20px">
          <!-- Local Table Search Header Wrapper -->
          <div style="
                display: flex;
                justify-content: flex-end;
                margin-bottom: 10px;
              ">
            <div class="search-wrapper" style="position: relative; min-width: 250px">
              <i class="fa-solid fa-magnifying-glass" style="
                    position: absolute;
                    left: 10px;
                    top: 50%;
                    transform: translateY(-50%);
                    color: #aaa;
                  "></i>
              <input id="dept-search-input" type="text" class="table-search-input" placeholder="Search departments..." style="
                    width: 100%;
                    padding: 6px 12px 6px 32px;
                    border-radius: 4px;
                    border: 1px solid #ccc;
                  " />
            </div>
          </div>
          <table id="departments-table">
            <thead>
              <tr>
                <th>Department Code</th>
                <th>Department Name</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="departments-table-body">
<?php
if (count($departments)) {
    foreach ($departments as $department) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($department['department_code']) . '</td>';
        echo '<td>' . htmlspecialchars($department['name']) . '</td>';
        echo '<td class="actions-cell"><i class="fa-solid fa-trash-can"></i></td>';
        echo '</tr>';
    }
} else {
    echo '<tr><td colspan="3" style="text-align:center; color:#666; padding:18px;">No departments available.</td></tr>';
}
?>
          </tbody>
          </table>
        </div>
      </div>

      <!-- Sections Panel Block -->
      <div class="panel-block">
        <div class="block-header">
          <h2 class="block-title">Create / Manage Sections</h2>
        </div>
        <form id="section-form" method="post">
          <input type="hidden" name="add_section" value="1" />
          <input type="hidden" name="sec_code" id="sec-code-input" value="" />
          <div class="grid-3col">
            <div class="form-group">
              <label for="sec-name-input">Section Name</label>
              <input type="text" id="sec-name-input" name="sec_name" class="form-control" placeholder="Enter section name" required />
            </div>
            <div class="form-group">
              <label for="sec-year-select">School Year</label>
              <select id="sec-year-select" name="sec_year" class="form-control" required>
                <option value="" disabled selected hidden>
                  Select academic year...
                </option>
                <option value="2025-2026">S.Y. 2025-2026</option>
                <option value="2026-2027">S.Y. 2026-2027</option>
              </select>
            </div>
            <div class="form-group">
              <label for="sec-sem-select">Semester</label>
              <select id="sec-sem-select" name="sec_semester" class="form-control" required>
                <option value="" disabled selected hidden>
                  Select semester term...
                </option>
                <option value="1st Semester">1st Semester</option>
                <option value="2nd Semester">2nd Semester</option>
              </select>
            </div>
            <div class="form-group">
              <label for="sec-dept-select">Department</label>
              <select id="sec-dept-select" name="sec_department" class="form-control" required>
                <option value="" disabled selected hidden>
                  Select a department...
                </option>
<?php foreach ($departments as $department): ?>
                <option value="<?php echo htmlspecialchars($department['name']); ?>"><?php echo htmlspecialchars($department['department_code'] . ' - ' . $department['name']); ?></option>
<?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="form-buttons-row">
            <button type="submit" class="btn-submit">
              <i class="fa-solid fa-plus"></i> Save Section
            </button>
          </div>
        </form>

        <div class="table-responsive" style="margin-top: 20px">
          <!-- Local Table Search Header Wrapper -->
          <div style="
                display: flex;
                justify-content: flex-end;
                margin-bottom: 10px;
              ">
            <div class="search-wrapper" style="position: relative; min-width: 250px">
              <i class="fa-solid fa-magnifying-glass" style="
                    position: absolute;
                    left: 10px;
                    top: 50%;
                    transform: translateY(-50%);
                    color: #aaa;
                  "></i>
              <input id="section-search-input" type="text" class="table-search-input" placeholder="Search sections..." style="
                    width: 100%;
                    padding: 6px 12px 6px 32px;
                    border-radius: 4px;
                    border: 1px solid #ccc;
                  " />
            </div>
          </div>
          <table id="sections-table">
            <thead>
              <tr>
                <th>Section Code</th>
                <th>Section Name</th>
                <th>School Year</th>
                <th>Semester</th>
                <th>Department</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="sections-table-body">
<?php
if (count($sections)) {
    foreach ($sections as $section) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($section['section_code']) . '</td>';
        echo '<td>' . htmlspecialchars($section['name']) . '</td>';
        echo '<td>' . htmlspecialchars($section['school_year'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($section['semester'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($section['department'] ?? '') . '</td>';
        echo '<td class="actions-cell"><i class="fa-solid fa-trash-can"></i></td>';
        echo '</tr>';
    }
} else {
    echo '<tr><td colspan="6" style="text-align:center; color:#666; padding:18px;">No sections available.</td></tr>';
}
?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const departmentForm = document.getElementById('department-form');
      const sectionForm = document.getElementById('section-form');
      const departmentTableBody = document.getElementById('departments-table-body');
      const sectionTableBody = document.getElementById('sections-table-body');
      const deptSearchInput = document.getElementById('dept-search-input');
      const sectionSearchInput = document.getElementById('section-search-input');
      const deptCodeInput = document.getElementById('dept-code-input');
      const deptNameInput = document.getElementById('dept-name-input');
      const secNameInput = document.getElementById('sec-name-input');
      const secYearSelect = document.getElementById('sec-year-select');
      const secSemSelect = document.getElementById('sec-sem-select');
      const secDeptSelect = document.getElementById('sec-dept-select');

      function renderEmptyRow(tableBody, colspan, message) {
        tableBody.innerHTML = `
          <tr>
            <td colspan="${colspan}" style="text-align:center; color:#666; padding:18px;">${message}</td>
          </tr>
        `;
      }

      function addDepartmentOption(code, name) {
        if (!secDeptSelect) return;
        const optionValue = name;
        const existing = Array.from(secDeptSelect.options).find(opt => opt.value === optionValue);
        if (existing) return;

        const option = document.createElement('option');
        option.value = optionValue;
        option.textContent = `${code} - ${name}`;
        secDeptSelect.appendChild(option);
      }

      function removeDepartmentOption(name) {
        if (!secDeptSelect) return;
        const option = Array.from(secDeptSelect.options).find(opt => opt.value === name);
        if (option) {
          option.remove();
        }
      }

      function normalizeText(text) {
        return text.toLowerCase().trim();
      }

      function addDepartmentRow(code, name) {
        const row = document.createElement('tr');
        row.innerHTML = `
          <td>${code}</td>
          <td>${name}</td>
          <td class="actions-cell">
            <i class="fa-solid fa-trash-can" role="button" aria-label="Delete department"></i>
          </td>
        `;
        row.querySelector('.fa-trash-can').addEventListener('click', () => {
          row.remove();
          removeDepartmentOption(name);
          if (!departmentTableBody.querySelector('tr')) {
            renderEmptyRow(departmentTableBody, 3, 'No departments available.');
          }
        });
        if (departmentTableBody.querySelector('td[colspan]')) {
          departmentTableBody.innerHTML = '';
        }
        departmentTableBody.appendChild(row);
        addDepartmentOption(code, name);
      }

      function filterTableRows(input, tableBody, placeholderText) {
        const query = normalizeText(input.value);
        const rows = Array.from(tableBody.querySelectorAll('tr')).filter(row => !row.querySelector('td[colspan]'));
        let visibleCount = 0;

        rows.forEach(row => {
          const text = normalizeText(row.textContent);
          const match = text.includes(query);
          row.style.display = match ? '' : 'none';
          if (match) visibleCount++;
        });

        const placeholderRow = tableBody.querySelector('td[colspan]')?.closest('tr');
        const colspan = tableBody.id === 'departments-table-body' ? 3 : 7;
        if (visibleCount === 0) {
          if (!placeholderRow) {
            renderEmptyRow(tableBody, colspan, placeholderText);
          }
        } else if (placeholderRow) {
          placeholderRow.remove();
        }
      }

      if (deptSearchInput) {
        deptSearchInput.addEventListener('input', function () {
          filterTableRows(this, departmentTableBody, 'No departments matched your search.');
        });
      }

      if (sectionSearchInput) {
        sectionSearchInput.addEventListener('input', function () {
          filterTableRows(this, sectionTableBody, 'No sections matched your search.');
        });
      }

      if (!departmentTableBody.querySelector('tr')) {
        renderEmptyRow(departmentTableBody, 3, 'No departments available.');
      }
    });
  </script>
</body>
</html>