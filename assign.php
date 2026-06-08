<?php
require_once __DIR__ . '/inc/functions.php';

$teachers = [];
$sections = [];
$assignments = [];
$saveError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_assignment'])) {
    $assignmentId = intval($_POST['assignment_id'] ?? 0);
    if ($assignmentId) {
        $conn = db_connect();
        if ($conn) {
            $deleteStmt = $conn->prepare("DELETE FROM assignments WHERE id = ?");
            if ($deleteStmt) {
                $deleteStmt->bind_param('i', $assignmentId);
                $deleteStmt->execute();
                $deleteStmt->close();
                audit_log('delete_assignment');
            }
            $conn->close();
        }
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_assignment'])) {
    $teacherId = intval($_POST['teacher_id'] ?? 0);
    $sectionId = intval($_POST['section_id'] ?? 0);
    $module = trim($_POST['module'] ?? '');
    $schoolYear = trim($_POST['school_year'] ?? '');
    $semester = trim($_POST['semester'] ?? '');

    if (!$teacherId || !$sectionId || !$module) {
        $saveError = 'Teacher, Section, and Module are required.';
    } else {
        $conn = db_connect();
        if ($conn) {
            $stmt = $conn->prepare("INSERT INTO assignments (teacher_id, section_id, module, school_year, semester) VALUES (?, ?, ?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param('iisss', $teacherId, $sectionId, $module, $schoolYear, $semester);
                if ($stmt->execute()) {
                    $stmt->close();
                    audit_log('create_assignment');
                    $conn->close();
                    header('Location: ' . $_SERVER['PHP_SELF']);
                    exit;
                } else {
                    $saveError = 'Assignment save failed: ' . $stmt->error;
                    $stmt->close();
                }
            } else {
                $saveError = 'Assignment prepare failed: ' . $conn->error;
            }
            $conn->close();
        } else {
            $saveError = 'Database connection failed';
        }
    }
}

$conn = db_connect();
if ($conn) {
    $res = $conn->query("SELECT id, teacher_id, name FROM teachers ORDER BY name ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $teachers[] = $row;
        }
        $res->free();
    }

    $res = $conn->query("SELECT id, section_code, name FROM sections ORDER BY created_at DESC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $sections[] = $row;
        }
        $res->free();
    }

    $res = $conn->query(
        "SELECT a.id AS assignment_id, a.module, a.school_year, a.semester, t.name AS teacher_name, s.section_code AS section_name " .
        "FROM assignments a " .
        "LEFT JOIN teachers t ON a.teacher_id = t.id " .
        "LEFT JOIN sections s ON a.section_id = s.id " .
        "ORDER BY a.created_at DESC"
    );
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $assignments[] = $row;
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
  <input type="radio" name="nav-tabs" id="tab-sec-dept" class="tab-switch" />
  <input type="radio" name="nav-tabs" id="tab-students" class="tab-switch" />
  <input type="radio" name="nav-tabs" id="tab-assign" checked class="tab-switch" />

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
      <h1 class="view-title">Assign Module</h1>
      <p class="view-subtitle">
        Manage structural modules and assign entities
      </p>

      <!-- Subjects Management Panel -->
      <div class="panel-block" id="subject-block-container">
        <div class="block-header">
          <h2 class="block-title" id="subject-form-title">
            Create / Manage Subjects
          </h2>
        </div>
        <form id="subject-form" onsubmit="event.preventDefault()">
          <input type="hidden" id="edit-subject-id" value="" />

          <div class="grid-2col">
            <div class="form-group">
              <label for="sub-code-input">Subject Code</label>
              <input type="text" id="sub-code-input" class="form-control" placeholder="e.g., IT101" required />
            </div>
            <div class="form-group">
              <label for="sub-name-input">Subject Title Description</label>
              <input type="text" id="sub-name-input" class="form-control" placeholder="e.g., Intro to Programming"
                required />
            </div>
          </div>

          <div class="grid-2col">
            <div class="form-group">
              <label for="sub-year-select">School Year</label>
              <select id="sub-year-select" class="form-control" required>
                <option value="" disabled selected hidden>
                  Select academic year...
                </option>
                <option value="2025-2026">S.Y. 2025-2026</option>
                <option value="2026-2027">S.Y. 2026-2027</option>
              </select>
            </div>
            <div class="form-group">
              <label for="sub-sem-select">Semester</label>
              <select id="sub-sem-select" class="form-control" required>
                <option value="" disabled selected hidden>
                  Select semester term...
                </option>
                <option value="1st Semester">1st Semester</option>
                <option value="2nd Semester">2nd Semester</option>
              </select>
            </div>
          </div>

          <div class="form-buttons-row">
            <button type="submit" class="btn-submit" id="btn-subject-submit">
              <i class="fa-solid fa-plus"></i> Save Subject
            </button>
            <button type="button" class="btn-cancel" id="btn-subject-cancel" style="display: none">
              Cancel Edit
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
              <input type="text" class="table-search-input" placeholder="Search subjects..." style="
                    width: 100%;
                    padding: 6px 12px 6px 32px;
                    border-radius: 4px;
                    border: 1px solid #ccc;
                  " />
            </div>
          </div>
          <table id="subject-registry-table">
            <thead>
              <tr>
                <th>Code</th>
                <th>Subject Title</th>
                <th>School Year</th>
                <th>Semester</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody id="subject-table-body"></tbody>
          </table>
        </div>
      </div>

      <!-- Assignment Connections Panel -->
      <div class="panel-block" id="assign-form-block">
        <div class="block-header">
          <h2 class="block-title" id="form-action-title">
            Create New Assignment Connection
          </h2>
        </div>

        <form id="assign-form" method="post">
          <fieldset style="border: none">
            <legend style="display: none">
              Module Assignment Parameters
            </legend>
            <input type="hidden" id="edit-assignment-id" value="" />

            <div class="grid-2col">
              <div class="form-group">
                <label for="select-teacher">Teacher</label>
                <select id="select-teacher" name="teacher_id" class="form-control" required>
                  <option value="" disabled selected hidden>
                    Select teacher...
                  </option>
