<?php
require_once __DIR__ . '/inc/functions.php';
$pageTitle = 'View Grade';
$activeNav = 'grade_override';
$content = '';
ob_start();
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

$term = get_global_term();
$selectedYear = $term['year'];
$selectedSem  = $term['semester'];

$departments = get_departments();

$filterYear   = $_GET['filter_year']   ?? $selectedYear;
$filterSem    = $_GET['filter_sem']    ?? $selectedSem;
$filterDept   = $_GET['filter_dept']   ?? '';
$searchQuery  = trim($_GET['search']     ?? '');
$selectedStudentId = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) { header('Location: ' . $_SERVER['PHP_SELF']); exit; }
    $postStudentId = intval($_POST['student_id'] ?? 0);
    $postSubjectId = intval($_POST['subject_id'] ?? 0);
    $postYear      = trim($_POST['school_year'] ?? '');
    $postSem       = trim($_POST['semester'] ?? '');
    $period        = trim($_POST['grading_period'] ?? '');
    $newGrade      = trim($_POST['new_grade'] ?? '');
    $confirmAction = trim($_POST['confirm_action'] ?? '');

    if ($confirmAction !== 'YES') {
        $message = 'Confirmation required.';
        $messageType = 'error';
    } elseif (!$postStudentId || !$postSubjectId || !$postYear || !$postSem || !$period || $newGrade === '') {
        $message = 'All grade fields are required.';
        $messageType = 'error';
    } elseif (!in_array($period, ['prelim','midterm','finals'], true)) {
        $message = 'Invalid grading period.';
        $messageType = 'error';
    } elseif (!is_numeric($newGrade) || $newGrade < 0 || $newGrade > 100) {
        $message = 'Grade must be a number between 0 and 100.';
        $messageType = 'error';
    } else {
        $conn = db_connect();
        if ($conn) {
            $gradeId = null;
            $oldGrade = null;
            $gStmt = $conn->prepare("SELECT id, prelim, midterm, finals FROM grades WHERE student_id = ? AND subject_id = ? AND school_year = ? AND semester = ? LIMIT 1");
            if ($gStmt) {
                $gStmt->bind_param('iiss', $postStudentId, $postSubjectId, $postYear, $postSem);
                $gStmt->execute();
                $gRes = $gStmt->get_result();
                if ($gRow = $gRes->fetch_assoc()) {
                    $gradeId = (int)$gRow['id'];
                    $oldGrade = $gRow[$period];
                }
                $gStmt->close();
            }

            if (!$gradeId) {
                $ins = $conn->prepare("INSERT INTO grades (student_id, subject_id, teacher_id, section_id, school_year, semester, prelim, midterm, finals) VALUES (?, ?, 0, 0, ?, ?, COALESCE(NULLIF(?,''),NULL), COALESCE(NULLIF(?,''),NULL), COALESCE(NULLIF(?,''),NULL))");
                if ($ins) {
                    $p1 = $period === 'prelim' ? $newGrade : null;
                    $p2 = $period === 'midterm' ? $newGrade : null;
                    $p3 = $period === 'finals' ? $newGrade : null;
                    $ins->bind_param('iisssss', $postStudentId, $postSubjectId, $postYear, $postSem, $p1, $p2, $p3);
                    $ins->execute();
                    $gradeId = $conn->insert_id;
                    $ins->close();
                }
            } else {
                $upd = $conn->prepare("UPDATE grades SET $period = ?, updated_at = NOW() WHERE id = ? LIMIT 1");
                if ($upd) {
                    $upd->bind_param('di', $newGrade, $gradeId);
                    $upd->execute();
                    $upd->close();
                }
            }

            if ($gradeId) {
                $studentName = '';
                $sStmt = $conn->prepare("SELECT CONCAT(first_name,' ',middle_name,' ',last_name) AS fullname FROM students WHERE id = ? LIMIT 1");
                if ($sStmt) {
                    $sStmt->bind_param('i', $postStudentId);
                    $sStmt->execute();
                    $sRes = $sStmt->get_result();
                    if ($sRow = $sRes->fetch_assoc()) $studentName = $sRow['fullname'];
                    $sStmt->close();
                }
                $subjectTitle = '';
                $sjStmt = $conn->prepare("SELECT title FROM subjects WHERE id = ? LIMIT 1");
                if ($sjStmt) {
                    $sjStmt->bind_param('i', $postSubjectId);
                    $sjStmt->execute();
                    $sjRes = $sjStmt->get_result();
                    if ($sjRow = $sjRes->fetch_assoc()) $subjectTitle = $sjRow['title'];
                    $sjStmt->close();
                }
                audit_log('admin_grade_override', $_SESSION['user_id'], $period, $postYear, $postSem, $gradeId, null, $postSubjectId, null, null, null, null, $oldGrade, $newGrade, $studentName, $subjectTitle);
                $message = 'Grade updated successfully.';
                $messageType = 'success';
            } else {
                $message = 'Failed to save grade.';
                $messageType = 'error';
            }
            $conn->close();
        }
    }
    if ($postStudentId && !isset($_POST['ajax'])) {
        header('Location: ' . $_SERVER['PHP_SELF'] . '?student_id=' . $postStudentId . '&filter_year=' . urlencode($filterYear) . '&filter_sem=' . urlencode($filterSem) . '&filter_dept=' . urlencode($filterDept) . '&search=' . urlencode($searchQuery));
        exit;
    }
    if (isset($_POST['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => ($messageType === 'success'), 'message' => $message]);
        exit;
    }
}

if ($selectedStudentId) {
    audit_log('admin_view_student_grades', $_SESSION['user_id'], null, $filterYear, $filterSem, null, null, null, null, null, null, null, null, null, null, null, (string)$selectedStudentId);
}

$students = [];
$conn = db_connect();
if ($conn) {
    $sql = "SELECT s.id, s.student_id, s.first_name, s.middle_name, s.last_name, s.department, sec.name AS section_name FROM students s LEFT JOIN sections sec ON s.section_id = sec.id WHERE 1=1";
    $params = '';
    $vals = [];
    if ($filterYear) {
        $sql .= " AND (s.id IN (SELECT student_id FROM grades WHERE school_year = ?) OR s.id NOT IN (SELECT student_id FROM grades WHERE school_year = ?))";
        $params .= 'ss';
        $vals[] = $filterYear;
        $vals[] = $filterYear;
    }
    if ($filterSem) {
        $sql .= " AND (s.id IN (SELECT student_id FROM grades WHERE semester = ?) OR s.id NOT IN (SELECT student_id FROM grades WHERE semester = ?))";
        $params .= 'ss';
        $vals[] = $filterSem;
        $vals[] = $filterSem;
    }
    if ($filterDept) {
        $sql .= " AND s.department = ?";
        $params .= 's';
        $vals[] = $filterDept;
    }
    if ($searchQuery) {
        $sql .= " AND (CONCAT(s.first_name,' ',s.middle_name,' ',s.last_name) LIKE ? OR s.student_id LIKE ? OR s.last_name LIKE ?)";
        $params .= 'sss';
        $like = '%' . $searchQuery . '%';
        $vals[] = $like;
        $vals[] = $like;
        $vals[] = $like;
    }
    $sql .= " ORDER BY s.last_name ASC, s.first_name ASC LIMIT 200";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if ($params) {
            $stmt->bind_param($params, ...$vals);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) $students[] = $row;
        $stmt->close();
    }
    $conn->close();
}

