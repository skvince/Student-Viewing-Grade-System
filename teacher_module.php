<?php
require_once __DIR__ . '/inc/functions.php';
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
        $g = get_grade($st['id'], $activeSubjectId, $schoolYear, $semester);
        $gradesData[$st['id']] = $g ?: ['prelim'=>'', 'midterm'=>'', 'finals'=>'', 'average'=>'', 'gwa'=>'', 'remarks'=>''];
    }
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
                $conn2->close();
            }

            audit_log('save_' . $period, $userId, $period, $sy, $sem, null, $userId, $subjectId, $sectionId);
        }
    }

    header('Location: ' . $_SERVER['PHP_SELF'] . '?section_id=' . $sectionId . '&subject_id=' . $activeSubjectId . '&global_year=' . urlencode($schoolYear) . '&global_sem=' . urlencode($semester));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_permission'])) {
    if (!verify_csrf()) { header('Location: ' . $_SERVER['PHP_SELF'] . '?global_year=' . urlencode($schoolYear) . '&global_sem=' . urlencode($semester)); exit; }
    $reqSectionId = intval($_POST['request_section_id'] ?? 0);
    $reqSubjectId = intval($_POST['request_subject_id'] ?? 0);
    $reqPeriod = strtolower(trim($_POST['request_period'] ?? ''));
    $reqReason = trim($_POST['request_reason'] ?? '');

    if (in_array($reqPeriod, ['prelim', 'midterm', 'finals'], true) && $reqReason !== '' && $reqSectionId && $reqSubjectId) {
        save_grade_change_request($userId, $reqSectionId, $reqSubjectId, $schoolYear, $semester, $reqPeriod, $reqReason);
    }

    header('Location: ' . $_SERVER['PHP_SELF'] . '?global_year=' . urlencode($schoolYear) . '&global_sem=' . urlencode($semester));
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
        <div class="alert alert-error" style="margin-bottom:24px;"><i class="fa-solid fa-lock"></i> <strong>Submission locked.</strong> This grading period is currently closed for <?php echo htmlspecialchars($schoolYear . ' ' . $semester); ?>. You can request temporary access.</div>
    <?php endif; ?>

    <?php
    $anyOpen = ($isPeriodOpen['prelim'] ?? false) || ($isPeriodOpen['midterm'] ?? false) || ($isPeriodOpen['finals'] ?? false);
    $allOpen = ($isPeriodOpen['prelim'] ?? false) && ($isPeriodOpen['midterm'] ?? false) && ($isPeriodOpen['finals'] ?? false);
    ?>

    <div class="panel-block">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
            <h2 class="block-title" style="margin:0;">Grade Entry</h2>
            <?php if (!$allOpen): ?>
                <button type="button" class="btn btn-request" id="btn-request-permission"><i class="fa-solid fa-hand"></i> Request Permission to Edit</button>
            <?php endif; ?>
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
                <div class="grades-grid">
                    <div class="grade-row header">
                        <div>Student ID</div>
                        <div>Name</div>
                        <div>Prelim <span class="status-badge badge-<?php echo $isPeriodOpen['prelim'] ? 'open' : 'closed'; ?>"><?php echo $isPeriodOpen['prelim'] ? 'Open' : 'Closed'; ?></span></div>
                        <div>Midterm <span class="status-badge badge-<?php echo $isPeriodOpen['midterm'] ? 'open' : 'closed'; ?>"><?php echo $isPeriodOpen['midterm'] ? 'Open' : 'Closed'; ?></span></div>
                        <div>Finals <span class="status-badge badge-<?php echo $isPeriodOpen['finals'] ? 'open' : 'closed'; ?>"><?php echo $isPeriodOpen['finals'] ? 'Open' : 'Closed'; ?></span></div>
                        <div>Total Grade</div>
                        <div>GWA</div>
                    </div>
                    <?php foreach ($students as $st): 
                        $g = $gradesData[$st['id']] ?? [];
                        $prelimDisabled = ($isPeriodOpen['prelim'] ?? false) ? '' : 'disabled';
                        $midtermDisabled = ($isPeriodOpen['midterm'] ?? false) ? '' : 'disabled';
                        $finalsDisabled = ($isPeriodOpen['finals'] ?? false) ? '' : 'disabled';
                    ?>
                    <div class="grade-row">
                        <div><span class="student-id"><?php echo htmlspecialchars($st['student_id']); ?></span><input type="hidden" name="student_ids[]" value="<?php echo $st['id']; ?>"></div>
                        <div><span class="student-name"><?php echo htmlspecialchars(make_full_name($st['first_name'] ?? '', $st['middle_name'] ?? '', $st['last_name'] ?? '')); ?></span></div>
                        <div><div class="grade-input-wrapper"><input type="number" step="0.01" name="prelim_values[]" class="grade-input" data-period="prelim" value="<?php echo htmlspecialchars($g['prelim'] ?? ''); ?>" <?php echo $prelimDisabled; ?>></div></div>
                        <div><div class="grade-input-wrapper"><input type="number" step="0.01" name="midterm_values[]" class="grade-input" data-period="midterm" value="<?php echo htmlspecialchars($g['midterm'] ?? ''); ?>" <?php echo $midtermDisabled; ?>></div></div>
                        <div><div class="grade-input-wrapper"><input type="number" step="0.01" name="finals_values[]" class="grade-input" data-period="finals" value="<?php echo htmlspecialchars($g['finals'] ?? ''); ?>" <?php echo $finalsDisabled; ?>></div></div>
                        <div><span class="total-grade">-</span></div>
                        <div><span class="gwa-badge">-</span></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($anyOpen): ?>
                    <div class="form-actions" style="margin-top:16px;">
                        <button type="submit" class="btn-save-all"><i class="fa-solid fa-floppy-disk"></i> Save Grades</button>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    </div>
    <?php if (!$allOpen): ?>
    <div id="request-modal-backdrop" class="modal-backdrop">
        <div class="modal-card">
            <button type="button" class="modal-close" id="btn-close-request-modal"><i class="fa-solid fa-xmark"></i></button>
            <h3 style="margin-bottom:16px;">Request Permission to Edit Grades</h3>
            <form method="post" id="request-form">
                <?php echo csrf_field(); ?>
                <div class="form-group">
                    <label>Grading Period</label>
                    <select name="request_period" id="request-period" class="form-control" required>
                        <?php foreach (['prelim' => 'Prelim', 'midterm' => 'Midterm', 'finals' => 'Finals'] as $key => $label): ?>
                            <option value="<?php echo $key; ?>" <?php echo (!$isPeriodOpen[$key]) ? 'selected' : ''; ?>><?php echo $label; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Section</label>
                    <select name="request_section_id" class="form-control" required>
                        <option value="">Select section</option>
                        <?php foreach ($sections as $sec): ?>
                            <option value="<?php echo (int)$sec['id']; ?>" <?php echo $activeSectionId==$sec['id']?'selected':''; ?>><?php echo htmlspecialchars($sec['code'] ?? $sec['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Subject</label>
                    <select name="request_subject_id" class="form-control" required>
                        <option value="">Select subject</option>
                        <?php if ($activeSectionId && isset($sections[$activeSectionId]['subjects'])): foreach ($sections[$activeSectionId]['subjects'] as $subj): ?>
                            <option value="<?php echo (int)$subj['subject_id']; ?>" <?php echo $activeSubjectId==$subj['subject_id']?'selected':''; ?>><?php echo htmlspecialchars($subj['code'] . ' - ' . $subj['title']); ?></option>
                        <?php endforeach; endif; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Reason for Request</label>
                    <textarea name="request_reason" class="form-control" rows="4" required placeholder="Please explain why you need to edit..."></textarea>
                </div>
                <div class="form-buttons-row card-action-row">
                    <button type="submit" name="request_permission" class="btn btn-submit"><i class="fa-solid fa-paper-plane"></i> Submit Request</button>
                    <button type="button" class="btn-cancel" id="btn-cancel-request">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
<?php endif; ?>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const btnRequestPermission = document.getElementById('btn-request-permission');
        const requestModalBackdrop = document.getElementById('request-modal-backdrop');
        const btnCloseRequestModal = document.getElementById('btn-close-request-modal');
        const btnCancelRequest = document.getElementById('btn-cancel-request');

        if (btnRequestPermission && requestModalBackdrop) {
            btnRequestPermission.addEventListener('click', function() {
                requestModalBackdrop.style.display = 'flex';
            });
        }
        if (btnCloseRequestModal && requestModalBackdrop) {
            btnCloseRequestModal.addEventListener('click', function() {
                requestModalBackdrop.style.display = 'none';
            });
        }
        if (btnCancelRequest && requestModalBackdrop) {
            btnCancelRequest.addEventListener('click', function() {
                requestModalBackdrop.style.display = 'none';
            });
        }
        if (requestModalBackdrop) {
            requestModalBackdrop.addEventListener('click', function(e) {
                if (e.target === requestModalBackdrop) {
                    requestModalBackdrop.style.display = 'none';
                }
            });
        }
    });
</script>
<?php
$classesContent = ob_get_clean();
$activeNav = 'classes';
require_once __DIR__ . '/inc/teacher_layout.php';
?>