<?php foreach ($teachers as $teacher): ?>
                  <option value="<?= htmlspecialchars($teacher['id']) ?>">
                    <?= htmlspecialchars($teacher['name'] . ' (' . $teacher['teacher_id'] . ')') ?>
                  </option>
<?php endforeach; ?>
                </select>
              </div>

              <div class="form-group">
                <label for="select-subject">Subject Module</label>
                <select id="select-subject" name="module" class="form-control" required>
                  <option value="" disabled selected hidden>
                    Select subject module...
                  </option>
                
                </select>
              </div>
            </div>

            <div class="grid-2col">
              <div class="form-group">
                <label for="select-section">Target Section Name</label>
                <select id="select-section" name="section_id" class="form-control" required>
                  <option value="" disabled selected hidden>
                    Select section node...
                  </option>
<?php foreach ($sections as $section): ?>
                  <option value="<?= htmlspecialchars($section['id']) ?>">
                    <?= htmlspecialchars($section['section_code']) ?>
                  </option>
<?php endforeach; ?>
                </select>
              </div>

              <div class="form-group">
                <label for="select-school-year">School Year Term</label>
                <select id="select-school-year" name="school_year" class="form-control" required>
                  <option value="" disabled selected hidden>
                    Select school year...
                  </option>
                  <option value="2025-2026">S.Y. 2025-2026</option>
                </select>
              </div>
            </div>

            <div class="form-group">
              <label for="select-semester">Active Session Semester</label>
              <select id="select-semester" name="semester" class="form-control" required>
                <option value="" disabled selected hidden>
                  Select academic term semester...
                </option>
                <option value="1st Semester">1st Semester</option>
                <option value="2nd Semester">2nd Semester</option>
              </select>
            </div>

            <div class="form-buttons-row">
              <button type="submit" name="save_assignment" class="btn-submit">
                <i class="fa-solid fa-link"></i> Connect Assignment
              </button>
            </div>
          </fieldset>
        </form>

        <div class="table-responsive" style="margin-top: 30px">
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
              <input type="text" class="table-search-input" placeholder="Search assignments..." style="
                    width: 100%;
                    padding: 6px 12px 6px 32px;
                    border-radius: 4px;
                    border: 1px solid #ccc;
                  " />
            </div>
          </div>
          <table id="assignment-connections-table">
            <thead>
              <tr>
                <th>Teacher</th>
                <th>Subject Module</th>
                <th>Section</th>
                <th>School Year</th>
                <th>Semester</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="assignment-table-body">