$selectedStudent = null;
$gradeRows = [];
if ($selectedStudentId) {
    $conn = db_connect();
    if ($conn) {
        $sStmt = $conn->prepare("SELECT s.id, s.student_id, s.first_name, s.middle_name, s.last_name, s.department, sec.name AS section_name, sec.section_code FROM students s LEFT JOIN sections sec ON s.section_id = sec.id WHERE s.id = ? LIMIT 1");
        if ($sStmt) {
            $sStmt->bind_param('i', $selectedStudentId);
            $sStmt->execute();
            $sRes = $sStmt->get_result();
            if ($sRow = $sRes->fetch_assoc()) $selectedStudent = $sRow;
            $sStmt->close();
        }
        if ($selectedStudent) {
            $gStmt = $conn->prepare("SELECT g.id, g.subject_id, g.prelim, g.midterm, g.finals, g.average, g.gwa, g.remarks, sj.subject_code, sj.title, sj.units FROM grades g LEFT JOIN subjects sj ON g.subject_id = sj.id WHERE g.student_id = ? AND g.school_year = ? AND g.semester = ? ORDER BY sj.subject_code ASC");
            if ($gStmt) {
                $gStmt->bind_param('iss', $selectedStudentId, $filterYear, $filterSem);
                $gStmt->execute();
                $gRes = $gStmt->get_result();
                while ($gRow = $gRes->fetch_assoc()) $gradeRows[] = $gRow;
                $gStmt->close();
            }
        }
        $conn->close();
    }
}
?>
      <div class="panel-block">
        <div class="block-header">
          <h2 class="block-title">Find Student</h2>
          <div class="header-actions">
            <span style="font-size:0.85rem;color:var(--text-muted);">Total: <strong><?= count($students) ?></strong></span>
          </div>
        </div>
        <form method="get" class="filter-form">
          <div class="filter-group" style="flex-wrap:wrap;gap:12px;justify-content:space-between;">
            <div class="form-group" style="margin:0;flex:0.8;min-width:120px;">
              <label for="filter-year">School Year</label>
              <select id="filter-year" name="filter_year" class="global-select" onchange="this.form.submit()">
                <?php foreach (['2025-2026','2026-2027'] as $y): ?>
                  <option value="<?= htmlspecialchars($y) ?>" <?= $filterYear===$y?'selected':'' ?>><?= htmlspecialchars($y) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group" style="margin:0;flex:0.8;min-width:110px;">
              <label for="filter-sem">Semester</label>
              <select id="filter-sem" name="filter_sem" class="global-select" onchange="this.form.submit()">
                <?php foreach (['1st Semester','2nd Semester','Summer'] as $s): ?>
                  <option value="<?= htmlspecialchars($s) ?>" <?= $filterSem===$s?'selected':'' ?>><?= htmlspecialchars($s) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group" style="margin:0;flex:1;min-width:120px;">
              <label for="filter-dept">Department</label>
              <select id="filter-dept" name="filter_dept" class="global-select" onchange="this.form.submit()">
                <option value="">All Departments</option>
                <?php foreach ($departments as $d): ?>
                  <option value="<?= htmlspecialchars($d['name']) ?>" <?= $filterDept===$d['name']?'selected':'' ?>><?= htmlspecialchars($d['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group" style="margin:0px;min-width:200px;">
              <label for="search-input">Search Student</label>
              <div class="search-wrapper">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="search-input" name="search" class="search-input" placeholder="ID or Name..." value="<?= htmlspecialchars($searchQuery) ?>" />
              </div>
            </div>
          </div>
        </form>
        <div class="table-responsive">
          <table id="students-grade-table">
            <thead>
              <tr>
                <th>Student ID</th>
                <th>Name</th>
                <th>Department</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($students as $s): ?>
              <tr>
                <td><?= htmlspecialchars($s['student_id'] ?? ('S-'.sprintf('%03d',$s['id']))) ?></td>
                <td><?= htmlspecialchars($s['first_name'].' '.($s['middle_name']?($s['middle_name'].' '):'').$s['last_name']) ?></td>
                <td><?= htmlspecialchars($s['department'] ?? 'N/A') ?></td>
                <td>
                  <a href="?student_id=<?= (int)$s['id'] ?>&filter_year=<?= urlencode($filterYear) ?>&filter_sem=<?= urlencode($filterSem) ?>&filter_dept=<?= urlencode($filterDept) ?>&search=<?= urlencode($searchQuery) ?>" class="btn-action" style="text-decoration:none;">
                    <i class="fa-solid fa-eye"></i> View
                  </a>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php if (empty($students)): ?>
                <tr><td colspan="4" style="text-align:center;color:#6b7280;padding:18px;">No students found matching the current filters.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <?php if ($selectedStudent): ?>
      <div class="panel-block">
          <div class="block-header">
            <h2 class="block-title">Student Information & Grade Record — <?= htmlspecialchars($filterYear) ?> / <?= htmlspecialchars($filterSem) ?></h2>
            <div class="header-actions" style="display:flex;gap:10px;align-items:center;">
              <button type="button" class="btn-action" style="cursor:pointer;" onclick="printStudentGrade()">
                <i class="fa-solid fa-print"></i> Print
              </button>
            </div>
          </div>

        <div class="grade-record-card">
          <div class="grade-record-header">
            <div class="grade-record-info">
              <h3 class="grade-record-name"><?= htmlspecialchars($selectedStudent['first_name'].' '.($selectedStudent['middle_name']?($selectedStudent['middle_name'].' '):'').$selectedStudent['last_name']) ?></h3>
              <p class="grade-record-id"><?= htmlspecialchars($selectedStudent['student_id'] ?? ('S-'.sprintf('%03d',$selectedStudent['id']))) ?></p>
              <p class="grade-record-dept"><?= htmlspecialchars($selectedStudent['department'] ?? 'N/A') ?></p>
            </div>
            <div class="grade-record-badge">
              <span class="badge" style="background:var(--light-green-bg);color:var(--primary-green);"><?= htmlspecialchars($filterYear) ?> / <?= htmlspecialchars($filterSem) ?></span>
            </div>
          </div>

          <?php if (empty($gradeRows)): ?>
            <p class="grade-empty">No grade records found for this student in the selected term.</p>
          <?php else: ?>
          <div class="grade-table-wrap">
            <table class="grade-override-table">
              <thead>
                <tr>
                  <th>Subject Code</th>
                  <th>Title</th>
                  <th>Units</th>
                  <th>Prelim</th>
                  <th>Midterm</th>
                  <th>Finals</th>
                  <th>Average %</th>
                  <th>GWA</th>
                  <th>Remarks</th>
                  <th>Update</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($gradeRows as $g): ?>
                <tr>
                  <td class="grade-subject-code"><?= htmlspecialchars($g['subject_code'] ?? '') ?></td>
                  <td class="grade-subject-title"><?= htmlspecialchars($g['title'] ?? '') ?></td>
                  <td class="grade-units"><?= (int)($g['units'] ?? 3) ?></td>
                  <td><input type="number" class="grade-input override-input" data-grade-id="<?= (int)$g['id'] ?>" data-period="prelim" min="0" max="100" step="0.01" value="<?= htmlspecialchars($g['prelim'] ?? '') ?>" /></td>
                  <td><input type="number" class="grade-input override-input" data-grade-id="<?= (int)$g['id'] ?>" data-period="midterm" min="0" max="100" step="0.01" value="<?= htmlspecialchars($g['midterm'] ?? '') ?>" /></td>
                  <td><input type="number" class="grade-input override-input" data-grade-id="<?= (int)$g['id'] ?>" data-period="finals" min="0" max="100" step="0.01" value="<?= htmlspecialchars($g['finals'] ?? '') ?>" /></td>
                  <td class="grade-avg"><?= htmlspecialchars($g['average'] ?? '--') ?></td>
                  <td class="grade-gwa"><?= htmlspecialchars($g['gwa'] ?? '--') ?></td>
                  <td><span class="status-badge badge-<?= htmlspecialchars($g['remarks'] ?? 'pending') ?>"><?= htmlspecialchars($g['remarks'] ?? 'Pending') ?></span></td>
                  <td>
                    <button type="button" class="btn-save-override" data-grade-id="<?= (int)$g['id'] ?>" data-subject-id="<?= (int)$g['subject_id'] ?>">
                      <i class="fa-solid fa-floppy-disk"></i>
                    </button>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php else: ?>
      <div class="panel-block">
        <p style="color:var(--text-muted);padding:18px 0;">Select a student to view their grade record.</p>
      </div>
      <?php endif; ?>

  <script>
    document.addEventListener('DOMContentLoaded', function() {

      const gradeInputs = document.querySelectorAll('.override-input');
      gradeInputs.forEach(function(input) {
        input.addEventListener('change', function() {
          const val = parseFloat(this.value);
          if (isNaN(val) || val < 0 || val > 100) {
            alert('Grade must be between 0 and 100.');
            this.value = '';
          }
        });
      });

      const searchInput = document.getElementById('search-input');
      const studentTable = document.getElementById('students-grade-table');
      if (searchInput && studentTable) {
        searchInput.addEventListener('input', function() {
          const q = this.value.toLowerCase();
          const rows = studentTable.querySelectorAll('tbody tr');
          let visibleCount = 0;
          rows.forEach(function(row) {
            const text = row.textContent.toLowerCase();
            if (text.includes(q)) {
              row.style.display = '';
              visibleCount++;
            } else {
              row.style.display = 'none';
            }
          });
          const countEl = studentTable.closest('.panel-block').querySelector('strong');
          if (countEl) countEl.textContent = visibleCount;
        });
      }

      function autoSaveGrade(row) {
        const inputs = row.querySelectorAll('.override-input');
        const periods = {};
        const gradeId = inputs[0] ? inputs[0].getAttribute('data-grade-id') : null;
        const subjectId = inputs[0] ? inputs[0].closest('tr').querySelector('.btn-save-override').getAttribute('data-subject-id') : null;
        if (!gradeId || !subjectId) return;
        inputs.forEach(function(inp) {
          const p = inp.getAttribute('data-period');
          const v = inp.value.trim();
          if (v !== '') periods[p] = v;
        });
        const keys = Object.keys(periods);
        if (keys.length === 0) return;
        const saveBtn = row.querySelector('.btn-save-override');
        if (saveBtn) {
          saveBtn.disabled = true;
          saveBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
        }
        const formData = new FormData();
        formData.append('csrf_token', '<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES); ?>');
        formData.append('ajax', '1');
        formData.append('student_id', '<?= (int)$selectedStudentId ?>');
        formData.append('subject_id', subjectId);
        formData.append('school_year', '<?= htmlspecialchars($filterYear) ?>');
        formData.append('semester', '<?= htmlspecialchars($filterSem) ?>');
        formData.append('confirm_action', 'YES');
        keys.forEach(function(p) {
          formData.append('grading_period', p);
          formData.append('new_grade', periods[p]);
        });
        fetch('<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>', {
          method: 'POST',
          body: formData,
          credentials: 'same-origin'
        })
        .then(function(res) { return res.json(); })
        .then(function() {
          if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i>';
          }
          const avgCell = row.querySelector('.grade-avg');
          const gwaCell = row.querySelector('.grade-gwa');
          if (avgCell && periods.prelim !== undefined && periods.midterm !== undefined && periods.finals !== undefined) {
            const p = parseFloat(periods.prelim);
            const m = parseFloat(periods.midterm);
            const f = parseFloat(periods.finals);
            if (!isNaN(p) && !isNaN(m) && !isNaN(f)) {
              const avg = ((p + m + f) / 3).toFixed(2);
              avgCell.textContent = avg + '%';
            }
          }
        })
        .catch(function() {
          if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i>';
          }
        });
      }

      document.querySelectorAll('.override-input').forEach(function(input) {
        input.addEventListener('blur', function() {
          const val = parseFloat(this.value);
          if (isNaN(val) || val < 0 || val > 100) {
            alert('Grade must be between 0 and 100.');
            this.value = '';
            return;
          }
          const row = this.closest('tr');
          if (row) autoSaveGrade(row);
        });
        input.addEventListener('keydown', function(e) {
          if (e.key === 'Enter') {
            e.preventDefault();
            this.blur();
          }
        });
      });

      document.querySelectorAll('.btn-save-override').forEach(function(btn) {
        btn.addEventListener('click', function() {
          const row = this.closest('tr');
          if (row) autoSaveGrade(row);
        });
      });

      <?php if ($selectedStudent): ?>
      window.addEventListener('load', function() {
        const gradeRecord = document.getElementById('grade-override-table');
        if (gradeRecord) {
          setTimeout(function() {
            gradeRecord.scrollIntoView({ behavior: 'smooth', block: 'start' });
          }, 300);
        }
      });
      <?php endif; ?>
    });
  </script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/inc/app_layout.php';
