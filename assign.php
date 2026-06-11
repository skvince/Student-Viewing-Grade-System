<?php

require_once __DIR__ . '/inc/functions.php';
session_start();
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

$teachers     = [];
$sections     = [];
$subjects     = [];
$assignments  = [];
$saveError    = '';
$subjectError = '';
$editSubjectId = isset($_GET['edit_subject']) ? intval($_GET['edit_subject']) : 0;

/* ═══════════════════════════════════════════════════════════
   HANDLER 1 — DELETE ASSIGNMENT
   ═══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_assignment'])) {
    $assignmentId = intval($_POST['assignment_id'] ?? 0);
    if ($assignmentId) {
        $conn = db_connect();
        if ($conn) {
            $st = $conn->prepare("DELETE FROM assignments WHERE id = ? LIMIT 1");
            if ($st) {
                $st->bind_param('i', $assignmentId);
                $st->execute();
                $st->close();
                audit_log('delete_assignment');
            }
            $conn->close();
        }
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

/* ═══════════════════════════════════════════════════════════
   HANDLER 2 — UPDATE SUBJECT
   ═══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_subject'])) {
    $editSubjectId = intval($_POST['subject_id'] ?? 0);
    $subCode  = trim($_POST['subject_code']  ?? '');
    $subTitle = trim($_POST['subject_title'] ?? '');
    $subYear  = trim($_POST['sub_school_year'] ?? '');
    $subSem   = trim($_POST['sub_semester']  ?? '');
    $subUnits = intval($_POST['subject_units'] ?? 3);

    if (!$editSubjectId || !$subCode || !$subTitle || !$subYear || !$subSem) {
        $subjectError = 'All subject fields are required.';
    } else {
        $conn = db_connect();
        if ($conn) {
            $st = $conn->prepare(
                "UPDATE subjects SET subject_code = ?, title = ?, school_year = ?, semester = ?, units = ?
                 WHERE id = ? LIMIT 1"
            );
            if ($st) {
                $st->bind_param('ssssii', $subCode, $subTitle, $subYear, $subSem, $subUnits, $editSubjectId);
                if ($st->execute()) {
                    $st->close();
                    audit_log('update_subject');
                    $conn->close();
                    header('Location: ' . $_SERVER['PHP_SELF']);
                    exit;
                } else {
                    $subjectError = ($conn->errno === 1062)
                        ? "Subject code \"$subCode\" already exists."
                        : 'Subject update failed: ' . $st->error;
                    $st->close();
                }
            } else {
                $subjectError = 'Subject prepare failed: ' . $conn->error;
            }
            $conn->close();
        }
    }
}

/* ═══════════════════════════════════════════════════════════
   HANDLER 3 — DELETE SUBJECT
   Blocked if subject is referenced by any assignment (FK safety).
   ═══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_subject'])) {
    $subjectId = intval($_POST['subject_id'] ?? 0);
    if ($subjectId) {
        $conn = db_connect();
        if ($conn) {
            // Refuse if already assigned
            $chk = $conn->prepare("SELECT id FROM assignments WHERE subject_id = ? LIMIT 1");
            $inUse = true; // fail-safe default
            if ($chk) {
                $chk->bind_param('i', $subjectId);
                $chk->execute();
                $chk->store_result();
                $inUse = ($chk->num_rows > 0);
                $chk->close();
            }
            if (!$inUse) {
                $st = $conn->prepare("DELETE FROM subjects WHERE id = ? LIMIT 1");
                if ($st) {
                    $st->bind_param('i', $subjectId);
                    $st->execute();
                    $st->close();
                    audit_log('delete_subject');
                }
            }
            $conn->close();
        }
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

/* ═══════════════════════════════════════════════════════════
   HANDLER 4 — SAVE SUBJECT (CREATE)
   ═══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_subject']) && !isset($_POST['update_subject'])) {
    $subCode  = trim($_POST['subject_code']  ?? '');
    $subTitle = trim($_POST['subject_title'] ?? '');
    $subYear  = trim($_POST['sub_school_year'] ?? '');
    $subSem   = trim($_POST['sub_semester']  ?? '');
    $subUnits = intval($_POST['subject_units'] ?? 3);

    if (!$subCode || !$subTitle || !$subYear || !$subSem) {
        $subjectError = 'All subject fields are required.';
    } else {
        $conn = db_connect();
        if ($conn) {
            $st = $conn->prepare(
                "INSERT INTO subjects (subject_code, title, school_year, semester, units)
                 VALUES (?, ?, ?, ?, ?)"
            );
            if ($st) {
                $st->bind_param('ssssi', $subCode, $subTitle, $subYear, $subSem, $subUnits);
                if ($st->execute()) {
                    $st->close();
                    audit_log('create_subject');
                    $conn->close();
                    header('Location: ' . $_SERVER['PHP_SELF']);
                    exit;
                } else {
                    $subjectError = ($conn->errno === 1062)
                        ? "Subject code \"$subCode\" already exists."
                        : 'Subject save failed: ' . $st->error;
                    $st->close();
                }
            } else {
                $subjectError = 'Subject prepare failed: ' . $conn->error;
            }
            $conn->close();
        }
    }
}

/* ═══════════════════════════════════════════════════════════
   HANDLER 5 — SAVE ASSIGNMENT
   FIX A: INSERT now provides subject_id (required NOT NULL FK).
   FIX B: also stores module as "CODE - Title" for legacy reads.
   FIX C: bind_param corrected from 'iissss' (wrong) → 'iiiss'.
   ═══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_assignment'])) {
    $teacherId  = intval($_POST['teacher_id']  ?? 0);
    $sectionId  = intval($_POST['section_id']  ?? 0);
    $subjectId  = intval($_POST['subject_id']  ?? 0);   // ← was missing
    $schoolYear = trim($_POST['school_year']   ?? '');
    $semester   = trim($_POST['semester']      ?? '');

    if (!$teacherId || !$sectionId || !$subjectId) {
        $saveError = 'Teacher, Section, and Subject Module are required.';
    } else {
        $conn = db_connect();
        if ($conn) {
            // Resolve the human-readable label for the legacy `module` column
            $moduleLabel = '';
            $subRow = $conn->query(
                "SELECT subject_code, title FROM subjects WHERE id = " . $subjectId . " LIMIT 1"
            );
            if ($subRow && ($r = $subRow->fetch_assoc())) {
                $moduleLabel = $r['subject_code'] . ' - ' . $r['title'];
                $subRow->free();
            }

            // subject_id (INT NOT NULL FK) + module (VARCHAR legacy) both populated
            $st = $conn->prepare(
                "INSERT INTO assignments
                    (teacher_id, section_id, subject_id, school_year, semester, module)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            if ($st) {
                // 'iiisss' → teacher_id INT, section_id INT, subject_id INT,
                //             school_year STR, semester STR, module STR
                $st->bind_param('iiisss',
                    $teacherId, $sectionId, $subjectId,
                    $schoolYear, $semester, $moduleLabel
                );
                if ($st->execute()) {
                    $st->close();
                    audit_log('create_assignment');
                    $conn->close();
                    header('Location: ' . $_SERVER['PHP_SELF']);
                    exit;
                } else {
                    $saveError = 'Assignment save failed: ' . $st->error;
                    $st->close();
                }
            } else {
                $saveError = 'Assignment prepare failed: ' . $conn->error;
            }
            $conn->close();
        } else {
            $saveError = 'Database connection failed.';
        }
    }
}

/* ═══════════════════════════════════════════════════════════
   HANDLER 5 — UPDATE ASSIGNMENT
   ═══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_assignment'])) {
    $assignmentId = intval($_POST['assignment_id'] ?? 0);
    $teacherId    = intval($_POST['teacher_id']    ?? 0);
    $sectionId    = intval($_POST['section_id']    ?? 0);
    $subjectId    = intval($_POST['subject_id']    ?? 0);
    $schoolYear   = trim($_POST['school_year']    ?? '');
    $semester     = trim($_POST['semester']        ?? '');

    if (!$assignmentId || !$teacherId || !$sectionId || !$subjectId) {
        $saveError = 'Teacher, Section, and Subject Module are required.';
    } else {
        $conn = db_connect();
        if ($conn) {
            $moduleLabel = '';
            $subRow = $conn->query(
                "SELECT subject_code, title FROM subjects WHERE id = " . $subjectId . " LIMIT 1"
            );
            if ($subRow && ($r = $subRow->fetch_assoc())) {
                $moduleLabel = $r['subject_code'] . ' - ' . $r['title'];
                $subRow->free();
            }

            $st = $conn->prepare(
                "UPDATE assignments
                 SET teacher_id = ?, section_id = ?, subject_id = ?, school_year = ?, semester = ?, module = ?
                 WHERE id = ? LIMIT 1"
            );
            if ($st) {
                $st->bind_param('iiisssi',
                    $teacherId, $sectionId, $subjectId,
                    $schoolYear, $semester, $moduleLabel,
                    $assignmentId
                );
                if ($st->execute()) {
                    $st->close();
                    audit_log('update_assignment');
                    $conn->close();
                    header('Location: ' . $_SERVER['PHP_SELF']);
                    exit;
                } else {
                    $saveError = 'Assignment update failed: ' . $st->error;
                    $st->close();
                }
            } else {
                $saveError = 'Assignment prepare failed: ' . $conn->error;
            }
            $conn->close();
        } else {
            $saveError = 'Database connection failed.';
        }
    }
}

/* ═══════════════════════════════════════════════════════════
   FETCH DATA FOR PAGE RENDER
   ═══════════════════════════════════════════════════════════ */