<?php
if (count($assignments)) {
    foreach ($assignments as $assignment) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($assignment['teacher_name'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($assignment['module']) . '</td>';
        echo '<td>' . htmlspecialchars($assignment['section_name'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($assignment['school_year'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($assignment['semester'] ?? '') . '</td>';
        echo '<td class="actions-cell">';
        echo '<form method="post" style="display:inline;margin:0;padding:0;">';
        echo '<input type="hidden" name="delete_assignment" value="1">';
        echo '<input type="hidden" name="assignment_id" value="' . intval($assignment['assignment_id']) . '">';
        echo '<button type="submit" class="icon-button" onclick="return confirm(\'Delete this assignment?\');" title="Delete assignment">';
        echo '<i class="fa-solid fa-trash-can"></i>';
        echo '</button>';
        echo '</form>';
        echo '</td>';
        echo '</tr>';
    }
} else {
    echo '<tr><td colspan="6" style="text-align:center; color:#666; padding:18px;">No assignments available.</td></tr>';
}
?>
          </tbody>
          </table>
        </div>
      </div>
  </div>
  <script>
    document.addEventListener('DOMContentLoaded', function () {
      const subjectForm = document.getElementById('subject-form');
      const subjectCodeInput = document.getElementById('sub-code-input');
      const subjectNameInput = document.getElementById('sub-name-input');
      const subjectYearSelect = document.getElementById('sub-year-select');
      const subjectSemSelect = document.getElementById('sub-sem-select');
      const subjectTableBody = document.getElementById('subject-table-body');
      const subjectSearchInput = document.querySelector('#subject-block-container .table-search-input');

      const assignForm = document.getElementById('assign-form');
      const teacherSelect = document.getElementById('select-teacher');
      const subjectSelect = document.getElementById('select-subject');
      const sectionSelect = document.getElementById('select-section');
      const schoolYearSelect = document.getElementById('select-school-year');
      const semesterSelect = document.getElementById('select-semester');
      const assignmentTableBody = document.getElementById('assignment-table-body');
      const assignmentSearchInput = document.querySelector('#assign-form-block .table-search-input');

      function getAssignedModules() {
        const assignedModules = new Set();
        Array.from(assignmentTableBody.querySelectorAll('tr')).forEach(row => {
          const moduleCell = row.querySelector('td:nth-child(2)');
          if (moduleCell) {
            assignedModules.add(moduleCell.textContent.trim());
          }
        });
        return assignedModules;
      }

      function addSubjectRow(code, title, year, semester) {
        const row = document.createElement('tr');
        const assignedModules = getAssignedModules();
        const isAssigned = assignedModules.has(code);
        
        row.innerHTML = `
          <td>${code}</td>
          <td>${title}</td>
          <td>${year}</td>
          <td>${semester}</td>
          <td class="actions-cell">
            ${isAssigned 
              ? '<span title="Cannot delete: This subject is already assigned" style="color: #999; cursor: not-allowed;"><i class="fa-solid fa-lock" style="color: #999;"></i></span>' 
              : '<i class="fa-solid fa-trash-can" role="button" aria-label="Delete subject" style="color: #ef4444; cursor: pointer;"></i>'}
          </td>
        `;
        
        if (!isAssigned) {
          row.querySelector('.fa-trash-can').addEventListener('click', () => {
            row.remove();
            removeSubjectOption(code);
          });
        }
        
        subjectTableBody.appendChild(row);
      }

      function removeSubjectOption(code) {
        const opt = subjectSelect.querySelector(`option[value="${code}"]`);
        if (opt) opt.remove();
      }

      function populateSubjectOption(code, title) {
        if (!subjectSelect.querySelector(`option[value="${code}"]`)) {
          const option = document.createElement('option');
          option.value = code;
          option.textContent = `${code} - ${title}`;
          subjectSelect.appendChild(option);
        }
      }

      subjectForm.addEventListener('submit', function (event) {
        event.preventDefault();
        const code = subjectCodeInput.value.trim();
        const title = subjectNameInput.value.trim();
        const year = subjectYearSelect.value;
        const semester = subjectSemSelect.value;
        if (!code || !title || !year || !semester) return;
        addSubjectRow(code, title, year, semester);
        populateSubjectOption(code, title);
        subjectForm.reset();
      });

      function filterTableRows(input, tableBody) {
        const query = input.value.toLowerCase().trim();
        Array.from(tableBody.querySelectorAll('tr')).forEach(row => {
          const match = row.textContent.toLowerCase().includes(query);
          row.style.display = match ? '' : 'none';
        });
      }

      if (subjectSearchInput) {
        subjectSearchInput.addEventListener('input', () => filterTableRows(subjectSearchInput, subjectTableBody));
      }
      if (assignmentSearchInput) {
        assignmentSearchInput.addEventListener('input', () => filterTableRows(assignmentSearchInput, assignmentTableBody));
      }
    });
  </script>
</body>
</html>