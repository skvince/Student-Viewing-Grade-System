<?php
require_once __DIR__ . '/functions.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'teacher') {
    if (isset($_GET['teacher_id'])) {
        $_SESSION['user_role'] = 'teacher';
        $_SESSION['user_id'] = intval($_GET['teacher_id']);
    } else {
        header('Location: login.php');
        exit;
    }
}
$userId = intval($_SESSION['user_id']);
$userName = '';
$conn = db_connect();
if ($conn) {
    $stmt = $conn->prepare('SELECT first_name, middle_name, last_name FROM teachers WHERE id = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $row = $res->fetch_assoc()) {
            $userName = make_full_name($row['first_name'], $row['middle_name'], $row['last_name']);
        }
        $stmt->close();
    }
    $conn->close();
}

$activeNav = $_GET['view'] ?? 'classes';
$term = get_global_term();
$schoolYear = $term['year'];
$semester   = $term['semester'];

$_SESSION['teacher_sy']  = $schoolYear;
$_SESSION['teacher_sem'] = $semester;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Teacher Panel - CSCQC</title>
    <link rel="icon" type="image/png" href="https://cscqcph.com/images/bg/cscqcph.png"/>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <style>
        :root { --bg-color:#f1f4f2; --sidebar-bg:#ffffff; --text-color:#333333; --text-muted:#666666; --primary-green:#0e4429; --primary-green-hover:#165c39; --light-green-bg:#d8ebd4; --active-nav-bg:#b9deb3; --border-color:#e5e7eb; --card-bg:#ffffff; }
        * { box-sizing:border-box; margin:0; padding:0; font-family:"Inter",sans-serif; }
        body { background-color:var(--bg-color); color:var(--text-color); display:flex; height:100vh; overflow:hidden; }
        .sidebar { width:260px; background-color:var(--sidebar-bg); border-right:1px solid var(--border-color); display:flex; flex-direction:column; justify-content:space-between; padding:20px 0; flex-shrink:0; }
        .brand { display:flex; align-items:center; padding:0 24px; margin-bottom:30px; }
        .brand i { font-size:2rem; color:var(--primary-green); margin-right:12px; }
        .brand-text h2 { font-size:1rem; font-weight:700; color:#111827; }
        .brand-text p { font-size:0.75rem; color:var(--text-muted); }
        .nav-menu a { display:flex; align-items:center; padding:12px 24px; color:var(--text-muted); font-size:0.9rem; font-weight:500; cursor:pointer; margin-bottom:4px; border-left:4px solid transparent; text-decoration:none; transition:background-color 0.15s ease, color 0.15s ease, transform 0.15s ease; position:relative; }
        .nav-menu a:hover { background-color:var(--active-nav-bg); color:var(--primary-green); font-weight:600; transform:translateX(2px); }
        .nav-menu a:hover i { transform:scale(1.1); }
        .nav-menu a:focus { outline:none; background-color:transparent; color:var(--text-muted); font-weight:500; transform:none; }
        .nav-menu a:active { background-color:transparent; color:var(--text-muted); transform:none; }
        .nav-menu a i { margin-right:12px; font-size:1.1rem; width:20px; text-align:center; transition:transform 0.15s ease; }
        .logout-btn { margin:0 20px; padding:12px; background-color:var(--primary-green); color:white; border:none; border-radius:6px; font-weight:500; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:8px; text-decoration:none; font-size:0.9rem; transition:background 0.2s; }
        .logout-btn:hover { background-color:var(--primary-green-hover); }
        .main-content { flex-grow:1; padding:40px; overflow-y:auto; }
        .view-title { font-size:1.1rem; color:var(--primary-green); font-weight:600; margin-bottom:4px; text-transform:uppercase; letter-spacing:0.5px; }
        .view-subtitle { font-size:0.9rem; color:var(--text-muted); font-weight:400; margin-bottom:24px; }
        .global-term-container { display:flex; align-items:center; gap:24px; background-color:#0c3e21; padding:16px 24px; border-radius:12px; margin-bottom:24px; box-shadow:0 4px 6px -1px rgba(0,0,0,0.1); flex-wrap:wrap; }
        .global-term-container label { color:#ffffff; font-size:0.9rem; font-weight:700; display:flex; align-items:center; gap:8px; white-space:nowrap; }
        .global-select { padding:8px 14px; border-radius:6px; border:1px solid transparent; font-size:0.875rem; background-color:#ffffff; color:#1f2937; font-weight:500; cursor:pointer; outline:none; min-width:140px; transition:border-color 0.2s, box-shadow 0.2s; }
        .filter-group { display:flex; align-items:center; gap:12px; }
        .panel-block { background-color:var(--card-bg); border-radius:8px; padding:24px; box-shadow:0 1px 3px rgba(0,0,0,0.05); margin-bottom:24px; }
        .table-responsive { width:100%; overflow-x:auto; border:1px solid var(--border-color); border-radius:6px; background-color:#ffffff; }
        table { width:100%; border-collapse:collapse; text-align:left; font-size:0.85rem; min-width:650px; }
        th { color:#4b5563; background-color:#f9fafb; font-weight:600; text-transform:uppercase; font-size:0.75rem; padding:12px 16px; border-bottom:1px solid var(--border-color); }
        td { padding:14px 16px; color:#1f2937; border-bottom:1px solid #f3f4f6; vertical-align:middle; }
        .btn-save-all { display:inline-flex; align-items:center; gap:8px; background-color:var(--primary-green); color:white; border:none; padding:10px 20px; border-radius:8px; font-size:0.9rem; font-weight:600; cursor:pointer; box-shadow:0 1px 2px rgba(0,0,0,0.05); transition:background 0.2s, transform 0.1s; }
        .btn-save-all:hover { background-color:var(--primary-green-hover); transform:translateY(-1px); }
        .btn-request { display:inline-flex; align-items:center; gap:8px; background-color:#2563eb; color:white; border:none; padding:10px 20px; border-radius:8px; font-size:0.9rem; font-weight:600; cursor:pointer; box-shadow:0 1px 2px rgba(0,0,0,0.05); transition:background 0.2s, transform 0.1s; }
        .btn-request:hover { background-color:#1d4ed8; transform:translateY(-1px); }
        .btn-back { display:inline-flex; align-items:center; gap:8px; background-color:white; color:#4b5563; border:1px solid #d1d5db; padding:8px 16px; border-radius:6px; font-size:0.85rem; font-weight:500; cursor:pointer; }
        .status-badge { font-size:0.72rem; font-weight:800; text-transform:uppercase; padding:4px 10px; border-radius:50px; display:inline-block; letter-spacing:0.5px; }
        .badge-pass { background-color:#def7ec; color:#03543f; }
        .badge-fail { background-color:#fde8e8; color:#9b1c1c; }
        .badge-pending { background-color:#fef3c7; color:#92400e; }
        .badge-approved { background-color:#d1fae5; color:#065f46; }
        .badge-rejected { background-color:#fee2e2; color:#991b1b; }
        .badge-closed { background-color:#e5e7eb; color:#374151; }
        .badge-open { background-color:#d1fae5; color:#065f46; }
        .alert { padding:12px 16px; border-radius:6px; margin-bottom:16px; font-size:0.85rem; }
        .alert-error { background-color:#fee2e2; color:#991b1b; }
        .modal-backdrop { position:fixed; inset:0; display:none; align-items:center; justify-content:center; background:rgba(15,23,42,0.4); backdrop-filter:blur(10px); z-index:999; padding:24px; }
        .modal-card { background-color:#ffffff; border:1px solid #d1d5db; border-radius:18px; padding:24px; box-shadow:0 8px 24px rgba(15,23,42,0.08); position:relative; max-width:480px; width:100%; }
        .modal-close { position:absolute; top:18px; right:18px; background:transparent; border:none; color:#6b7280; font-size:1.1rem; cursor:pointer; padding:8px; }
        .form-group { margin:10px 5px 20px; }
        .form-group label { display:block; font-size:0.85rem; color:var(--text-muted); margin-bottom:8px; font-weight:500; }
        .form-control { width:100%; padding:10px 12px; border:1px solid #d1d5db; border-radius:6px; background-color:#fff; font-size:0.9rem; color:#1f2937; outline:none; }
        select.form-control { appearance:none; padding-right:36px; }
        .form-control:focus { border-color:var(--primary-green); box-shadow:0 0 0 3px rgba(14,68,41,0.15); }
        .btn-submit { background-color:var(--primary-green); color:white; border:none; margin-bottom:8px; margin-left:7px; padding:10px 20px; border-radius:6px; font-size:0.9rem; font-weight:500; cursor:pointer; display:flex; align-items:center; gap:6px; }
        .btn-cancel { background-color:#e5e7eb; color:#374151; border:none; padding:10px 20px; border-radius:6px; font-size:0.9rem; font-weight:500; cursor:pointer; }
        .grade-submission-wrapper { background:#ffffff; border-radius:10px; border:1px solid #e5e7eb; padding:4px; }
        .grade-form { display:flex; flex-direction:column; gap:16px; }
        .grade-table { width:100%; border-collapse:separate; border-spacing:0; border-radius:8px; overflow:hidden; background:#ffffff; border:1px solid #e5e7eb; }
        .grade-table thead th { background-color:#f3f4f6; color:#374151; font-weight:600; font-size:0.8rem; text-transform:uppercase; padding:12px 16px; border-bottom:1px solid #e5e7eb; text-align:left; }
        .grade-table tbody td { padding:12px 16px; border-bottom:1px solid #f3f4f6; vertical-align:middle; color:#1f2937; font-size:0.9rem; }
        .grade-table tbody tr:last-child td { border-bottom:none; }
        .grade-table tbody tr:hover { background-color:#f9fafb; }
        .student-id { font-weight:600; color:#111827; display:inline-block; padding:4px 8px; background:#f3f4f6; border-radius:6px; font-size:0.82rem; letter-spacing:0.3px; }
        .student-name { font-weight:500; color:#1f2937; }
        .grade-input-wrapper { display:flex; align-items:center; justify-content:center; }
        .grade-input { width:110px; padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:0.9rem; text-align:center; transition:border-color 0.2s, box-shadow 0.2s; background:#ffffff; color:#111827; }
        .grade-input:focus { border-color:var(--primary-green); box-shadow:0 0 0 3px rgba(14,68,41,0.15); outline:none; }
        .grade-input:disabled { background-color:#f3f4f6; color:#9ca3af; cursor:not-allowed; border-color:#e5e7eb; }
        .form-actions { display:flex; justify-content:flex-end; padding-top:4px; }
        .sections-grid, .subjects-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(min(100%, 240px), 1fr)); gap:16px; width:100%; }
        .section-card, .subject-card { background:#ffffff; border:1px solid var(--border-color); border-radius:10px; padding:18px; display:flex; flex-direction:column; justify-content:space-between; text-decoration:none; color:inherit; box-shadow:0 1px 3px rgba(0,0,0,0.04); transition:transform .15s ease, box-shadow .15s ease, border-color .15s; width:100%; min-height:140px; box-sizing:border-box; }
        .section-card:hover, .subject-card:hover { transform:translateY(-2px); box-shadow:0 10px 15px -3px rgba(0,0,0,0.08); border-color:#cbd5e1; }
        .section-card-header, .subject-card-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; gap:10px; }
        .section-code, .subject-code { font-weight:700; font-size:1.05rem; color:#111827; }
        .badge-subjects, .subject-units { font-size:0.78rem; font-weight:600; color:#0e4429; background:#d8ebd4; padding:5px 12px; border-radius:50px; white-space:nowrap; }
        .section-body, .subject-body { color:#4b5563; font-size:0.95rem; flex-grow:1; }
        .section-card-footer, .subject-card-footer { margin-top:16px; }
        .btn-select { display:inline-flex; align-items:center; gap:6px; background:var(--primary-green); color:white; padding:8px 14px; border-radius:6px; font-size:0.85rem; font-weight:600; }
        .empty-state { padding:28px; text-align:center; color:var(--text-muted); background:var(--card-bg); border:1px solid var(--border-color); border-radius:8px; }
        .sidebar-toggle { display:none; position:fixed; top:16px; left:16px; z-index:110; background:var(--primary-green); color:white; border:none; border-radius:8px; width:40px; height:40px; cursor:pointer; font-size:1.2rem; line-height:1; box-shadow:0 2px 6px rgba(0,0,0,0.15); transition:background 0.2s, transform 0.2s; }
        .sidebar-toggle:hover { background:var(--primary-green-hover); transform:scale(1.05); }
        @media (max-width:1024px) { 
            body { display:block; padding-top:0; } 
            .sidebar-toggle { display:flex; align-items:center; justify-content:center; }
            .sidebar { position:fixed; top:0; left:0; bottom:0; transform:translateX(-100%); width:260px; height:100vh; box-shadow:4px 0 10px rgba(0,0,0,0.1); transition:transform .3s ease; z-index:105; padding-top:60px; }
            .sidebar.is-open { transform:translateX(0); }
            .main-content { padding:20px; padding-top:70px; }
            .sidebar-overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,0.4); z-index:104; }
            .sidebar-overlay.is-open { display:block; }
        }
    </style>
</head>
<body>
    <button class="sidebar-toggle" id="sidebar-toggle" aria-label="Toggle navigation">
        <i class="fa-solid fa-bars"></i>
    </button>
    <div class="sidebar-overlay" id="sidebar-overlay"></div>
    <aside class="sidebar" id="sidebar">
        <div>
            <div class="brand"><i class="fa-solid fa-graduation-cap"></i><div class="brand-text"><h2>Teacher Panel</h2><p>CSCQC</p></div></div>
            <nav class="nav-menu" aria-label="Main Navigation">
                <a href="teacher_module.php"><i class="fa-solid fa-list-ul"></i> My Classes</a>
                <a href="teacher_requests.php"><i class="fa-solid fa-paper-plane"></i> My Requests</a>
            </nav>
        </div>
        <div style="padding:0 20px 20px 20px; text-align:center;">
            <a href="login.php?logout=1" class="logout-btn"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
        </div>
    </aside>

    <main class="main-content">
        <?php if ($activeNav === 'classes'): ?>
            <h1 class="view-title">Welcome, <?php echo htmlspecialchars($userName); ?></h1>
            <p class="view-subtitle">Teacher Panel - CSCQC</p>
            <div class="global-term-container">
                <div class="filter-group">
                    <label for="global-sy-select"><i class="fa-solid fa-calendar-days"></i> Academic Year:</label>
                    <select id="global-sy-select" class="global-select">
                        <?php foreach (['2025-2026','2026-2027'] as $y): ?>
                            <option value="<?php echo $y; ?>" <?php echo $schoolYear===$y?'selected':''; ?>><?php echo $y; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="global-sem-select"><i class="fa-solid fa-clock"></i> Semester:</label>
                    <select id="global-sem-select" class="global-select">
                        <option value="1st Semester" <?php echo $semester==='1st Semester'?'selected':''; ?>>1st Semester</option>
                        <option value="2nd Semester" <?php echo $semester==='2nd Semester'?'selected':''; ?>>2nd Semester</option>
                        <option value="Summer" <?php echo $semester==='Summer'?'selected':''; ?>>Summer</option>
                    </select>
                </div>
            </div>
        <?php endif; ?>