$conn = db_connect();
if ($conn) {

    // Teachers
    $res = $conn->query("SELECT id, teacher_id, name FROM teachers ORDER BY name ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) $teachers[] = $row;
        $res->free();
    }

    // Sections
    $res = $conn->query("SELECT id, section_code, name FROM sections ORDER BY created_at DESC");
    if ($res) {
        while ($row = $res->fetch_assoc()) $sections[] = $row;
        $res->free();
    }

    // Subjects — loaded from DB, not from JS DOM
    $res = $conn->query(
        "SELECT id, subject_code, title, units, school_year, semester FROM subjects ORDER BY subject_code ASC"
    );
    if ($res) {
        while ($row = $res->fetch_assoc()) $subjects[] = $row;
        $res->free();
    }

    // Assignments — JOIN to get live subject info; don't rely on free-text module column
    $res = $conn->query(
        "SELECT
             a.id           AS assignment_id,
             a.subject_id,
             a.school_year,
             a.semester,
             t.name         AS teacher_name,
             s.section_code AS section_name,
             sj.subject_code,
             sj.title       AS subject_title
         FROM assignments a
         LEFT JOIN teachers  t  ON a.teacher_id  = t.id
         LEFT JOIN sections  s  ON a.section_id  = s.id
         LEFT JOIN subjects  sj ON a.subject_id  = sj.id
         ORDER BY a.created_at DESC"
    );
    if ($res) {
        while ($row = $res->fetch_assoc()) $assignments[] = $row;
        $res->free();
    }

    $editSubject = null;
    if (isset($_GET['edit_subject']) ? intval($_GET['edit_subject']) : 0) {
        $q = $conn->query("SELECT id, subject_code, title, units, school_year, semester FROM subjects WHERE id = " . intval($_GET['edit_subject']) . " LIMIT 1");
        if ($q) { $editSubject = $q->fetch_assoc(); $q->free(); }
    }

    $conn->close();
}

