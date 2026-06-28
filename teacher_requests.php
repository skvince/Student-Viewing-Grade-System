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
$activeSectionId = 0;
$activeSubjectId = 0;
$sections = [];
$requests = [];
$flashPopupType = '';
$flashPopupTitle = '';
$flashPopupMessage = '';

$term = get_global_term();
$schoolYear = $term['year'];
$semester   = $term['semester'];

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

    $assignments = get_teacher_assignments($userId, $schoolYear, $semester);
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

    if (!empty($sections)) {
        $activeSectionId = (int)array_key_first($sections);
        if (!empty($sections[$activeSectionId]['subjects'])) {
            $activeSubjectId = (int)$sections[$activeSectionId]['subjects'][0]['subject_id'];
        }
    }

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) { header('Location: ' . $_SERVER['PHP_SELF'] . '?global_year=' . urlencode($schoolYear) . '&global_sem=' . urlencode($semester)); exit; }
    $reqSectionId = intval($_POST['request_section_id'] ?? 0);
    $reqSubjectId = intval($_POST['request_subject_id'] ?? 0);
    $reqPeriod = strtolower(trim($_POST['request_period'] ?? ''));
    $reqReason = trim($_POST['request_reason'] ?? '');
    $reqScope = strtolower(trim($_POST['request_scope'] ?? 'single'));

    $scopeLabel = 'Single subject';
    if ($reqScope === 'section_all') {
        $scopeLabel = 'All subjects in this section';
        if ($reqSectionId && !empty($sections[$reqSectionId])) {
            $reqReason = trim(($reqReason ? $reqReason . ' — ' : '') . 'Scope: ' . $scopeLabel . ' (' . htmlspecialchars($sections[$reqSectionId]['code'] ?? $sections[$reqSectionId]['name']) . ')');
        }
        $reqSubjectId = 0;
    } elseif ($reqScope === 'all') {
        $scopeLabel = 'All subjects across all my sections';
        $reqReason = trim(($reqReason ? $reqReason . ' — ' : '') . 'Scope: ' . $scopeLabel);
        $reqSectionId = 0;
        $reqSubjectId = 0;
    }

    if (in_array($reqPeriod, ['prelim', 'midterm', 'finals'], true) && $reqReason !== '' && ($reqScope === 'single' ? ($reqSectionId && $reqSubjectId) : true)) {
        $conn2 = db_connect();
        if ($conn2) {
            $ok = save_grade_change_request($userId, $reqSectionId, $reqSubjectId, $schoolYear, $semester, $reqPeriod, $reqReason);
            if (!$ok) { $flashPopupType = 'error'; $flashPopupTitle = 'Error'; $flashPopupMessage = 'Failed to submit request.'; }
            $conn2->close();
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $flashPopupType = 'error'; $flashPopupTitle = 'Error'; $flashPopupMessage = 'Please fill in all required fields.';
    }

    if (!$flashPopupMessage) {
        // Redirect to the same page to prevent resubmission, preserving the school year and semester parameters.
        header('Location: ' . $_SERVER['PHP_SELF'] . '?view=requests&global_year=' . urlencode($schoolYear) . '&global_sem=' . urlencode($semester) . '&success=1');
        exit;
    }
}

$activeNav = 'requests';
ob_start();
?>
<div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
    <div>
        <h1 class="view-title" style="margin-bottom:4px;">My Requests</h1>
        <p class="view-subtitle" style="margin-bottom:0;">Track your grade change requests</p>
    </div>
    <button type="button" class="btn btn-add" id="btn-new-request"><i class="fa-solid fa-plus"></i> New Request</button>
</div>

