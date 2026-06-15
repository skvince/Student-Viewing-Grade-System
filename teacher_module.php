<?php 
require_once __DIR__ . '/inc/functions.php'; 
session_start();

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

$term = get_global_term();
$schoolYear = $term['year'];
$semester   = $term['semester'];

// Keep teacher term consistent with the global filter.
// (Avoid mixing legacy GET parameters that can cause semester flips.)
$_SESSION['teacher_sy']  = $schoolYear;
$_SESSION['teacher_sem'] = $semester;

$assignments = get_teacher_assignments($userId, $schoolYear, $semester);
$sections = [];

foreach ($assignments as $a) {
    $key = $a['section_id'];
    if (!isset($sections[$key])) {
        $sections[$key] = [
            'id' => $a['section_id'],
            'code' => $a['section_code'],
            'name' => $a['section_name'],
            'department' => $a['department'] ?? '',
            'subjects' => []
        ];
    }
    $sections[$key]['subjects'][] = [
        'assignment_id' => $a['assignment_id'],
        'subject_id' => $a['subject_id'],
        'code' => $a['subject_code'],
        'title' => $a['subject_title'],
        'units' => $a['units'] ?? 3
    ];
}

$activeSectionId = isset($_GET['section_id']) ? intval($_GET['section_id']) : 0;
$activeSubjectId = isset($_GET['subject_id']) ? intval($_GET['subject_id']) : 0;
$students = [];
$gradesData = [];

if ($activeSectionId && $activeSubjectId) {
    $students = get_section_students($activeSectionId);
    foreach ($students as $st) {
        $g = get_grade($st['id'], $activeSubjectId, $schoolYear, $semester);
        $gradesData[$st['id']] = $g ?: ['prelim'=>'', 'midterm'=>'', 'finals'=>'', 'average'=>'', 'gwa'=>'', 'remarks'=>''];
    }
}

