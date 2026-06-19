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

$term = get_global_term();
$schoolYear = $term['year'];
$semester   = $term['semester'];

$_SESSION['teacher_sy']  = $schoolYear;
$_SESSION['teacher_sem'] = $semester;

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

// Build allowed sections+subjects for this teacher and current global term.
$assignments = get_teacher_assignments($userId, $schoolYear, $semester);
$sections = [];
foreach ($assignments as $a) {
    $key = $a['section_id'];
    if (!isset($sections[$key])) {
        $sections[$key] = [
            'id' => (int)$a['section_id'],
            'code' => $a['section_code'] ?? '',
            'name' => $a['section_name'] ?? '',
            'department' => $a['department'] ?? '',
            'subjects' => []
        ];
    }

    $sections[$key]['subjects'][] = [
        'assignment_id' => $a['assignment_id'] ?? null,
        'subject_id' => (int)$a['subject_id'],
        'code' => $a['subject_code'] ?? '',
        'title' => $a['subject_title'] ?? '',
        'units' => $a['units'] ?? 3,
    ];
}

// Default active section/subject for dropdown selection (prevents undefined vars).
$activeSectionId = 0;
$activeSubjectId = 0;
if (!empty($sections)) {
    $activeSectionId = (int)array_key_first($sections);
    if (!empty($sections[$activeSectionId]['subjects'])) {
        $activeSubjectId = (int)$sections[$activeSectionId]['subjects'][0]['subject_id'];
    }
}


$requests = [];
$conn = db_connect();
if ($conn) {
    $stmt = $conn->prepare("SELECT cr.id, cr.section_id, cr.subject_id, cr.school_year, cr.semester, cr.grading_period, cr.reason, cr.status, cr.admin_response, cr.expires_at, cr.created_at,
            s.name AS section_name,
            sj.subject_code, sj.title AS subject_title
        FROM grade_change_requests cr
        LEFT JOIN sections s ON cr.section_id = s.id
        LEFT JOIN subjects sj ON cr.subject_id = sj.id
        WHERE cr.teacher_id = ?
        ORDER BY cr.created_at DESC");
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $requests[] = $r;
        $stmt->close();
    }
    $conn->close();
}

$activeNav = 'requests';
require_once __DIR__ . '/inc/teacher_layout.php';
?>
<div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
    <div>
        <h1 class="view-title">My Requests</h1>
        <p class="view-subtitle">Track your grade change requests</p>
    </div>
    <button type="button" class="btn btn-request" onclick="openRequestModal('prelim')"><i class="fa-solid fa-plus"></i> New Request</button>
</div>