<div class="panel-block">
    <div class="block-header">
        <h2 class="block-title">Requests Registry</h2>
        <div class="header-actions">
            <div class="search-wrapper">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" class="search-input" placeholder="Search requests..." id="request-search-input" />
            </div>
        </div>
    </div>
    <div class="table-responsive">
        <table id="requests-table">
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
        <button type="button" class="modal-close" id="btn-close-request-modal"><i class="fa-solid fa-xmark"></i></button>
        <div class="request-form-header">
            <h3 id="form-title">New Grade Change Request</h3>
            <p id="form-subtitle">Fill in the details below to submit your request.</p>
        </div>
        <form method="post" id="request-form">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label for="request-period">Grading Period</label>
                <select name="request_period" id="request-period" class="form-control" required>
                    <option value="prelim">Prelim</option>
                    <option value="midterm">Midterm</option>
                    <option value="finals">Finals</option>
                </select>
            </div>
            <div class="form-group">
                <label>Request Scope</label>
                <div style="display:flex; flex-direction:column; gap:6px;">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                        <input type="radio" name="request_scope" value="single" checked style="width:auto;" /> Single subject
                    </label>
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                        <input type="radio" name="request_scope" value="section_all" style="width:auto;" /> All subjects in this section
                    </label>
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                        <input type="radio" name="request_scope" value="all" style="width:auto;" /> All subjects across all my sections
                    </label>
                </div>
            </div>
            <div class="form-group" id="section-group">
                <label for="request-section">Section</label>
                <select name="request_section_id" id="request-section" class="form-control" required>
                    <option value="">Select section...</option>
                    <?php foreach ($sections as $sec): ?>
                        <option value="<?php echo (int)$sec['id']; ?>" <?php echo $activeSectionId===(int)$sec['id']?'selected':''; ?>><?php echo htmlspecialchars($sec['code'] ?? $sec['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" id="subject-group">
                <label for="request-subject">Subject</label>
                <select name="request_subject_id" id="request-subject" class="form-control">
                    <?php if (!empty($sections) && isset($sections[$activeSectionId]['subjects'])): foreach ($sections[$activeSectionId]['subjects'] as $subj): ?>
                        <option value="<?php echo (int)$subj['subject_id']; ?>" <?php echo $activeSubjectId===(int)$subj['subject_id']?'selected':''; ?>><?php echo htmlspecialchars($subj['code'] . ' - ' . $subj['title']); ?></option>
                    <?php endforeach; endif; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="request-reason">Reason for Request</label>
                <textarea name="request_reason" id="request-reason" class="form-control" rows="4" required placeholder="Please explain why you need access..."></textarea>
            </div>
            <div class="form-buttons-row card-action-row">
                <button type="submit" class="btn btn-submit"><i class="fa-solid fa-paper-plane"></i> Submit Request</button>
                <button type="button" class="btn-cancel" id="btn-cancel-request">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
    const btnNewRequest = document.getElementById('btn-new-request');
    const requestModalBackdrop = document.getElementById('request-modal-backdrop');
    const btnCloseRequestModal = document.getElementById('btn-close-request-modal');
    const btnCancelRequest = document.getElementById('btn-cancel-request');
    const requestSearchInput = document.getElementById('request-search-input');

    function openRequestModal() {
        if (!requestModalBackdrop) return;
        requestModalBackdrop.style.display = 'flex';
        const sectionSelect = document.querySelector('select[name="request_section_id"]');
        const subjectSelect = document.querySelector('select[name="request_subject_id"]');
        if (sectionSelect) sectionSelect.value = '';
        if (subjectSelect) {
            subjectSelect.innerHTML = '<option value="">Select subject</option>';
            subjectSelect.value = '';
        }
        const scopeRadios = document.querySelectorAll('input[name="request_scope"]');
        scopeRadios.forEach(function(radio) {
            if (radio.value === 'single') radio.checked = true;
            else radio.checked = false;
        });
        applyRequestScope();
    }

    function closeRequestModal() {
        if (!requestModalBackdrop) return;
        requestModalBackdrop.style.display = 'none';
        const form = document.getElementById('request-form');
        if (form) {
            form.querySelectorAll('input, textarea').forEach(function(el) {
                if (el.tagName.toLowerCase() === 'textarea') el.value = '';
                else if (el.type === 'text') el.value = '';
            });
        }
    }

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
        subjectSelect.innerHTML = '';

        subjects.forEach(function (s, idx) {
            const opt = document.createElement('option');
            opt.value = s.subject_id;
            opt.textContent = (s.code ? s.code : '') + (s.code ? ' - ' : '') + (s.title ? s.title : '');
            subjectSelect.appendChild(opt);
        });

        const hasCurrent = subjects.some(function (s) { return parseInt(s.subject_id, 10) === currentSubject; });
        if (subjects.length > 0 && !hasCurrent) {
            subjectSelect.value = subjects[0].subject_id;
        } else if (subjects.length === 0) {
            subjectSelect.value = '';
        }
    }

    function applyRequestScope() {
        const scopeRadios = document.querySelectorAll('input[name="request_scope"]');
        const sectionGroup = document.getElementById('section-group');
        const subjectGroup = document.getElementById('subject-group');
        const sectionSelect = document.querySelector('select[name="request_section_id"]');
        const subjectSelect = document.querySelector('select[name="request_subject_id"]');
        if (!sectionGroup || !subjectGroup || !sectionSelect || !subjectSelect) return;

        let selectedScope = 'single';
        scopeRadios.forEach(function(radio) {
            if (radio.checked) selectedScope = radio.value;
        });

        if (selectedScope === 'all') {
            sectionGroup.style.display = 'none';
            sectionSelect.required = false;
            sectionSelect.value = '';
            subjectGroup.style.display = 'none';
            subjectSelect.required = false;
            subjectSelect.value = '';
        } else if (selectedScope === 'section_all') {
            sectionGroup.style.display = '';
            sectionSelect.required = true;
            subjectGroup.style.display = 'none';
            subjectSelect.required = false;
            subjectSelect.value = '';
        } else {
            sectionGroup.style.display = '';
            sectionSelect.required = true;
            subjectGroup.style.display = '';
            subjectSelect.required = true;
        }
    }

    function filterRequests() {
        if (!requestSearchInput) return;
        const query = requestSearchInput.value.toLowerCase();
        const tableBody = document.querySelector('#requests-table tbody');
        if (!tableBody) return;
        const rows = tableBody.querySelectorAll('tr');
        let visibleCount = 0;
        rows.forEach(function(row) {
            const text = row.textContent.toLowerCase();
            if (text.includes(query)) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });
    }

    if (btnNewRequest) btnNewRequest.addEventListener('click', openRequestModal);
    if (btnCloseRequestModal) btnCloseRequestModal.addEventListener('click', closeRequestModal);
    if (btnCancelRequest) btnCancelRequest.addEventListener('click', closeRequestModal);
    if (requestSearchInput) requestSearchInput.addEventListener('input', filterRequests);

    const sectionSelect = document.querySelector('select[name="request_section_id"]');
    if (sectionSelect) sectionSelect.addEventListener('change', updateRequestSubjects);

    const scopeRadios = document.querySelectorAll('input[name="request_scope"]');
    scopeRadios.forEach(function(radio) {
        radio.addEventListener('change', applyRequestScope);
    });
</script>

<?php
$requestsContent = ob_get_clean();
$activeNav = 'requests';
require_once __DIR__ . '/inc/teacher_layout.php';
?>

<script>
  document.addEventListener('DOMContentLoaded', function() {
    <?php if ($flashPopupMessage): ?>
      showPopup('<?php echo $flashPopupType; ?>', '<?php echo addslashes($flashPopupTitle); ?>', '<?php echo addslashes($flashPopupMessage); ?>');
    <?php endif; ?>
    <?php if (isset($_GET['success'])): ?>
      showPopup('success', 'Success', 'Your request was submitted successfully.');
    <?php endif; ?>
  });
</script>