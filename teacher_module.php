<?php
require_once __DIR__ . '/inc/functions.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'teacher') {
    header('Location: login.php');
    exit;
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

$periodStatus = [
    'prelim' => get_deadline_status($schoolYear, $semester, 'prelim'),
    'midterm' => get_deadline_status($schoolYear, $semester, 'midterm'),
    'finals' => get_deadline_status($schoolYear, $semester, 'finals'),
];

$isPeriodOpen = [
    'prelim' => is_period_open_for_teacher($userId, $schoolYear, $semester, 'prelim'),
    'midterm' => is_period_open_for_teacher($userId, $schoolYear, $semester, 'midterm'),
    'finals' => is_period_open_for_teacher($userId, $schoolYear, $semester, 'finals'),
];

if ($activeSectionId && $activeSubjectId) {
    $students = get_section_students($activeSectionId);
    foreach ($students as $st) {
        $g = get_grade((int)($st['id'] ?? 0), $activeSubjectId, $schoolYear, $semester);
        $gradesData[$st['id']] = $g ?: ['prelim'=>'', 'midterm'=>'', 'finals'=>'', 'average'=>'', 'gwa'=>'', 'remarks'=>''];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_grades'])) {
    if (!verify_csrf()) { header('Location: ' . $_SERVER['PHP_SELF'] . '?section_id=' . ($_POST['section_id'] ?? 0) . '&subject_id=' . ($_POST['subject_id'] ?? 0)); exit; }
    $sectionId = intval($_POST['section_id'] ?? 0);
    $subjectId = intval($_POST['subject_id'] ?? 0);
    $sy = trim($_POST['school_year'] ?? $schoolYear);
    $sem = trim($_POST['semester'] ?? $semester);

    if (isset($_FILES['grades_csv']) && $_FILES['grades_csv']['error'] === UPLOAD_ERR_OK) {
        $file = fopen($_FILES['grades_csv']['tmp_name'], 'r');
        if ($file) {
            $conn2 = db_connect();
            if ($conn2) {
                fgetcsv($file);
                while (($row = fgetcsv($file)) !== false) {
                    $studentId = intval($row[0] ?? 0);
                    $prelim = isset($row[1]) && $row[1] !== '' ? max(0, min(100, floatval($row[1]))) : null;
                    $midterm = isset($row[2]) && $row[2] !== '' ? max(0, min(100, floatval($row[2]))) : null;
                    $finals = isset($row[3]) && $row[3] !== '' ? max(0, min(100, floatval($row[3]))) : null;

                    if ($studentId) {
                        $existing = get_grade($studentId, $subjectId, $sy, $sem);
                        if (!$existing) {
                            $stmt = $conn2->prepare("INSERT INTO grades (student_id, subject_id, teacher_id, section_id, school_year, semester, prelim, midterm, finals, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
                            if ($stmt) {
                                $stmt->bind_param('iiiisssss', $studentId, $subjectId, $userId, $sectionId, $sy, $sem, $prelim, $midterm, $finals);
                                $stmt->execute();
                                $stmt->close();
                            }
                        } else {
                            $upd = $conn2->prepare("UPDATE grades SET prelim = ?, midterm = ?, finals = ?, updated_at = NOW() WHERE student_id = ? AND subject_id = ? AND school_year = ? AND semester = ?");
                            if ($upd) {
                                $upd->bind_param('ssssiss', $prelim, $midterm, $finals, $studentId, $subjectId, $sy, $sem);
                                $upd->execute();
                                $upd->close();
                            }
                        }
                    }
                }
                fclose($file);
                $conn2->close();
            }
        }
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . '?section_id=' . $sectionId . '&subject_id=' . $subjectId . '&global_year=' . urlencode($schoolYear) . '&global_sem=' . urlencode($semester));
    exit;
}

if (isset($_GET['export_grades']) && $activeSectionId && $activeSubjectId) {
    $students = get_section_students($activeSectionId);
    $gradesData = [];
    foreach ($students as $st) {
        $g = get_grade((int)($st['id'] ?? 0), $activeSubjectId, $schoolYear, $semester);
        $gradesData[$st['id']] = $g ?: ['prelim'=>'', 'midterm'=>'', 'finals'=>''];
    }

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="grades_' . $schoolYear . '_' . $semester . '_' . ($sections[$activeSectionId]['code'] ?? $activeSectionId) . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Student ID', 'Name', 'Prelim', 'Midterm', 'Finals']);
    foreach ($students as $st) {
        $g = $gradesData[$st['id']] ?? [];
        fputcsv($output, [
            $st['student_id'] ?? '',
            make_full_name($st['first_name'] ?? '', $st['middle_name'] ?? '', $st['last_name'] ?? ''),
            $g['prelim'] ?? '',
            $g['midterm'] ?? '',
            $g['finals'] ?? ''
        ]);
    }
    fclose($output);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_all_grades'])) {
    if (!verify_csrf()) { header('Location: ' . $_SERVER['PHP_SELF'] . '?section_id=' . ($_POST['section_id'] ?? 0) . '&subject_id=' . ($_POST['subject_id'] ?? 0)); exit; }
    $sectionId = intval($_POST['section_id'] ?? 0);
    $subjectId = intval($_POST['subject_id'] ?? 0);
    $sy = trim($_POST['school_year'] ?? $schoolYear);
    $sem = trim($_POST['semester'] ?? $semester);
    $studentIds = $_POST['student_ids'] ?? [];
    $prelimValues = $_POST['prelim_values'] ?? [];
    $midtermValues = $_POST['midterm_values'] ?? [];
    $finalsValues = $_POST['finals_values'] ?? [];

    $periods = [
        'prelim' => ['values' => $prelimValues, 'allowed' => $isPeriodOpen['prelim'] ?? is_period_open_for_teacher($userId, $sy, $sem, 'prelim')],
        'midterm' => ['values' => $midtermValues, 'allowed' => $isPeriodOpen['midterm'] ?? is_period_open_for_teacher($userId, $sy, $sem, 'midterm')],
        'finals' => ['values' => $finalsValues, 'allowed' => $isPeriodOpen['finals'] ?? is_period_open_for_teacher($userId, $sy, $sem, 'finals')],
    ];

    foreach ($studentIds as $idx => $sid) {
        $sid = intval($sid);
        foreach ($periods as $period => $cfg) {
            $val = isset($cfg['values'][$idx]) && $cfg['values'][$idx] !== '' ? floatval($cfg['values'][$idx]) : null;
            if ($val !== null) {
                $val = max(0, min(100, $val));
            }
            if (!$cfg['allowed']) continue;

            $existing = get_grade($sid, $subjectId, $sy, $sem);
            $existingVal = null;
            if ($existing) {
                $existingVal = $existing[$period] ?? null;
                if ($existingVal === '') $existingVal = null;
            }

            $hasPermission = is_period_open_for_teacher($userId, $sy, $sem, $period);

            if ($existingVal !== null && !$hasPermission) {
                continue;
            }

            if (!$existing) {
                save_grade($sid, $subjectId, $userId, $sectionId, $sy, $sem, null, null, null);
            }

            $conn2 = db_connect();
            if ($conn2) {
                $upd = $conn2->prepare("UPDATE grades SET {$period} = ?, teacher_id = ?, updated_at = NOW() WHERE student_id = ? AND subject_id = ? AND school_year = ? AND semester = ?");
                if ($upd) {
                    $upd->bind_param('diiiss', $val, $userId, $sid, $subjectId, $sy, $sem);
                    $upd->execute();
                    $upd->close();
                }

                $recalc = $conn2->prepare("SELECT prelim, midterm, finals FROM grades WHERE student_id = ? AND subject_id = ? AND school_year = ? AND semester = ? LIMIT 1");
                if ($recalc) {
                    $recalc->bind_param('iiss', $sid, $subjectId, $sy, $sem);
                    $recalc->execute();
                    $rRes = $recalc->get_result();
                    if ($rRow = $rRes->fetch_assoc()) {
                        $p = $rRow['prelim'];
                        $m = $rRow['midterm'];
                        $f = $rRow['finals'];
                        if ($p !== null && $m !== null && $f !== null) {
                            $avg = round(($p + $m + $f) / 3, 2);
                            $gwa = gwa_from_percentage($avg);
                            $remarks = $avg >= 75 ? 'Passed' : 'Failed';
                            $upd2 = $conn2->prepare("UPDATE grades SET average = ?, gwa = ?, remarks = ? WHERE student_id = ? AND subject_id = ? AND school_year = ? AND semester = ?");
                            if ($upd2) {
                                $upd2->bind_param('dssiiss', $avg, $gwa, $remarks, $sid, $subjectId, $sy, $sem);
                                $upd2->execute();
                                $upd2->close();
                            }
                        }
                    }
                    $recalc->close();
                }
                $conn2->close();
            }

            audit_log('save_' . $period, $userId, $period, $sy, $sem, null, $userId, $subjectId, $sectionId);
        }
    }

    header('Location: ' . $_SERVER['PHP_SELF'] . '?section_id=' . $sectionId . '&subject_id=' . $activeSubjectId . '&global_year=' . urlencode($schoolYear) . '&global_sem=' . urlencode($semester));
    exit;
}

$activeNav = 'classes';
ob_start();
?>
<?php if (!$activeSectionId): ?>
    <h1 class="view-title">Select Section</h1>
    <div class="sections-grid">
        <?php if (empty($sections)): ?>
            <div class="empty-state">No sections found.</div>
        <?php else: foreach ($sections as $sec): ?>
            <a href="?section_id=<?php echo $sec['id']; ?>&global_year=<?php echo urlencode($schoolYear); ?>&global_sem=<?php echo urlencode($semester); ?>" class="section-card">
                <div class="section-card-header">
                    <span class="section-code"><?php echo htmlspecialchars($sec['name']); ?></span>
                    <span class="badge-subjects"><?php echo count($sec['subjects']); ?> subjects</span>
                </div>
                <div class="section-body">
                    <i class="fa-solid fa-building-columns"></i> <?php echo htmlspecialchars($sec['department'] ?? 'N/A'); ?>
                </div>
                <div class="section-card-footer">
                    <span class="btn-select">Select</span>
                </div>
            </a>
        <?php endforeach; endif; ?>
    </div>
<?php elseif ($activeSectionId && !$activeSubjectId): ?>
    <a href="?global_year=<?php echo urlencode($schoolYear); ?>&global_sem=<?php echo urlencode($semester); ?>" class="btn-back"><i class="fa-solid fa-arrow-left"></i> Back to Sections</a>
    <h1 class="view-title">Select Subject</h1>
    <div class="subjects-grid">
        <?php foreach ($sections[$activeSectionId]['subjects'] as $subj): ?>
            <a href="?section_id=<?php echo $activeSectionId; ?>&subject_id=<?php echo $subj['subject_id']; ?>&global_year=<?php echo urlencode($schoolYear); ?>&global_sem=<?php echo urlencode($semester); ?>" class="subject-card">
                <div class="subject-card-header">
                    <span class="subject-code"><?php echo htmlspecialchars($subj['code']); ?></span>
                    <span class="subject-units"><?php echo htmlspecialchars($subj['units']); ?> units</span>
                </div>
                <div class="subject-body">
                    <div class="subject-title"><?php echo htmlspecialchars($subj['title']); ?></div>
                </div>
                <div class="subject-card-footer">
                    <span class="btn-select">Select</span>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <a href="?global_year=<?php echo urlencode($schoolYear); ?>&global_sem=<?php echo urlencode($semester); ?>" class="btn-back"><i class="fa-solid fa-arrow-left"></i> Back to Sections</a>
    <h1 class="view-title">Class Grades</h1>
    <p class="view-subtitle"><?php echo htmlspecialchars("$schoolYear | $semester"); ?></p>

    <?php if (isset($_GET['err']) && $_GET['err'] === 'locked'): ?>
        <div class="alert alert-error" style="margin-bottom:24px;"><i class="fa-solid fa-lock"></i> <strong>Submission locked.</strong> This grading period is currently closed for <?php echo htmlspecialchars($schoolYear . ' ' . $semester); ?>.</div>
    <?php endif; ?>

    <?php
    $anyOpen = ($isPeriodOpen['prelim'] ?? false) || ($isPeriodOpen['midterm'] ?? false) || ($isPeriodOpen['finals'] ?? false);
    ?>

    <div class="panel-block">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
            <h2 class="block-title" style="margin:0;">Grade Entry</h2>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <button type="button" class="btn-action" id="btn-export-grades"><i class="fa-solid fa-file-export"></i> Export CSV</button>
                <button type="button" class="btn-action" id="btn-import-grades"><i class="fa-solid fa-file-import"></i> Import CSV</button>
            </div>
        </div>

        <div id="import-form-wrapper" style="display:none; margin-bottom:16px; padding:16px; background:#f9fafb; border:1px solid var(--border-color); border-radius:8px;">
            <form method="post" enctype="multipart/form-data" id="import-grades-form">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="import_grades" value="1">
                <input type="hidden" name="section_id" value="<?php echo $activeSectionId; ?>">
                <input type="hidden" name="subject_id" value="<?php echo $activeSubjectId; ?>">
                <input type="hidden" name="school_year" value="<?php echo htmlspecialchars($schoolYear); ?>">
                <input type="hidden" name="semester" value="<?php echo htmlspecialchars($semester); ?>">
                <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
                    <input type="file" name="grades_csv" accept=".csv" required style="padding:8px;">
                    <button type="submit" class="btn-submit"><i class="fa-solid fa-upload"></i> Upload</button>
                    <button type="button" class="btn-cancel" id="btn-cancel-import">Cancel</button>
                </div>
                <p style="font-size:0.8rem; color:var(--text-muted); margin-top:8px;">CSV format: Student ID, Prelim, Midterm, Finals</p>
            </form>
        </div>

        <?php if (!$anyOpen): ?>
            <div class="alert alert-error" style="margin-bottom:16px;"><i class="fa-solid fa-lock"></i> All grading periods are currently closed. Please contact the administrator to open them.</div>
        <?php endif; ?>

        <div class="grade-submission-wrapper">
            <form id="grades-form" class="grade-form" method="post">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="section_id" value="<?php echo $activeSectionId; ?>">
                <input type="hidden" name="subject_id" value="<?php echo $activeSubjectId; ?>">
                <input type="hidden" name="school_year" value="<?php echo htmlspecialchars($schoolYear); ?>">
                <input type="hidden" name="semester" value="<?php echo htmlspecialchars($semester); ?>">
                <input type="hidden" name="save_all_grades" value="1">

                <div class="table-responsive">
                    <table class="grade-table">
                        <thead>
                            <tr>
                                <th style="width:22%;">Student ID</th>
                                <th style="width:32%;">Name</th>
                                <th style="width:16%;">Prelim <span class="status-badge badge-<?php echo $isPeriodOpen['prelim'] ? 'open' : 'closed'; ?>"><?php echo $isPeriodOpen['prelim'] ? 'Open' : 'Closed'; ?></span></th>
                                <th style="width:16%;">Midterm <span class="status-badge badge-<?php echo $isPeriodOpen['midterm'] ? 'open' : 'closed'; ?>"><?php echo $isPeriodOpen['midterm'] ? 'Open' : 'Closed'; ?></span></th>
                                <th style="width:15%;">Finals <span class="status-badge badge-<?php echo $isPeriodOpen['finals'] ? 'open' : 'closed'; ?>"><?php echo $isPeriodOpen['finals'] ? 'Open' : 'Closed'; ?></span></th>
                                <th style="width:14%;">Total Grade</th>
                                <th style="width:13%;">GWA</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $st): 
                                $g = $gradesData[$st['id']] ?? [];
                                $prelimDisabled  = ($isPeriodOpen['prelim'] ?? false) ? '' : 'disabled';
                                $midtermDisabled = ($isPeriodOpen['midterm'] ?? false) ? '' : 'disabled';
                                $finalsDisabled  = ($isPeriodOpen['finals'] ?? false) ? '' : 'disabled';
                            ?>
                            <tr>
                                <td>
                                    <span class="student-id"><?php echo htmlspecialchars($st['student_id'] ?? ''); ?></span>
                                    <input type="hidden" name="student_ids[]" value="<?php echo (int)($st['id'] ?? 0); ?>">
                                </td>
                                <td><span class="student-name"><?php echo htmlspecialchars(make_full_name($st['first_name'] ?? '', $st['middle_name'] ?? '', $st['last_name'] ?? '')); ?></span></td>
                                <td><div class="grade-input-wrapper"><input type="number" step="0.01" name="prelim_values[]" class="grade-input grade-input--period" data-period="prelim" value="<?php echo htmlspecialchars($g['prelim'] ?? ''); ?>" <?php echo $prelimDisabled; ?>></div></td>
                                <td><div class="grade-input-wrapper"><input type="number" step="0.01" name="midterm_values[]" class="grade-input grade-input--period" data-period="midterm" value="<?php echo htmlspecialchars($g['midterm'] ?? ''); ?>" <?php echo $midtermDisabled; ?>></div></td>
                                <td><div class="grade-input-wrapper"><input type="number" step="0.01" name="finals_values[]" class="grade-input grade-input--period" data-period="finals" value="<?php echo htmlspecialchars($g['finals'] ?? ''); ?>" <?php echo $finalsDisabled; ?>></div></td>
                                <td><span class="total-grade"><?php echo $g['average'] !== null ? htmlspecialchars($g['average']) : '-'; ?></span></td>
                                <td><span class="gwa-badge"><?php echo $g['gwa'] !== null ? htmlspecialchars($g['gwa']) : '-'; ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($anyOpen): ?>
                    <div class="form-actions" style="margin-top:16px;">
                        <button type="submit" class="btn-save-all"><i class="fa-solid fa-floppy-disk"></i> Save Grades</button>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php
$classesContent = ob_get_clean();
$activeNav = 'classes';
require_once __DIR__ . '/inc/teacher_layout.php';
?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const btnExport = document.getElementById('btn-export-grades');
    const btnImport = document.getElementById('btn-import-grades');
    const importWrapper = document.getElementById('import-form-wrapper');
    const btnCancelImport = document.getElementById('btn-cancel-import');

    if (btnExport) {
        btnExport.addEventListener('click', function() {
            const url = new URL(window.location);
            url.searchParams.set('export_grades', '1');
            window.location.href = url.toString();
        });
    }

    if (btnImport) {
        btnImport.addEventListener('click', function() {
            if (importWrapper) importWrapper.style.display = 'block';
        });
    }

    if (btnCancelImport) {
        btnCancelImport.addEventListener('click', function() {
            if (importWrapper) importWrapper.style.display = 'none';
        });
    }

    function calculateGwa(percentage) {
        if (percentage >= 97) return '1.00';
        if (percentage >= 94) return '1.25';
        if (percentage >= 91) return '1.50';
        if (percentage >= 88) return '1.75';
        if (percentage >= 85) return '2.00';
        if (percentage >= 82) return '2.25';
        if (percentage >= 79) return '2.50';
        if (percentage >= 76) return '2.75';
        if (percentage >= 75) return '3.00';
        return '5.00';
    }

    document.querySelectorAll('.grade-input--period').forEach(function(input) {
        input.addEventListener('input', function() {
            const row = this.closest('tr');
            const prelim = row.querySelector('input[data-period="prelim"]').value;
            const midterm = row.querySelector('input[data-period="midterm"]').value;
            const finals = row.querySelector('input[data-period="finals"]').value;
            const totalGradeEl = row.querySelector('.total-grade');
            const gwaEl = row.querySelector('.gwa-badge');

            if (prelim !== '' && midterm !== '' && finals !== '') {
                const p = parseFloat(prelim);
                const m = parseFloat(midterm);
                const f = parseFloat(finals);
                if (!isNaN(p) && !isNaN(m) && !isNaN(f)) {
                    const avg = ((p + m + f) / 3).toFixed(2);
                    const gwa = calculateGwa(parseFloat(avg));
                    totalGradeEl.textContent = avg;
                    gwaEl.textContent = gwa;
                } else {
                    totalGradeEl.textContent = '-';
                    gwaEl.textContent = '-';
                }
            } else {
                totalGradeEl.textContent = '-';
                gwaEl.textContent = '-';
            }
        });
    });
});
</script>