<div class="panel-block">
    <div class="table-responsive">
        <table>
            <thead>
                <tr><th>ID</th><th>Section</th><th>Subject</th><th>Period</th><th>Reason</th><th>Status</th><th>Admin Response</th><th>Submitted</th></tr>
            </thead>
            <tbody>
                <?php if (empty($requests)): ?>
                    <tr><td colspan="8" style="text-align:center;padding:24px;">No requests found.</td></tr>
                <?php else: foreach ($requests as $req): ?>
                    <tr>
                        <td><?php echo (int)$req['id']; ?></td>
                        <td><?php echo htmlspecialchars($req['section_name'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars(($req['subject_code'] ?? '') . ' - ' . ($req['subject_title'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars(ucfirst($req['grading_period'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars($req['reason'] ?? ''); ?></td>
                        <td><span class="status-badge badge-<?php echo htmlspecialchars($req['status'] ?? 'pending'); ?>"><?php echo htmlspecialchars(ucfirst($req['status'] ?? 'pending')); ?></span></td>
                        <td><?php echo htmlspecialchars($req['admin_response'] ?? '-'); ?></td>
                        <td><?php echo date('M d, Y h:i A', strtotime($req['created_at'])); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="request-modal-backdrop" class="modal-backdrop">
    <div class="modal-card">
        <button type="button" class="modal-close" onclick="closeRequestModal()"><i class="fa-solid fa-xmark"></i></button>
        <h3 style="margin-bottom:16px;">Request Permission</h3>
        <form method="post" id="request-form">
            <input type="hidden" name="request_period" id="request-period" value="">
            <div class="form-group">
                <label>Section</label>
                <select name="request_section_id" class="form-control" required>
                    <option value="">Select section</option>
                    <?php foreach ($sections as $sec): ?>
                        <option value="<?php echo (int)$sec['id']; ?>" <?php echo $activeSectionId===(int)$sec['id']?'selected':''; ?>><?php echo htmlspecialchars($sec['code'] ?? $sec['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Subject</label>

                <select name="request_subject_id" class="form-control" required>
                    <?php if (!empty($sections) && isset($sections[$activeSectionId]['subjects'])): foreach ($sections[$activeSectionId]['subjects'] as $subj): ?>
                        
                        <option value="<?php echo (int)$subj['subject_id']; ?>" <?php echo $activeSubjectId===(int)$subj['subject_id']?'selected':''; ?>><?php echo htmlspecialchars($subj['code'] . ' - ' . $subj['title']); ?></option>
                    <?php endforeach; endif; ?>

                </select>
            </div>
            <div class="form-group">
                <label>Reason for Request</label>
                <textarea name="request_reason" class="form-control" rows="4" required placeholder="Please explain why you need access..."></textarea>
            </div>
            <div style="display:flex; gap:12px; align-items:center;">
                <button type="submit" class="btn btn-submit"><i class="fa-solid fa-paper-plane"></i> Submit Request</button>
                <button type="button" class="btn-cancel" onclick="closeRequestModal()">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openRequestModal(period) {
        document.getElementById('request-period').value = period;
        document.getElementById('request-modal-backdrop').style.display = 'flex';

        // Force placeholders each time modal opens.
        // Keep teacher sections already rendered; only reset selected values.
        const sectionSelect = document.querySelector('select[name="request_section_id"]');
        const subjectSelect = document.querySelector('select[name="request_subject_id"]');

        if (sectionSelect) {
            sectionSelect.value = '';
        }

        if (subjectSelect) {
            subjectSelect.innerHTML = '<option value="">Select subject</option>';
            subjectSelect.value = '';
        }
    }



    function closeRequestModal() {
        document.getElementById('request-modal-backdrop').style.display = 'none';

        
        document.getElementById('request-form').querySelectorAll('textarea, input').forEach(function(el){ if(el.tagName && el.tagName.toLowerCase()==='textarea') el.value=''; if(el.type==='text') el.value='';});
    }


    // Sync subjects based on selected section (client-side).


    // Data is generated server-side from $sections (teacher's assigned modules only).
    const TEACHER_SECTIONS = <?php echo json_encode(array_map(function ($sec) {
        return [
            'subjects' => $sec['subjects'] ?? []
        ];
    }, $sections)); ?>;


    function updateRequestSubjects() {
        const sectionSelect = document.querySelector('select[name="request_section_id"]');
        const subjectSelect = document.querySelector('select[name="request_subject_id"]');
        if (!sectionSelect || !subjectSelect) return;

        const sectionId = parseInt(sectionSelect.value || '0', 10);
        const subjects = (TEACHER_SECTIONS && TEACHER_SECTIONS[sectionId] && TEACHER_SECTIONS[sectionId].subjects)
            ? TEACHER_SECTIONS[sectionId].subjects
            : [];

        const currentSubject = parseInt(subjectSelect.value || '0', 10);

        // Rebuild subject dropdown
        subjectSelect.innerHTML = '';

        subjects.forEach(function (s, idx) {

            const opt = document.createElement('option');
            opt.value = s.subject_id;
            opt.textContent = (s.code ? s.code : '') + (s.code ? ' - ' : '') + (s.title ? s.title : '');
            subjectSelect.appendChild(opt);
        });

        // Select first subject if current not present
        const hasCurrent = subjects.some(function (s) { return parseInt(s.subject_id, 10) === currentSubject; });
        if (subjects.length > 0 && !hasCurrent) {
            subjectSelect.value = subjects[0].subject_id;
        } else if (subjects.length === 0) {
            subjectSelect.value = '';
        }
    }

    window.addEventListener('DOMContentLoaded', function () {
        const sectionSelect = document.querySelector('select[name="request_section_id"]');
        if (sectionSelect) {
            sectionSelect.addEventListener('change', updateRequestSubjects);
            updateRequestSubjects();
        }
    });



    window.addEventListener('click', function(e) {
        if (e.target === document.getElementById('request-modal-backdrop')) {
            closeRequestModal();
        }
    });
</script>
</body>
</html>