?>


<style media="print">
  body { padding: 0 !important; background: #fff !important; }

  .sidebar, .mobile-header, .global-term-container, .filter-form, .header-actions, .btn-action, .grade-input, .btn-save-override, .panel-block { display: none !important; }
  .main-content { padding: 0 !important; margin: 0 !important; }
  .panel-block { display: block !important; border: none !important; box-shadow: none !important; padding: 0 !important; margin: 0 !important; }
  table { width: 100% !important; border-collapse: collapse !important; }
  th, td { border: 1px solid #333 !important; padding: 6px 8px !important; font-size: 11pt !important; }
  .view-title { font-size: 14pt !important; margin-bottom: 4px !important; }
  .view-subtitle { font-size: 11pt !important; margin-bottom: 12px !important; }
  .summary-highlights-strip { display: block !important; border: 1px solid #333 !important; padding: 8px !important; margin-bottom: 16px !important; }
  .metric-item-box { display: inline-block !important; width: 48% !important; margin-right: 2% !important; }
  .avg-cell, .gwa-cell { font-weight: bold !important; }
</style>

<script>
  function printStudentGrade() {
    const gradeCard = document.querySelector('.panel-block .grade-record-card');
    if (!gradeCard) {
      alert('No grade record to print.');
      return;
    }

    const title = 'Student Grade - <?= addslashes($selectedStudent ? ($selectedStudent['student_id'] ?? $selectedStudent['id']) : '') ?>';
    const printWindow = window.open('', '_blank', 'width=900,height=700');
    if (!printWindow) {
      alert('Popup blocked. Allow popups to print.');
      return;
    }

    const html = `<!doctype html>
<html>
<head>
  <meta charset="utf-8" />
  <title>${title}</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: Arial, Helvetica, sans-serif; padding: 24px; color: #333; }
    table { width: 100%; border-collapse: collapse; margin-top: 16px; }
    th, td { border: 1px solid #333; padding: 8px 10px; font-size: 11pt; text-align: left; }
    th { background: #f5f5f5; font-weight: 600; }
    .grade-record-card { border: 1px solid #ddd; padding: 20px; }
    .grade-record-header { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 2px solid #0e4429; }
    .grade-record-info h3 { margin: 0 0 6px; font-size: 1.1rem; color: #0e4429; }
    .grade-record-info p { margin: 3px 0; font-size: 0.9rem; color: #555; }
    .badge { display: inline-block; padding: 5px 12px; border-radius: 999px; background: #d8ebd4; color: #0e4429; border: 1px solid #b9deb3; font-size: 0.85rem; font-weight: 600; }
    .grade-table-wrap { overflow-x: auto; }
    input, button, .sidebar, .mobile-header, .global-term-container, .filter-form, .header-actions, .btn-action, .grade-input, .btn-save-override, .panel-block { display: none !important; }
  </style>
</head>
<body>
  <div id="print-area">${gradeCard.outerHTML}</div>
  <script>
    document.querySelectorAll('#print-area input').forEach(inp => {
      const text = document.createElement('span');
      text.textContent = inp.value ?? '';
      inp.parentNode.replaceChild(text, inp);
    });
  <\/script>
</body>
</html>`;

    printWindow.document.open();
    printWindow.document.write(html);
    printWindow.document.close();
    setTimeout(function() { printWindow.print(); }, 150);
  }
</script>