$assignedSubjectIds = array_unique(
    array_column(array_filter($assignments ?? [], fn($a) => !empty($a['subject_id'])), 'subject_id')
);
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
    * { box-sizing: border-box; margin: 0; padding: 0; font-family: "Inter", sans-serif; }
    body { background-color: var(--bg-color); color: var(--text-color); display: grid; grid-template-columns: 260px 1fr; min-height: 100vh; }

    /* Mobile header */
    .mobile-header { display: none; background-color: var(--sidebar-bg); border-bottom: 1px solid var(--border-color); padding: 12px 20px; align-items: center; justify-content: space-between; position: fixed; top: 0; left: 0; right: 0; z-index: 100; height: 60px; }
    .menu-toggle-btn { font-size: 1.3rem; color: var(--primary-green); cursor: pointer; padding: 4px 8px; }
    #sidebar-toggle { display: none; }

    /* Sidebar */
    .sidebar { background-color: var(--sidebar-bg); border-right: 1px solid var(--border-color); display: flex; flex-direction: column; justify-content: space-between; padding: 20px 0; height: 100vh; position: sticky; top: 0; z-index: 90; }
    .brand { display: flex; align-items: center; padding: 0 24px; margin-bottom: 30px; }
    .brand i { font-size: 1.8rem; color: var(--primary-green); margin-right: 12px; }
    .brand-text h2 { font-size: 1rem; font-weight: 700; color: #111827; }
    .brand-text p { font-size: 0.75rem; color: var(--text-muted); }
    .nav-menu { list-style: none; flex-grow: 1; }
    input[type="radio"].tab-switch { display: none; }
    .nav-menu a { display: flex; align-items: center; padding: 12px 24px; color: var(--text-muted); font-size: 0.9rem; font-weight: 500; cursor: pointer; transition: all 0.2s; margin-bottom: 4px; border-left: 4px solid transparent; text-decoration: none; }
    .nav-menu a i { margin-right: 12px; font-size: 1.1rem; width: 20px; text-align: center; }
    .nav-menu a:hover { background-color: #b9deb3; color: var(--primary-green); }
    .nav-menu a.active { background-color: var(--active-nav-bg); color: var(--primary-green); font-weight: 600; }
    .logout-btn { margin: 0 20px; padding: 12px; background-color: var(--primary-green); color: white; border: none; border-radius: 6px; font-weight: 500; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none; font-size: 0.9rem; transition: background 0.2s; }
    .logout-btn:hover { background-color: var(--primary-green-hover); }

    /* Main */
    .main-content { padding: 40px; min-width: 0; }
    .global-term-container { display: flex; align-items: center; gap: 24px; background-color: #0c3e21; padding: 16px 24px; border-radius: 12px; margin-bottom: 24px; box-shadow: 0 4px 6px -1px rgba(0,0,0,.1); flex-wrap: wrap; }
    .filter-group { display: flex; align-items: center; gap: 12px; }
    .global-term-container label { color: #fff; font-size: .9rem; font-weight: 700; display: flex; align-items: center; gap: 8px; white-space: nowrap; }
    .global-select { padding: 8px 14px; border-radius: 6px; border: 1px solid transparent; font-size: .875rem; background-color: #fff; color: #1f2937; font-weight: 500; cursor: pointer; outline: none; min-width: 140px; transition: border-color .2s, box-shadow .2s; }
    .global-select:focus { border-color: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,.2); }

    /* Typography */
    .view-title { font-size: 1.25rem; color: var(--primary-green); font-weight: 600; margin-bottom: 4px; text-transform: uppercase; letter-spacing: .5px; }
    .view-subtitle { font-size: .85rem; color: var(--text-muted); margin-bottom: 24px; }

    /* Panels */
    .panel-block { background-color: var(--card-bg); border-radius: 8px; padding: 24px; margin-bottom: 24px; box-shadow: 0 1px 3px rgba(0,0,0,.05); }
    .block-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; gap: 16px; flex-wrap: wrap; }
    .block-title { font-size: 1rem; color: var(--primary-green); font-weight: 600; }

    /* Alerts */
    .alert-error   { margin: 0 0 16px; padding: 10px 14px; background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; border-radius: 8px; font-size: .875rem; }
    .alert-success { margin: 0 0 16px; padding: 10px 14px; background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; border-radius: 8px; font-size: .875rem; }

    /* Tables */
    .table-responsive { width: 100%; overflow-x: auto; -webkit-overflow-scrolling: touch; border: 1px solid var(--border-color); border-radius: 6px; background-color: #fff; }
    table { width: 100%; border-collapse: collapse; text-align: left; font-size: .85rem; min-width: 650px; }
    th { color: #4b5563; background-color: #f9fafb; font-weight: 600; text-transform: uppercase; font-size: .75rem; padding: 12px 16px; border-bottom: 1px solid var(--border-color); }
    td { padding: 14px 16px; color: #1f2937; border-bottom: 1px solid #f3f4f6; vertical-align: middle; }
    th:last-child, .actions-cell { text-align: center; width: 100px; min-width: 100px; }
    .actions-cell { white-space: nowrap; }
    .icon-button { background: transparent; border: none; padding: 4px 8px; color: inherit; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; border-radius: 4px; transition: transform .2s; }
    .icon-button:hover { transform: scale(1.15); }
    .fa-trash-can { color: #ef4444; }

    /* Search */
    .search-bar-wrap { display: flex; justify-content: flex-end; margin-bottom: 10px; }
    .search-bar-inner { position: relative; min-width: 250px; }
    .search-bar-inner i { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #aaa; pointer-events: none; }
    .table-search-input { width: 100%; padding: 6px 12px 6px 32px; border-radius: 4px; border: 1px solid #ccc; font-size: .85rem; }

    /* Forms */
    .form-group { margin: 10px 5px 20px; }
    .form-group label { display: block; font-size: .85rem; color: var(--text-muted); margin-bottom: 8px; font-weight: 500; }
    .form-control { width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; background-color: #fff; font-size: .9rem; color: #1f2937; outline: none; }
    select.form-control { appearance: none; background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%234b5563' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e"); background-repeat: no-repeat; background-position: right 12px center; background-size: 16px; padding-right: 36px; }
    .form-control:focus { border-color: var(--primary-green); box-shadow: 0 0 0 3px rgba(14,68,41,.15); }
    .form-buttons-row { display: flex; gap: 12px; align-items: center; }
    .btn-submit { background-color: var(--primary-green); color: white; border: none; margin-bottom: 8px; margin-left: 7px; padding: 10px 20px; border-radius: 6px; font-size: .9rem; font-weight: 500; cursor: pointer; display: flex; align-items: center; gap: 6px; }
    .btn-submit:hover { background-color: var(--primary-green-hover); }
    .btn-cancel { background-color: #e5e7eb; color: #374151; border: none; padding: 10px 20px; border-radius: 6px; font-size: .9rem; font-weight: 500; cursor: pointer; }
    .grid-2col { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; }
    .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 200; align-items: center; justify-content: center; }
    .modal-content { background: #fff; padding: 24px; border-radius: 8px; width: 90%; max-width: 600px; max-height: 90vh; overflow-y: auto; }

    /* Responsive */
    @media (max-width: 1024px) {
      body { grid-template-columns: 1fr; padding-top: 60px; }
      .mobile-header { display: flex; }
      .sidebar { position: fixed; top: 60px; left: 0; bottom: 0; transform: translateX(-100%); width: 260px; height: calc(100vh - 60px); box-shadow: 4px 0 10px rgba(0,0,0,.1); transition: transform .3s ease; }
      #sidebar-toggle:checked ~ .sidebar { transform: translateX(0); }
      .main-content { padding: 20px; }
    }
    @media (max-width: 768px) {
      .grid-2col { grid-template-columns: 1fr; gap: 0; }
    }
    @media (max-width: 480px) {
      .panel-block { padding: 16px; }
    }
  </style>
</head>
<body>
  <input type="checkbox" id="sidebar-toggle" />

  <header class="mobile-header">
    <div class="brand" style="padding:0;margin:0;">
      <i class="fa-solid fa-graduation-cap" style="font-size:1.5rem;color:var(--primary-green);margin-right:10px;"></i>
      <div class="brand-text"><h2 style="font-size:.9rem;">Admin Panel</h2></div>
    </div>
    <label for="sidebar-toggle" class="menu-toggle-btn">
      <i class="fa-solid fa-bars"></i>
    </label>
  </header>

  <aside class="sidebar">
    <div>
      <div class="brand">
        <i class="fa-solid fa-graduation-cap"></i>
        <div class="brand-text"><h2>Admin Panel</h2><p>CSCQC</p></div>
      </div>
      <nav class="nav-menu" aria-label="Main Navigation">
        <a href="admin.php">   <i class="fa-solid fa-table-cells-large"></i> Dashboard</a>
        <a href="teachers.php"><i class="fa-solid fa-users"></i> Teachers</a>
        <a href="section.php"> <i class="fa-solid fa-book-open"></i> Section &amp; Dept</a>
        <a href="students.php"><i class="fa-solid fa-user-graduate"></i> Students</a>
        <a href="assign.php" class="active"><i class="fa-solid fa-gear"></i> Assign Module</a>
      </nav>
    </div>
    <a href="login.php?logout=1" class="logout-btn"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
  </aside>

  <div class="main-content">

    <!-- Global term filter bar -->
    <div class="global-term-container">
      <div class="filter-group">
        <label for="global-filter-year"><i class="fa-solid fa-calendar-days"></i> Academic Year:</label>
        <select id="global-filter-year" class="global-select">
          <option value="2025-2026">2025–2026</option>
          <option value="2024-2025">2024–2025</option>
        </select>
      </div>
      <div class="filter-group">
        <label for="global-filter-sem"><i class="fa-solid fa-clock"></i> Semester:</label>
        <select id="global-filter-sem" class="global-select">
          <option value="1st Semester">1st Semester</option>
          <option value="2nd Semester">2nd Semester</option>
        </select>
      </div>
    </div>

    <h1 class="view-title">Assign Module</h1>
    <p class="view-subtitle">Manage structural modules and assign entities</p>

    <!-- ═══════════════════════════════════════════════════════
         PANEL 1 — CREATE / MANAGE SUBJECTS
         FIX: form now POSTs to PHP via name="save_subject".
              Each field has a name= attribute.
              Subjects persist in the DB across page reloads.
    ════════════════════════════════════════════════════════ -->
    <div class="panel-block" id="subject-block-container">
      <div class="block-header">
        <h2 class="block-title">Create / Manage Subjects</h2>
      </div>

      <?php if ($subjectError): ?>
        <div class="alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($subjectError) ?></div>
      <?php endif; ?>

      <form method="post" action="">
        <input type="hidden" name="subject_id" id="subject-edit-id" value="<?= intval($editSubjectId) ?>">
        <div class="grid-2col">
          <div class="form-group">
            <label for="sub-code-input">Subject Code</label>
            <input type="text" id="sub-code-input" name="subject_code"
                   class="form-control" placeholder="e.g., IT101" required
                   value="<?= htmlspecialchars($editSubject['subject_code'] ?? '', ENT_QUOTES) ?>" />
          </div>
          <div class="form-group">
            <label for="sub-name-input">Subject Title</label>
            <input type="text" id="sub-name-input" name="subject_title"
                   class="form-control" placeholder="e.g., Intro to Programming" required
                   value="<?= htmlspecialchars($editSubject['title'] ?? '', ENT_QUOTES) ?>" />
          </div>
          <div class="form-group">
            <label for="sub-units-input">Units</label>
            <input type="number" id="sub-units-input" name="subject_units"
                   class="form-control" placeholder="e.g., 3" min="1" max="6" step="1" required
                   value="<?= htmlspecialchars($editSubject['units'] ?? '3', ENT_QUOTES) ?>" />
          </div>
        </div>
        <div class="grid-2col">
          <div class="form-group">
            <label for="sub-year-select">School Year</label>
            <select id="sub-year-select" name="sub_school_year" class="form-control" required>
              <option value="" disabled <?= $editSubject ? '' : 'selected' ?>>Select academic year...</option>
              <option value="2025-2026" <?= ($editSubject['school_year'] ?? '') === '2025-2026' ? 'selected' : '' ?>>S.Y. 2025-2026</option>
              <option value="2026-2027" <?= ($editSubject['school_year'] ?? '') === '2026-2027' ? 'selected' : '' ?>>S.Y. 2026-2027</option>
            </select>
          </div>
          <div class="form-group">
            <label for="sub-sem-select">Semester</label>
            <select id="sub-sem-select" name="sub_semester" class="form-control" required>
              <option value="" disabled <?= $editSubject ? '' : 'selected' ?>>Select semester...</option>
              <option value="1st Semester" <?= ($editSubject['semester'] ?? '') === '1st Semester' ? 'selected' : '' ?>>1st Semester</option>
              <option value="2nd Semester" <?= ($editSubject['semester'] ?? '') === '2nd Semester' ? 'selected' : '' ?>>2nd Semester</option>
            </select>
          </div>
        </div>
        <div class="form-buttons-row">
          <?php if ($editSubjectId): ?>
            <button type="submit" name="update_subject" class="btn-submit">
              <i class="fa-solid fa-floppy-disk"></i> Update Subject
            </button>
            <button type="button" class="btn-cancel" id="btn-cancel-subject-edit">Cancel</button>
          <?php else: ?>
            <button type="submit" name="save_subject" class="btn-submit">
              <i class="fa-solid fa-plus"></i> Save Subject
            </button>
          <?php endif; ?>
        </div>
      </form>

      <!-- Subject table -->
      <div style="margin-top:20px;">
        <div class="search-bar-wrap">
          <div class="search-bar-inner">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" class="table-search-input" placeholder="Search subjects..." />
          </div>
        </div>
      <div class="table-responsive">
        <div class="search-bar-wrap">
          <div class="search-bar-inner">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" class="table-search-input" placeholder="Search subjects..." />
          </div>
        </div>
        <table id="subject-registry-table">
          <thead>
            <tr>
              <th>Code</th>
              <th>Subject Title</th>
              <th>Units</th>
              <th>School Year</th>
              <th>Semester</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody id="subject-table-body">
              <?php if ($subjects): ?>
                <?php foreach ($subjects as $subject):
                  // FIX: lock check uses integer ID comparison, not string matching
                  $isAssigned = in_array((int)$subject['id'], $assignedSubjectIds, true);
                ?>
                <tr>
                  <td><?= htmlspecialchars($subject['subject_code']) ?></td>
                  <td><?= htmlspecialchars($subject['title']) ?></td>
                  <td><?= htmlspecialchars($subject['units'] ?? 3) ?></td>
                  <td><?= htmlspecialchars($subject['school_year'] ?? '') ?></td>
                  <td><?= htmlspecialchars($subject['semester'] ?? '') ?></td>
                  <td class="actions-cell">
                    <?php if ($isAssigned): ?>
                      <span title="Cannot delete: subject is already assigned" style="color:#9ca3af;cursor:not-allowed;">
                        <i class="fa-solid fa-lock"></i>
                      </span>
                    <?php else: ?>
                      <a href="?edit_subject=<?= intval($subject['id']) ?>#subject-form-anchor"
                         class="icon-button" title="Edit subject" style="text-decoration:none;">
                        <i class="fa-solid fa-pen-to-square" style="color:#059669;"></i>
                      </a>
                      <form method="post" style="display:inline;margin:0;padding:0;" onsubmit="return confirm('Delete this subject?');">
                        <input type="hidden" name="delete_subject" value="1">
                        <input type="hidden" name="subject_id" value="<?= intval($subject['id']) ?>">
                        <button type="submit" class="icon-button" title="Delete subject">
                          <i class="fa-solid fa-trash-can"></i>
                        </button>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr><td colspan="5" style="text-align:center;color:#6b7280;padding:18px;">No subjects found. Add one above.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div><!-- /subject panel -->

    <!-- ═══════════════════════════════════════════════════════
         PANEL 2 — CREATE ASSIGNMENT CONNECTION
         FIX: subject dropdown populated from DB with value=subject.id
              INSERT now provides subject_id (NOT NULL FK) + module text.
              bind_param corrected to 'iiisss'.
    ════════════════════════════════════════════════════════ -->
    <div class="panel-block" id="assign-form-block">
      <div class="block-header">
        <h2 class="block-title">Create New Assignment Connection</h2>
      </div>

      <?php if ($saveError): ?>
        <div class="alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($saveError) ?></div>
      <?php endif; ?>

      <form method="post" action="">
        <fieldset style="border:none;">
          <div class="grid-2col">
            <div class="form-group">
              <label for="select-teacher">Teacher</label>
              <select id="select-teacher" name="teacher_id" class="form-control" required>
                <option value="" disabled selected hidden>Select teacher...</option>
                <?php foreach ($teachers as $t): ?>
                  <option value="<?= intval($t['id']) ?>">
                    <?= htmlspecialchars($t['name'] . ' (' . $t['teacher_id'] . ')') ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="form-group">
              <label for="select-subject">Subject Module</label>
              <!-- FIX: name="subject_id", value = subject.id (integer FK) -->
              <select id="select-subject" name="subject_id" class="form-control" required>
                <option value="" disabled selected hidden>Select subject module...</option>
                <?php foreach ($subjects as $sj): ?>
                  <option value="<?= intval($sj['id']) ?>">
                    <?= htmlspecialchars($sj['subject_code'] . ' - ' . $sj['title']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="grid-2col">
            <div class="form-group">
              <label for="select-section">Target Section</label>
              <select id="select-section" name="section_id" class="form-control" required>
                <option value="" disabled selected hidden>Select section...</option>
                <?php foreach ($sections as $sec): ?>
                  <option value="<?= intval($sec['id']) ?>">
                    <?= htmlspecialchars($sec['section_code']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="form-group">
              <label for="select-school-year">School Year</label>
              <select id="select-school-year" name="school_year" class="form-control" required>
                <option value="" disabled selected hidden>Select school year...</option>
                <option value="2025-2026">S.Y. 2025-2026</option>
                <option value="2026-2027">S.Y. 2026-2027</option>
              </select>
            </div>
          </div>

          <div class="form-group">
            <label for="select-semester">Semester</label>
            <select id="select-semester" name="semester" class="form-control" required>
              <option value="" disabled selected hidden>Select semester...</option>
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

      <!-- Assignment table -->
      <div style="margin-top:30px;">
        <div class="search-bar-wrap">
          <div class="search-bar-inner">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" class="table-search-input" placeholder="Search assignments..." />
          </div>
        </div>
        <div class="table-responsive">
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
              <?php if ($assignments): ?>
                <?php foreach ($assignments as $a): ?>
                <tr>
                  <td><?= htmlspecialchars($a['teacher_name'] ?? '—') ?></td>
                  <!-- FIX: built from JOINed subject_code + title, not the stale module text column -->
                  <td><?= htmlspecialchars(($a['subject_code'] ?? '') . ' - ' . ($a['subject_title'] ?? '')) ?></td>
                  <td><?= htmlspecialchars($a['section_name'] ?? '—') ?></td>
                  <td><?= htmlspecialchars($a['school_year'] ?? '') ?></td>
                  <td><?= htmlspecialchars($a['semester'] ?? '') ?></td>
                  <td class="actions-cell">
                    <button type="button" class="icon-button btn-edit-assignment"
                            data-id="<?= intval($a['assignment_id']) ?>"
                            data-teacher="<?= intval($a['teacher_id'] ?? 0) ?>"
                            data-subject="<?= intval($a['subject_id'] ?? 0) ?>"
                            data-section="<?= intval($a['section_id'] ?? 0) ?>"
                            data-year="<?= htmlspecialchars($a['school_year'] ?? '', ENT_QUOTES) ?>"
                            data-semester="<?= htmlspecialchars($a['semester'] ?? '', ENT_QUOTES) ?>"
                            title="Edit assignment">
                      <i class="fa-solid fa-pen-to-square" style="color:#059669;"></i>
                    </button>
                    <form method="post" style="display:inline;margin:0;padding:0;">
                      <input type="hidden" name="delete_assignment" value="1">
                      <input type="hidden" name="assignment_id" value="<?= intval($a['assignment_id']) ?>">
                      <button type="submit" class="icon-button"
                              onclick="return confirm('Delete this assignment?');"
                              title="Delete assignment">
                        <i class="fa-solid fa-trash-can"></i>
                      </button>
                    </form>
                  </td>
                </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr><td colspan="6" style="text-align:center;color:#6b7280;padding:18px;">No assignments yet.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div><!-- /assign panel -->

    <!-- Edit Assignment Modal -->
    <div class="modal-overlay" id="edit-modal">
      <div class="modal-content">
        <h3 class="block-title" style="margin-bottom:16px;">Edit Assignment</h3>
      <form method="post" action="" id="subject-form-anchor">
          <input type="hidden" name="update_assignment" value="1">
          <input type="hidden" name="assignment_id" id="edit-assignment-id">
          <div class="grid-2col">
            <div class="form-group">
              <label for="edit-teacher">Teacher</label>
              <select id="edit-teacher" name="teacher_id" class="form-control" required>
                <option value="" disabled selected hidden>Select teacher...</option>
                <?php foreach ($teachers as $t): ?>
                  <option value="<?= intval($t['id']) ?>">
                    <?= htmlspecialchars($t['name'] . ' (' . $t['teacher_id'] . ')') ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label for="edit-subject">Subject Module</label>
              <select id="edit-subject" name="subject_id" class="form-control" required>
                <option value="" disabled selected hidden>Select subject module...</option>
                <?php foreach ($subjects as $sj): ?>
                  <option value="<?= intval($sj['id']) ?>">
                    <?= htmlspecialchars($sj['subject_code'] . ' - ' . $sj['title']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="grid-2col">
            <div class="form-group">
              <label for="edit-section">Target Section</label>
              <select id="edit-section" name="section_id" class="form-control" required>
                <option value="" disabled selected hidden>Select section...</option>
                <?php foreach ($sections as $sec): ?>
                  <option value="<?= intval($sec['id']) ?>">
                    <?= htmlspecialchars($sec['section_code']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label for="edit-school-year">School Year</label>
              <select id="edit-school-year" name="school_year" class="form-control" required>
                <option value="" disabled selected hidden>Select school year...</option>
                <option value="2025-2026">S.Y. 2025-2026</option>
                <option value="2026-2027">S.Y. 2026-2027</option>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label for="edit-semester">Semester</label>
            <select id="edit-semester" name="semester" class="form-control" required>
              <option value="" disabled selected hidden>Select semester...</option>
              <option value="1st Semester">1st Semester</option>
              <option value="2nd Semester">2nd Semester</option>
            </select>
          </div>
          <div class="form-buttons-row">
            <button type="submit" class="btn-submit"><i class="fa-solid fa-floppy-disk"></i> Save Changes</button>
            <button type="button" class="btn-cancel" id="btn-close-modal">Cancel</button>
          </div>
        </form>
      </div>
    </div>

  </div><!-- /.main-content -->

  <script>
  document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('edit-modal');
    const btnClose = document.getElementById('btn-close-modal');

    document.querySelectorAll('.btn-edit-assignment').forEach(function (btn) {
      btn.addEventListener('click', function () {
        const data = btn.dataset;
        document.getElementById('edit-assignment-id').value = data.id || '';
        document.getElementById('edit-teacher').value = data.teacher || '';
        document.getElementById('edit-subject').value = data.subject || '';
        document.getElementById('edit-section').value = data.section || '';
        document.getElementById('edit-school-year').value = data.year || '';
        document.getElementById('edit-semester').value = data.semester || '';
        modal.style.display = 'flex';
      });
    });

    function closeModal() { modal.style.display = 'none'; }
    btnClose.addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });

    const subjectIdField = document.getElementById('subject-edit-id');
    const subCodeInput = document.getElementById('sub-code-input');
    const subTitleInput = document.getElementById('sub-name-input');
    const subYearSelect = document.getElementById('sub-year-select');
    const subSemSelect = document.getElementById('sub-sem-select');
    const btnCancelSubjectEdit = document.getElementById('btn-cancel-subject-edit');

    document.querySelectorAll('.btn-edit-subject').forEach(function (btn) {
      btn.addEventListener('click', function () {
        const data = btn.dataset;
        subjectIdField.value = data.id || '';
        subCodeInput.value = data.code || '';
        subTitleInput.value = data.title || '';
        subYearSelect.value = data.year || '';
        subSemSelect.value = data.semester || '';
        document.getElementById('sub-code-input').focus();
        window.scrollTo({ top: document.getElementById('subject-block-container').offsetTop - 20, behavior: 'smooth' });
      });
    });

    function cancelSubjectEdit() {
      subjectIdField.value = '';
      subCodeInput.value = '';
      subTitleInput.value = '';
      subYearSelect.value = '';
      subSemSelect.value = '';
    }
    if (btnCancelSubjectEdit) {
      btnCancelSubjectEdit.addEventListener('click', cancelSubjectEdit);
    }

    document.querySelectorAll('.table-search-input').forEach(function (input) {
      input.addEventListener('input', function () {
        const q = this.value.toLowerCase();
        const panel = this.closest('.panel-block') || this.closest('[id$="-container"]');
        if (!panel) return;
        panel.querySelectorAll('tbody tr').forEach(function (row) {
          row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
        });
      });
    });
  });
  </script>
</body>
</html>