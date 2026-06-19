<?php require_once __DIR__ . '/inc/functions.php'; ?>
<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$term = get_global_term();
$selectedYear = $term['year'];
$selectedSem  = $term['semester'];

// Admin management tables should not depend on the global term filter.
// They are identity records (teachers registry) and must remain visible across terms.
// Term filtering is only applied to assignments/grades, not to CRUD registries.


$teacherSaveError = '';
$editingTeacherId = 0;
$editingTeacherData = [];
$isEditingTeacher = false;
$departments = [];

$conn = db_connect();
if ($conn) {
    $res = $conn->query("SELECT id, name FROM departments ORDER BY name ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) $departments[] = $row;
        $res->free();
    }
    $conn->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_teacher'])) {
    $teacherId = intval($_POST['teacher_id'] ?? 0);
    if ($teacherId) {
        delete_teacher($teacherId);
        audit_log('delete_teacher');
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_teacher'])) {
    $teacherId = intval($_POST['teacher_id'] ?? 0);
    $firstName = trim($_POST['first_name'] ?? '');
    $middleName = trim($_POST['middle_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $department = trim($_POST['department'] ?? '');

    if (!$teacherId || !$firstName || !$lastName) {
        $teacherSaveError = 'First name and last name are required.';
    } else {
        $ok = update_teacher($teacherId, $firstName, $middleName, $lastName, $department ?: null, null);
        if ($ok) {
            audit_log('update_teacher');
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        } else {
            $teacherSaveError = 'Teacher update failed.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_teacher'])) {
    $firstName = trim($_POST['first_name'] ?? '');
    $middleName = trim($_POST['middle_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $department = trim($_POST['department'] ?? '');

    if (!$firstName || !$lastName) {
        $teacherSaveError = 'First name and last name are required.';
    } else {
        $result = create_teacher($firstName, $middleName, $lastName, $department ?: null);
        if ($result['success']) {
            audit_log('create_teacher');
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        } else {
            $teacherSaveError = $result['error'];
        }
    }
}

if (isset($_GET['edit_teacher'])) {
    $editingTeacherId = intval($_GET['edit_teacher']);
    $conn = db_connect();
    if ($conn) {
        $stmt = $conn->prepare("SELECT id, first_name, middle_name, last_name, department FROM teachers WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $editingTeacherId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $r = $res->fetch_assoc()) $editingTeacherData = $r;
            $stmt->close();
        }
        $conn->close();
    }
}

$isEditingTeacher = (bool) $editingTeacherData;

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
    .nav-menu a:hover,
    .nav-menu a.active {
      background-color: var(--active-nav-bg);
      color: var(--primary-green);
      font-weight: 600;
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

    .icon-button {
      background: transparent;
      border: none;
      padding: 4px 8px;
      margin: 0;
      color: inherit;
      cursor: pointer;
      font: inherit;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 4px;
      transition: transform 0.2s ease;
    }

    .icon-button:hover {
      transform: scale(1.1);
    }

    .icon-button:focus {
      outline: none;
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

    .modal-backdrop {
      position: fixed;
      inset: 0;
      display: none;
      align-items: center;
      justify-content: center;
      background: rgba(15, 23, 42, 0.4);
      backdrop-filter: blur(10px);
      z-index: 999;
      padding: 24px;
    }

    .teacher-form-card {
      background-color: #ffffff;
      border: 1px solid #d1d5db;
      border-radius: 18px;
      padding: 24px;
      margin-bottom: 24px;
      box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08);
      position: relative;
      max-width: 720px;
      width: 100%;
    }

    .teacher-form-close {
      position: absolute;
      top: 18px;
      right: 18px;
      background: transparent;
      border: none;
      color: #6b7280;
      font-size: 1.1rem;
      cursor: pointer;
      padding: 8px;
      line-height: 1;
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

    .form-stack {
      display: grid;
      grid-template-columns: 1fr;
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
        <a href="admin.php" class="nav-link"> <i class="fa-solid fa-table-cells-large"></i> Dashboard</a>
        <a href="teachers.php" class="nav-link active"><i class="fa-solid fa-users"></i> Teachers</a>
        <a href="section.php" class="nav-link"><i class="fa-solid fa-book-open"></i> Section & Dept</a>
        <a href="students.php" class="nav-link"><i class="fa-solid fa-user-graduate"></i> Students</a>
        <a href="assign.php" class="nav-link"><i class="fa-solid fa-gear"></i> Assign Module</a>
        <a href="deadline_manager.php"><i class="fa-solid fa-calendar-check"></i> Grade Deadlines</a>
        <a href="grade_requests.php"><i class="fa-solid fa-clipboard-check"></i> Grade Requests</a>
      </nav>
    </div>
    <a href="login.php?logout=1" class="logout-btn">
      <i class="fa-solid fa-right-from-bracket"></i> Logout
    </a>
  </aside>
  <div class="main-content">
    <form method="get" action="" style="margin-bottom:0;">
      <input type="hidden" name="global_year" id="hidden-global-year" value="<?php echo htmlspecialchars($selectedYear); ?>">
      <input type="hidden" name="global_sem" id="hidden-global-sem" value="<?php echo htmlspecialchars($selectedSem); ?>">
      <div class="global-term-container">
        <div class="filter-group">
          <label for="global-filter-year">
            <i class="fa-solid fa-calendar-days" aria-hidden="true"></i>
            Academic Year:
          </label>
          <select id="global-filter-year" class="global-select" onchange="syncGlobalFilter()">
            <option value="2025-2026" <?php echo $selectedYear==='2025-2026'?'selected':''; ?>>2025–2026</option>
            <option value="2026-2027" <?php echo $selectedYear==='2026-2027'?'selected':''; ?>>2026–2027</option>
          </select>
        </div>

        <div class="filter-group">
          <label for="global-filter-sem">
            <i class="fa-solid fa-clock" aria-hidden="true"></i> Semester:
          </label>
          <select id="global-filter-sem" class="global-select" onchange="syncGlobalFilter()">
            <option value="1st Semester" <?php echo $selectedSem==='1st Semester'?'selected':''; ?>>1st Semester</option>
            <option value="2nd Semester" <?php echo $selectedSem==='2nd Semester'?'selected':''; ?>>2nd Semester</option>
            <option value="Summer" <?php echo $selectedSem==='Summer'?'selected':''; ?>>Summer</option>
          </select>
        </div>
      </div>
    </form>

    <div class="tab-content" style="display: block;">
      <h1 class="view-title">Teachers Management</h1>
      <p class="view-subtitle">Manage Teachers Account</p>

      <div class="panel-block">
        <div class="block-header" style="
              display: flex;
              justify-content: space-between;
              align-items: center;
              flex-wrap: wrap;
              gap: 15px;
              margin-bottom: 15px;
            ">
          <h2 class="block-title" style="margin: 0">Teachers Registry</h2>
          <div class="header-actions" style="display: flex; gap: 15px; align-items: center">
            <!-- Local Table Search -->
            <div class="search-wrapper" style="position: relative; min-width: 250px">
              <i class="fa-solid fa-magnifying-glass" style="
                    position: absolute;
                    left: 10px;
                    top: 50%;
                    transform: translateY(-50%);
                    color: #aaa;
                  "></i>
              <input type="text" class="table-search-input" placeholder="Search teachers..." style="
                    width: 100%;
                    padding: 6px 12px 6px 32px;
                    border-radius: 4px;
                    border: 1px solid #ccc;
                  " />
            </div>
            <button type="button" id="btn-add-teacher" class="btn-add" style="<?php echo ($teacherSaveError || $isEditingTeacher) ? 'display:none;' : ''; ?>" onclick="var m = document.getElementById('teacher-modal-backdrop'); if (!m) return; m.style.display = 'flex'; this.style.display = 'none';">
              <i class="fa-solid fa-plus"></i> Add Teacher
            </button>
          </div>
        </div>
        <div id="teacher-modal-backdrop" class="modal-backdrop" style="display:<?php echo ($teacherSaveError || $isEditingTeacher) ? 'flex' : 'none'; ?>;">
          <div class="teacher-form-card">
            <button type="button" class="teacher-form-close" onclick="document.getElementById('teacher-modal-backdrop').style.display='none'; document.getElementById('btn-add-teacher').style.display='inline-flex'; document.getElementById('teacher-form').reset(); document.getElementById('teacher-id-field').value='';">
              <i class="fa-solid fa-xmark"></i>
            </button>
            <?php if ($teacherSaveError): ?>
              <p style="color:#b91c1c; margin-bottom: 16px;"><?php echo htmlspecialchars($teacherSaveError); ?></p>
            <?php endif; ?>
            <div class="student-form-header">
              <h3 id="form-title">Add Teacher</h3>
              <p id="form-subtitle">Enter the teacher details below.</p>
            </div>
            <form method="post" id="teacher-form">
              <input type="hidden" name="teacher_id" id="teacher-id-field" value="<?php echo intval($editingTeacherData['id'] ?? 0); ?>">
              <div class="grid-3col">
                <div class="form-group">
                  <label for="first_name">First Name</label>
                  <input type="text" id="first_name" name="first_name" class="form-control" required value="<?php echo htmlspecialchars($editingTeacherData['first_name'] ?? ''); ?>" />
                </div>
                <div class="form-group">
                  <label for="middle_name">Middle Name</label>
                  <input type="text" id="middle_name" name="middle_name" class="form-control" value="<?php echo htmlspecialchars($editingTeacherData['middle_name'] ?? ''); ?>" />
                </div>
                <div class="form-group">
                  <label for="last_name">Last Name</label>
                  <input type="text" id="last_name" name="last_name" class="form-control" required value="<?php echo htmlspecialchars($editingTeacherData['last_name'] ?? ''); ?>" />
                </div>
              </div>
              <div class="form-group">
                <label for="department">Department</label>
                <select id="department" name="department" class="form-control">
                  <option value="">Select department...</option>
                  <?php foreach ($departments as $dept): ?>
                    <option value="<?php echo htmlspecialchars($dept['name']); ?>" <?php echo ($editingTeacherData['department'] ?? '') === $dept['name'] ? 'selected' : ''; ?>>
                      <?php echo htmlspecialchars($dept['name']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-buttons-row card-action-row">
                <button type="submit" id="submit-btn" name="add_teacher" class="btn-submit">Save Teacher</button>
                <button type="button" onclick="document.getElementById('teacher-modal-backdrop').style.display='none'; document.getElementById('btn-add-teacher').style.display='inline-flex'; document.getElementById('teacher-form').reset(); document.getElementById('teacher-id-field').value='';" class="btn-cancel">Cancel</button>
              </div>
            </form>
          </div>
        </div>
        <div class="table-responsive">
          <table id="teachers-table">
            <thead>
              <tr>
                <th>T-ID</th>
                <th>Name</th>
                <th>Department</th>
                <th>Password</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
            <?php
            $conn = db_connect();
            if ($conn) {
                // Teachers registry is not term-scoped; show all teachers.
$res = $conn->query("SELECT id, teacher_id, first_name, middle_name, last_name, department FROM teachers ORDER BY last_name ASC, first_name ASC");
                if ($res && $res->num_rows === 0) {
                    $res = $conn->query("SELECT id, teacher_id, first_name, middle_name, last_name, department FROM teachers ORDER BY last_name ASC, first_name ASC");
                }
                if ($res) {
                    if ($res->num_rows) {
                        while ($row = $res->fetch_assoc()) {
                            $fullName = htmlspecialchars($row['first_name'] . ' ' . ($row['middle_name'] ? $row['middle_name'] . ' ' : '') . $row['last_name']);
                            $passwordHint = htmlspecialchars($row['id'] . $row['last_name']);
                            echo '<tr>';
                            echo '<td>' . htmlspecialchars($row['teacher_id'] ?? ('T-' . sprintf('%03d', $row['id']))) . '</td>';
                            echo '<td>' . $fullName . '</td>';
                            echo '<td>' . htmlspecialchars($row['department'] ?? '') . '</td>';
                            echo '<td><div class="password-cell"><span class="password-dots" id="pwd-dots-' . intval($row['id']) . '">••••••••</span><span class="password-text" id="pwd-text-' . intval($row['id']) . '" style="display:none;">' . $passwordHint . '</span><button type="button" class="icon-button" onclick="togglePassword(' . intval($row['id']) . ')" title="Show/Hide password"><i class="fa-solid fa-eye" id="pwd-eye-' . intval($row['id']) . '"></i></button></div></td>';
                            echo '<td class="actions-cell">';
                            echo '<a href="?edit_teacher=' . intval($row['id']) . '" class="icon-button" title="Edit teacher" style="text-decoration:none;"><i class="fa-solid fa-pen-to-square" style="color:#10b981;"></i></a>';
                            echo '<form method="post" style="display:inline;" onsubmit="return confirm(\'Delete this teacher?\');"><input type="hidden" name="delete_teacher" value="1"><input type="hidden" name="teacher_id" value="' . intval($row['id']) . '"><button type="submit" class="icon-button" title="Delete teacher"><i class="fa-solid fa-trash-can"></i></button></form>';
                            echo '</td>';
                            echo '</tr>';
                        }
                    } else {
                        echo '<tr><td colspan="5" style="text-align:center;color:#6b7280;padding:18px;">No teachers found for the selected term.</td></tr>';
                    }
                } else {
                    echo '<tr><td colspan="5" style="text-align:center;color:#6b7280;padding:18px;">Query error.</td></tr>';
                }
                $conn->close();
            } else {
                echo '<tr><td colspan="5" style="text-align:center;color:#6b7280;padding:18px;">Database unavailable.</td></tr>';
            }
            ?>
            </tbody>
          </table>
        </div>
    </div>
  </div>
  <script>
    function syncGlobalFilter() {
      const year = document.getElementById('global-filter-year').value;
      const sem  = document.getElementById('global-filter-sem').value;
      const url  = new URL(window.location);
      url.searchParams.set('global_year', year);
      url.searchParams.set('global_sem', sem);
      url.searchParams.delete('school_year');
      url.searchParams.delete('semester');
      url.searchParams.delete('academic_year');
      window.location.href = url.toString();
    }

    function openTeacherModal() {
      document.getElementById('teacher-modal').style.display = 'flex';
      document.getElementById('btn-add-teacher').style.display = 'none';
      document.getElementById('form-title').textContent = 'Add Teacher';
      document.getElementById('form-subtitle').textContent = 'Enter the teacher details below.';
      const submitBtn = document.querySelector('#teacher-form button[type="submit"]');
      submitBtn.name = 'add_teacher';
      submitBtn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Teacher';
    }
    function closeTeacherModal() {
      document.getElementById('teacher-modal').style.display = 'none';
      document.getElementById('btn-add-teacher').style.display = 'inline-flex';
      document.getElementById('teacher-form').reset();
      document.getElementById('teacher-id-field').value = '';
      document.getElementById('form-title').textContent = 'Add Teacher';
      document.getElementById('form-subtitle').textContent = 'Enter the teacher details below.';
      const submitBtn = document.querySelector('#teacher-form button[type="submit"]');
      submitBtn.name = 'add_teacher';
      submitBtn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Teacher';
    }
    function togglePassword(id) {
      const dots = document.getElementById('pwd-dots-' + id);
      const text = document.getElementById('pwd-text-' + id);
      const eye = document.getElementById('pwd-eye-' + id);
      if (dots.style.display === 'none') {
        dots.style.display = '';
        text.style.display = 'none';
        eye.className = 'fa-solid fa-eye';
      } else {
        dots.style.display = 'none';
        text.style.display = '';
        eye.className = 'fa-solid fa-eye-slash';
      }
    }
    function filterTeachers(query) {
      query = query.toLowerCase();
      document.querySelectorAll('#teachers-table tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(query) ? '' : 'none';
      });
    }
    document.querySelectorAll('.table-search-input').forEach(input => {
      input.addEventListener('input', function() {
        filterTeachers(this.value);
      });
    });

    if (document.getElementById('teacher-id-field').value) {
      document.getElementById('form-title').textContent = 'Edit Teacher';
      document.getElementById('form-subtitle').textContent = 'Update the teacher details below.';
      const submitBtn = document.querySelector('#teacher-form button[type="submit"]');
      submitBtn.name = 'update_teacher';
      submitBtn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Update Teacher';
    }
  </script>
</body>
</html>