// --- Handle Form Submission ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_grades'])) {
    $sectionId = intval($_POST['section_id'] ?? 0);
    $subjectId = intval($_POST['subject_id'] ?? 0);
    $sy = trim($_POST['school_year'] ?? '');
    $sem = trim($_POST['semester'] ?? '');
    $studentIds = $_POST['student_ids'] ?? [];
    $prelims = $_POST['prelim'] ?? [];
    $midterms = $_POST['midterm'] ?? [];
    $finals = $_POST['finals'] ?? [];

    foreach ($studentIds as $idx => $sid) {
        $sid = intval($sid);
        $p = isset($prelims[$idx]) && $prelims[$idx] !== '' ? floatval($prelims[$idx]) : null;
        $m = isset($midterms[$idx]) && $midterms[$idx] !== '' ? floatval($midterms[$idx]) : null;
        $f = isset($finals[$idx]) && $finals[$idx] !== '' ? floatval($finals[$idx]) : null;
        
        if ($p !== null) $p = max(0, min(100, $p));
        if ($m !== null) $m = max(0, min(100, $m));
        if ($f !== null) $f = max(0, min(100, $f));
        
        save_grade($sid, $subjectId, $userId, $sectionId, $sy, $sem, $p, $m, $f);
    }
    
    audit_log('save_grades', $userId);
     header('Location: ' . $_SERVER['PHP_SELF'] . '?section_id=' . $sectionId . '&subject_id=' . $subjectId . '&global_year=' . urlencode($schoolYear) . '&global_sem=' . urlencode($semester));
    exit;
}
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
        
        /* Sidebar Styles */
        .sidebar { width:260px; background-color:var(--sidebar-bg); border-right:1px solid var(--border-color); display:flex; flex-direction:column; justify-content:space-between; padding:20px 0; flex-shrink:0; }
        .brand { display:flex; align-items:center; padding:0 24px; margin-bottom:30px; }
        .brand i { font-size:2rem; color:var(--primary-green); margin-right:12px; }
        .brand-text h2 { font-size:1rem; font-weight:700; color:#111827; }
        .brand-text p { font-size:0.75rem; color:var(--text-muted); }
        .nav-item { display:flex; align-items:center; padding:12px 24px; color:var(--primary-green); background-color:var(--active-nav-bg); font-size:0.9rem; font-weight:600; cursor:pointer; border-left:4px solid transparent; width:100%; text-align:left; border:none; }
        .nav-item i { margin-right:12px; font-size:1.1rem; width:20px; text-align:center; }
        .logout-btn { margin:0 20px; padding:12px; background-color:var(--primary-green); color:white; border:none; border-radius:6px; font-weight:500; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:8px; text-decoration:none; font-size:0.9rem; transition:background 0.2s; }
        .logout-btn:hover { background-color:var(--primary-green-hover); }
        
        /* Main Content Styles */
        .main-content { flex-grow:1; padding:40px; overflow-y:auto; }
        .view-title { font-size:1.1rem; color:var(--primary-green); font-weight:600; margin-bottom:4px; text-transform:uppercase; letter-spacing:0.5px; }
        .view-subtitle { font-size:0.9rem; color:var(--text-muted); font-weight:400; margin-bottom:24px; }
        .global-term-container { display:flex; align-items:center; gap:24px; background-color:#0c3e21; padding:16px 24px; border-radius:12px; margin-bottom:24px; box-shadow:0 4px 6px -1px rgba(0,0,0,0.1); flex-wrap:wrap; }
        .global-term-container label { color:#ffffff; font-size:0.9rem; font-weight:700; display:flex; align-items:center; gap:8px; white-space:nowrap; }
        .global-select { padding:8px 14px; border-radius:6px; border:1px solid transparent; font-size:0.875rem; background-color:#ffffff; color:#1f2937; font-weight:500; cursor:pointer; outline:none; min-width:140px; transition:border-color 0.2s, box-shadow 0.2s; }
        .filter-group { display:flex; align-items:center; gap:12px; }
        
        /* Table Styles */
        .panel-block { background-color:var(--card-bg); border-radius:8px; padding:24px; box-shadow:0 1px 3px rgba(0,0,0,0.05); margin-bottom:24px; }
        .table-responsive { width:100%; overflow-x:auto; -webkit-overflow-scrolling:touch; border:1px solid var(--border-color); border-radius:6px; background-color:#ffffff; }
        table { width:100%; border-collapse:collapse; text-align:left; font-size:0.85rem; min-width:650px; }
        th { color:#4b5563; background-color:#f9fafb; font-weight:600; text-transform:uppercase; font-size:0.75rem; padding:12px 16px; border-bottom:1px solid var(--border-color); }
        td { padding:14px 16px; color:#1f2937; border-bottom:1px solid #f3f4f6; vertical-align:middle; }
        .grade-input { width:75px; padding:6px 8px; border:1px solid #d1d5db; border-radius:4px; font-size:0.85rem; text-align:center; }
        .calculated-final { font-weight:600; font-size:0.9rem; }
        .gwa-badge { font-weight:700; font-size:0.85rem; background-color:#edf2f7; padding:4px 8px; border-radius:4px; color:#2d3748; }
        .status-badge { font-size:0.72rem; font-weight:800; text-transform:uppercase; padding:4px 10px; border-radius:50px; display:inline-block; letter-spacing:0.5px; }
        .badge-pass { background-color:#def7ec; color:#03543f; }
        .badge-fail { background-color:#fde8e8; color:#9b1c1c; }
        .badge-empty { background-color:#edf2f7; color:#718096; }
        
        /* Buttons */
        .btn-back { display:inline-flex; align-items:center; gap:6px; background-color:white; color:#4b5563; border:1px solid #d1d5db; padding:8px 16px; border-radius:6px; font-size:0.85rem; font-weight:500; cursor:pointer; }
        .btn-save-all { display:inline-flex; align-items:center; gap:6px; background-color:var(--primary-green); color:white; border:none; padding:8px 18px; border-radius:6px; font-size:0.85rem; font-weight:600; cursor:pointer; margin-right:10px; }
        
        @media (max-width:1024px) { body { grid-template-columns:1fr; padding-top:60px; } .mobile-header { display:flex; } .sidebar { position:fixed; top:60px; left:0; bottom:0; transform:translateX(-100%); width:260px; height:calc(100vh - 60px); box-shadow:4px 0 10px rgba(0,0,0,0.1); transition:transform .3s ease; } #sidebar-toggle:checked ~ .sidebar { transform:translateX(0); } .main-content { padding:20px; } }
    </style>
</head>
<body>
    <input type="checkbox" id="sidebar-toggle" />
    <aside class="sidebar">
        <div>
            <div class="brand"><i class="fa-solid fa-graduation-cap"></i><div class="brand-text"><h2>Teacher Panel</h2><p>CSCQC</p></div></div>
            <nav class="nav-menu">
                <a href="teacher_module.php" class="nav-item"><i class="fa-solid fa-list-ul"></i> My Classes</a>
            </nav>
        </div>
        <div style="padding:0 20px 20px 20px; text-align:center;">
            <a href="login.php?logout=1" class="logout-btn"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
        </div>
    </aside>

    <main class="main-content">
        <h1 class="view-title">Welcome, <?php echo htmlspecialchars($userName); ?></h1>
        <p class="view-subtitle">Teacher Panel - CSCQC</p>
        <div class="global-term-container">
            <div class="filter-group">
                <label for="global-sy-select"><i class="fa-solid fa-calendar-days"></i> Academic Year:</label>
                <select id="global-sy-select" class="global-select" onchange="syncTeacherFilter()">
                    <?php foreach (['2025-2026','2026-2027'] as $y): ?>
                        <option value="<?php echo $y; ?>" <?php echo $schoolYear===$y?'selected':''; ?>><?php echo $y; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label for="global-sem-select"><i class="fa-solid fa-clock"></i> Semester:</label>
                <select id="global-sem-select" class="global-select" onchange="syncTeacherFilter()">
                    <option value="1st Semester" <?php echo $semester==='1st Semester'?'selected':''; ?>>1st Semester</option>
                    <option value="2nd Semester" <?php echo $semester==='2nd Semester'?'selected':''; ?>>2nd Semester</option>
                    <option value="Summer" <?php echo $semester==='Summer'?'selected':''; ?>>Summer</option>
                </select>
            </div>
        </div>

        <?php if (!$activeSectionId): ?>
            <h1 class="view-title">Select Section</h1>
            <div class="panel-block">
                <div class="table-responsive">
                    <table id="sections-table" style="min-width:650px;">
                        <thead>
                            <tr><th>Section Name / ID</th><th>Section Name</th><th>Department</th><th>Subjects</th><th>Action</th></tr>
                        </thead>
                        <tbody>
                        <?php if (empty($sections)): ?>
                            <tr><td colspan="5" style="text-align:center;padding:24px;">No sections found.</td></tr>
                        <?php else: foreach ($sections as $sec): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($sec['name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($sec['name']); ?></td>
                                <td><?php echo htmlspecialchars($sec['department'] ?? 'N/A'); ?></td>
                                <td><?php echo count($sec['subjects']); ?></td>
                                <td><a href="?section_id=<?php echo $sec['id']; ?>&global_year=<?php echo urlencode($schoolYear); ?>&global_sem=<?php echo urlencode($semester); ?>" style="padding:6px 14px; background-color:var(--primary-green); color:white; border-radius:6px; text-decoration:none;">Select</a></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        <?php elseif ($activeSectionId && !$activeSubjectId): ?>
            <a href="?" class="btn-back"><i class="fa-solid fa-arrow-left"></i> Back to Sections</a>
            <h1 class="view-title">Select Subject</h1>
            <div class="panel-block">
                <div class="table-responsive">
                    <table id="subjects-table" style="min-width:650px;">
                        <thead>
                            <tr><th>Subject Code</th><th>Title</th><th>Units</th><th>Action</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sections[$activeSectionId]['subjects'] as $subj): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($subj['code']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($subj['title']); ?></td>
                                    <td><?php echo htmlspecialchars($subj['units']); ?></td>
                                    <td><a href="?section_id=<?php echo $activeSectionId; ?>&subject_id=<?php echo $subj['subject_id']; ?>&global_year=<?php echo urlencode($schoolYear); ?>&global_sem=<?php echo urlencode($semester); ?>" style="padding:6px 14px; background-color:var(--primary-green); color:white; border-radius:6px; text-decoration:none;">Select</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php else: ?>
            <a href="?global_year=<?php echo urlencode($schoolYear); ?>&global_sem=<?php echo urlencode($semester); ?>" class="btn-back"><i class="fa-solid fa-arrow-left"></i> Back to Sections</a>
            <h1 class="view-title">Class Grades</h1>
            <p class="view-subtitle"><?php echo htmlspecialchars("$schoolYear | $semester"); ?></p>

            <div class="panel-block">
                <form id="grades-form" method="post">
                    <input type="hidden" name="section_id" value="<?php echo $activeSectionId; ?>">
                    <input type="hidden" name="subject_id" value="<?php echo $activeSubjectId; ?>">
                    <input type="hidden" name="school_year" value="<?php echo htmlspecialchars($schoolYear); ?>">
                    <input type="hidden" name="semester" value="<?php echo htmlspecialchars($semester); ?>">
                    <input type="hidden" name="save_grades" value="1">
                    <div class="table-responsive">
                        <table style="min-width:650px;">
                            <thead>
                                <tr><th>Student ID</th><th>Name</th><th>Prelim</th><th>Midterm</th><th>Finals</th><th>Average</th><th>GWA</th><th>Remarks</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($students as $st): 
                                    $g = $gradesData[$st['id']] ?? []; 
                                ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($st['student_id']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($st['last_name'] . ', ' . $st['first_name']); ?></td>
                                    <td><input type="number" name="prelim[]" class="grade-input" value="<?php echo htmlspecialchars($g['prelim'] ?? ''); ?>"></td>
                                    <td><input type="number" name="midterm[]" class="grade-input" value="<?php echo htmlspecialchars($g['midterm'] ?? ''); ?>"></td>
                                    <td><input type="number" name="finals[]" class="grade-input" value="<?php echo htmlspecialchars($g['finals'] ?? ''); ?>"></td>
                                    <td><span class="calculated-final"><?php echo htmlspecialchars($g['average'] ?? '--'); ?></span></td>
                                    <td><span class="gwa-badge"><?php echo htmlspecialchars($g['gwa'] ?? '--'); ?></span></td>
                                    <td><span class="status-badge <?php echo ($g['remarks'] ?? '')==='Passed'?'badge-pass':'badge-fail'; ?>"><?php echo htmlspecialchars($g['remarks'] ?? 'Pending'); ?></span></td>
                                    <input type="hidden" name="student_ids[]" value="<?php echo $st['id']; ?>">
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <button type="submit" class="btn-save-all"><i class="fa-solid fa-floppy-disk"></i> Save Marks</button>
                </form>
            </div>
        <?php endif; ?>
    </main>

    <script>
        function syncTeacherFilter() {
            const year = document.getElementById('global-sy-select').value;
            const sem  = document.getElementById('global-sem-select').value;
            const url  = new URL(window.location);
            url.searchParams.set('global_year', year);
            url.searchParams.set('global_sem', sem);
            url.searchParams.delete('school_year');
            url.searchParams.delete('semester');
            url.searchParams.delete('academic_year');
            window.location.href = url.toString();
        }

        // Grade Calculation Logic
        document.querySelectorAll('.grade-input').forEach(input => {
            input.addEventListener('input', function() {
                const row = this.closest('tr');
                const inputs = row.querySelectorAll('.grade-input');
                const p = parseFloat(inputs[0].value), m = parseFloat(inputs[1].value), f = parseFloat(inputs[2].value);
                const avgSpan = row.querySelector('.calculated-final'), gwaSpan = row.querySelector('.gwa-badge'), statusSpan = row.querySelector('.status-badge');
                
                if (!isNaN(p) && !isNaN(m) && !isNaN(f)) {
                    const avg = (p + m + f) / 3;
                    avgSpan.textContent = avg.toFixed(2) + '%';
                    gwaSpan.textContent = getGwa(avg);
                    statusSpan.textContent = avg >= 75 ? 'Passed' : 'Failed';
                    statusSpan.className = 'status-badge ' + (avg >= 75 ? 'badge-pass' : 'badge-fail');
                }
            });
        });

        function getGwa(pct) {
            if (pct >= 97) return '1.00'; if (pct >= 94) return '1.25'; if (pct >= 91) return '1.50';
            if (pct >= 88) return '1.75'; if (pct >= 85) return '2.00'; if (pct >= 82) return '2.25';
            if (pct >= 79) return '2.50'; if (pct >= 76) return '2.75'; if (pct >= 75) return '3.00';
            return '5.00';
        }
    </script>
</body>
</html>