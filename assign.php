<?php
require_once __DIR__ . '/inc/functions.php';
$pageTitle = 'Assign Module';
$activeNav = 'assign';
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

$teachers     = [];
$sections     = [];
$subjects     = [];
$assignments  = [];
$saveError    = '';
$subjectError = $_SESSION['subject_error'] ?? '';
unset($_SESSION['subject_error']);
$editSubjectId = isset($_GET['edit_subject']) ? intval($_GET['edit_subject']) : 0;
$gradedSubjectIds = [];

/* ═══════════════════════════════════════════════════════════
   HANDLER 1 — DELETE ASSIGNMENT
   ═══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_assignment'])) {
    if (!verify_csrf()) { header('Location: ' . $_SERVER['PHP_SELF']); exit; }
    $assignmentId = intval($_POST['assignment_id'] ?? 0);
    if ($assignmentId) {
        // deletion is term-scoped by the record itself; no extra term filter needed here
        
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
    if (!verify_csrf()) { header('Location: ' . $_SERVER['PHP_SELF']); exit; }
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
   Blocked if subject is referenced by assignments or grades (FK safety).
   ═══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_subject'])) {
    if (!verify_csrf()) { header('Location: ' . $_SERVER['PHP_SELF']); exit; }
    $subjectId = intval($_POST['subject_id'] ?? 0);
    if ($subjectId) {
        if (delete_subject($subjectId)) {
            audit_log('delete_subject');
        } else {
            $_SESSION['subject_error'] = 'Cannot delete: subject is already used in assignments or grades.';
        }
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

/* ═══════════════════════════════════════════════════════════
   HANDLER 4 — SAVE SUBJECT (CREATE)
   ═══════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_subject']) && !isset($_POST['update_subject'])) {
    if (!verify_csrf()) { header('Location: ' . $_SERVER['PHP_SELF']); exit; }
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
    if (!verify_csrf()) { header('Location: ' . $_SERVER['PHP_SELF']); exit; }
    $teacherId  = intval($_POST['teacher_id']  ?? 0);
    $sectionId  = intval($_POST['section_id']  ?? 0);
    $subjectId  = intval($_POST['subject_id']  ?? 0);   // ← was missing
    $schoolYear = trim($_POST['school_year']   ?? '');
    $semester   = trim($_POST['semester']      ?? '');

    // Normalize to match teacher_module.php filtering logic.
    $schoolYear = normalize_school_year($schoolYear);
    $semester   = normalize_semester($semester);

    if (!$teacherId || !$sectionId || !$subjectId) {

        $saveError = 'Teacher, Section, and Subject Module are required.';
    } else {
        $conn = db_connect();
        if ($conn) {
            $moduleLabel = '';
            $subRow = $conn->prepare("SELECT subject_code, title FROM subjects WHERE id = ? LIMIT 1");
            if ($subRow) {
                $subRow->bind_param('i', $subjectId);
                $subRow->execute();
                $r = $subRow->get_result()->fetch_assoc();
                $subRow->close();
                if ($r) $moduleLabel = $r['subject_code'] . ' - ' . $r['title'];
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
    if (!verify_csrf()) { header('Location: ' . $_SERVER['PHP_SELF']); exit; }
    $assignmentId = intval($_POST['assignment_id'] ?? 0);
    $teacherId    = intval($_POST['teacher_id']    ?? 0);
    $sectionId    = intval($_POST['section_id']    ?? 0);
    $subjectId    = intval($_POST['subject_id']    ?? 0);
    $schoolYear   = trim($_POST['school_year']    ?? '');
    $semester     = trim($_POST['semester']        ?? '');

    // Normalize to match teacher_module.php filtering logic.
    $schoolYear = normalize_school_year($schoolYear);
    $semester   = normalize_semester($semester);


    if (!$assignmentId || !$teacherId || !$sectionId || !$subjectId) {
        $saveError = 'Teacher, Section, and Subject Module are required.';
    } else {
        $conn = db_connect();
        if ($conn) {
            $moduleLabel = '';
            $subRow = $conn->prepare("SELECT subject_code, title FROM subjects WHERE id = ? LIMIT 1");
            if ($subRow) {
                $subRow->bind_param('i', $subjectId);
                $subRow->execute();
                $r = $subRow->get_result()->fetch_assoc();
                $subRow->close();
                if ($r) $moduleLabel = $r['subject_code'] . ' - ' . $r['title'];
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
    $res = $conn->query("SELECT id, teacher_id, first_name, middle_name, last_name FROM teachers ORDER BY last_name ASC, first_name ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $row['name'] = trim($row['first_name'] . ' ' . ($row['middle_name'] ? $row['middle_name'] . ' ' : '') . $row['last_name']);
            $teachers[] = $row;
        }
        $res->free();
    }

    // Sections — filtered by global term
    $stmt = $conn->prepare("SELECT id, name AS section_code, name FROM sections WHERE school_year = ? AND semester = ? ORDER BY created_at DESC");
    if ($stmt) {
        $stmt->bind_param('ss', $selectedYear, $selectedSem);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
            while ($row = $res->fetch_assoc()) $sections[] = $row;
            $res->free();
        }
        $stmt->close();
    }

    // Subjects — loaded from DB, filtered by global term, not from JS DOM
    $stmt = $conn->prepare(
        "SELECT id, subject_code, title, units, school_year, semester FROM subjects WHERE school_year = ? AND semester = ? ORDER BY subject_code ASC"
    );
    if ($stmt) {
        $stmt->bind_param('ss', $selectedYear, $selectedSem);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
            while ($row = $res->fetch_assoc()) $subjects[] = $row;
            $res->free();
        }
        $stmt->close();
    }

    // Assignments — JOIN to get live subject info; filtered by global term
    // FIX: teachers table has no `name` column — build it from
    // first_name/middle_name/last_name via CONCAT, same as elsewhere.
    $stmt = $conn->prepare(
        "SELECT
             a.id           AS assignment_id,
             a.subject_id,
             a.school_year,
             a.semester,
             TRIM(CONCAT(t.first_name, ' ', IF(t.middle_name <> '', CONCAT(t.middle_name, ' '), ''), t.last_name)) AS teacher_name,
             s.name AS section_name,
             sj.subject_code,
             sj.title       AS subject_title
         FROM assignments a
         LEFT JOIN teachers  t  ON a.teacher_id  = t.id
         LEFT JOIN sections  s  ON a.section_id  = s.id
         LEFT JOIN subjects  sj ON a.subject_id  = sj.id
         WHERE a.school_year = ? AND a.semester = ?
         ORDER BY a.created_at DESC"
    );
    if ($stmt) {
        $stmt->bind_param('ss', $selectedYear, $selectedSem);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
            while ($row = $res->fetch_assoc()) $assignments[] = $row;
            $res->free();
        }
        $stmt->close();
    }

    $gradeRes = $conn->query("SELECT DISTINCT subject_id FROM grades");
    if ($gradeRes) {
        while ($row = $gradeRes->fetch_assoc()) $gradedSubjectIds[] = intval($row['subject_id']);
        $gradeRes->free();
    }

    $editSubject = null;
    if (isset($_GET['edit_subject']) ? intval($_GET['edit_subject']) : 0) {
        $q = $conn->prepare("SELECT id, subject_code, title, units, school_year, semester FROM subjects WHERE id = ? LIMIT 1");
        if ($q) {
            $editSubjectId = intval($_GET['edit_subject']);
            $q->bind_param('i', $editSubjectId);
            $q->execute();
            $editSubject = $q->get_result()->fetch_assoc();
            $q->close();
        }
    }

    $conn->close();
}

$assignedSubjectIds = array_unique(
    array_column(array_filter($assignments ?? [], fn($a) => !empty($a['subject_id'])), 'subject_id')
);
$gradedSubjectIds = array_unique($gradedSubjectIds);
?>

    <div class="tab-content" style="display: block;">
      <h1 class="view-title">Assign Module</h1>
      <p class="view-subtitle">Manage structural modules and assign entities</p>

      <div class="panel-block" id="subject-block-container">
        <div class="block-header">
          <h2 class="block-title">Create / Manage Subjects</h2>
        </div>

        <?php if ($subjectError): ?><div class="alert-error"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($subjectError) ?></div><?php endif; ?>

        <form method="post" action="">
        <?php echo csrf_field(); ?>
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
              <option value="2025-2026" <?= ($editSubject['school_year'] ?? $selectedYear) === '2025-2026' ? 'selected' : '' ?>>S.Y. 2025-2026</option>
              <option value="2026-2027" <?= ($editSubject['school_year'] ?? $selectedYear) === '2026-2027' ? 'selected' : '' ?>>S.Y. 2026-2027</option>
            </select>
          </div>
          <div class="form-group">
            <label for="sub-sem-select">Semester</label>
            <select id="sub-sem-select" name="sub_semester" class="form-control" required>
              <option value="" disabled <?= $editSubject ? '' : 'selected' ?>>Select semester...</option>
              <option value="1st Semester" <?= ($editSubject['semester'] ?? $selectedSem) === '1st Semester' ? 'selected' : '' ?>>1st Semester</option>
              <option value="2nd Semester" <?= ($editSubject['semester'] ?? $selectedSem) === '2nd Semester' ? 'selected' : '' ?>>2nd Semester</option>
              <option value="Summer" <?= ($editSubject['semester'] ?? $selectedSem) === 'Summer' ? 'selected' : '' ?>>Summer</option>
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
      <div style="display:flex;justify-content:flex-end;margin-bottom:10px;">
        <div class="search-wrapper">
          <i class="fa-solid fa-magnifying-glass"></i>
          <input type="text" class="search-input" placeholder="Search subjects..." />
        </div>
      </div>
      <div class="table-responsive">
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
                  $subjectId = (int)$subject['id'];
                  // FIX: lock check uses integer ID comparison, not string matching
                  $isUsed = in_array($subjectId, $assignedSubjectIds, true) || in_array($subjectId, $gradedSubjectIds, true);
                ?>
                <tr>
                  <td><?= htmlspecialchars($subject['subject_code']) ?></td>
                  <td><?= htmlspecialchars($subject['title']) ?></td>
                  <td><?= htmlspecialchars($subject['units'] ?? 3) ?></td>
                  <td><?= htmlspecialchars($subject['school_year'] ?? '') ?></td>
                  <td><?= htmlspecialchars($subject['semester'] ?? '') ?></td>
                  <td class="actions-cell">
                    <button type="button" class="icon-button btn-edit-subject"
                            data-id="<?= intval($subject['id']) ?>"
                            data-code="<?= htmlspecialchars($subject['subject_code'] ?? '', ENT_QUOTES) ?>"
                            data-title="<?= htmlspecialchars($subject['title'] ?? '', ENT_QUOTES) ?>"
                            data-units="<?= htmlspecialchars($subject['units'] ?? 3, ENT_QUOTES) ?>"
                            data-year="<?= htmlspecialchars($subject['school_year'] ?? '', ENT_QUOTES) ?>"
                            data-semester="<?= htmlspecialchars($subject['semester'] ?? '', ENT_QUOTES) ?>"
                            title="Update subject">
                      <i class="fa-solid fa-pen-to-square" style="color:#059669;"></i>
                    </button>
                      <form method="post" class="delete-form" data-confirm="Delete this subject and its related assignment/grade records?">
                      <?php echo csrf_field(); ?>
                      <input type="hidden" name="delete_subject" value="1">
                      <input type="hidden" name="subject_id" value="<?= intval($subject['id']) ?>">
                      <button type="submit" class="icon-button" title="Delete subject">
                        <i class="fa-solid fa-trash-can"></i>
                      </button>
                    </form>
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
        <?php echo csrf_field(); ?>
        <fieldset style="border:none;">
          <div class="grid-2col">
            <div class="form-group">
              <label for="select-teacher">Teacher</label>
              <select id="select-teacher" name="teacher_id" class="form-control" required>
                <option value="" disabled selected hidden>Select teacher...</option>
                <?php foreach ($teachers as $t): ?>
                  <option value="<?= intval($t['id']) ?>">
                    <?= htmlspecialchars($t['name']) ?>
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
                    <?= htmlspecialchars($sec['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="form-group">
              <label for="select-school-year">School Year</label>
              <select id="select-school-year" name="school_year" class="form-control" required>
                <option value="" disabled <?= $selectedYear === '' ? 'selected' : '' ?>>Select school year...</option>
                <option value="2025-2026" <?= $selectedYear === '2025-2026' ? 'selected' : '' ?>>S.Y. 2025-2026</option>
                <option value="2026-2027" <?= $selectedYear === '2026-2027' ? 'selected' : '' ?>>S.Y. 2026-2027</option>
              </select>
            </div>
          </div>

          <div class="form-group">
            <label for="select-semester">Semester</label>
              <select id="select-semester" name="semester" class="form-control" required>
                <option value="" disabled <?= $selectedSem === '' ? 'selected' : '' ?>>Select semester...</option>
                <option value="1st Semester" <?= $selectedSem === '1st Semester' ? 'selected' : '' ?>>1st Semester</option>
                <option value="2nd Semester" <?= $selectedSem === '2nd Semester' ? 'selected' : '' ?>>2nd Semester</option>
                <option value="Summer" <?= $selectedSem === 'Summer' ? 'selected' : '' ?>>Summer</option>
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
      <div style="display:flex;justify-content:flex-end;margin-bottom:10px;">
        <div class="search-wrapper">
          <i class="fa-solid fa-magnifying-glass"></i>
          <input type="text" class="search-input" placeholder="Search assignments..." />
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
                      <form method="post" class="delete-form" data-confirm="Delete this assignment?">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="delete_assignment" value="1">
                       <input type="hidden" name="assignment_id" value="<?= intval($a['assignment_id']) ?>">
                       <button type="submit" class="icon-button" title="Delete assignment">
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
    </div>

    <!-- Edit Assignment Modal -->
    <div class="modal-backdrop" id="edit-modal">
      <div class="modal-card">
        <h3 class="block-title" style="margin-bottom:16px;">Edit Assignment</h3>
      <form method="post" action="" id="subject-form-anchor">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="update_assignment" value="1">
          <input type="hidden" name="assignment_id" id="edit-assignment-id">
          <div class="grid-2col">
            <div class="form-group">
              <label for="edit-teacher">Teacher</label>
              <select id="edit-teacher" name="teacher_id" class="form-control" required>
                <option value="" disabled selected hidden>Select teacher...</option>
                <?php foreach ($teachers as $t): ?>
                  <option value="<?= intval($t['id']) ?>">
                    <?= htmlspecialchars($t['name']) ?>
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
                    <?= htmlspecialchars($sec['name']) ?>
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
              <option value="Summer">Summer</option>
            </select>
          </div>
          <div class="form-buttons-row">
            <button type="submit" class="btn-submit"><i class="fa-solid fa-floppy-disk"></i> Save Changes</button>
            <button type="button" class="btn-cancel" id="btn-close-modal">Cancel</button>
          </div>
</form>
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

    document.addEventListener('DOMContentLoaded', function () {
      const yearSelect = document.getElementById('global-filter-year');
      const semSelect = document.getElementById('global-filter-sem');
      if (yearSelect) yearSelect.addEventListener('change', syncGlobalFilter);
      if (semSelect) semSelect.addEventListener('change', syncGlobalFilter);

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
      const subUnitsInput = document.getElementById('sub-units-input');
      const subYearSelect = document.getElementById('sub-year-select');
      const subSemSelect = document.getElementById('sub-sem-select');
      const btnCancelSubjectEdit = document.getElementById('btn-cancel-subject-edit');

      document.querySelectorAll('.btn-edit-subject').forEach(function (btn) {
        btn.addEventListener('click', function () {
          const data = btn.dataset;
          subjectIdField.value = data.id || '';
          subCodeInput.value = data.code || '';
          subTitleInput.value = data.title || '';
          subUnitsInput.value = data.units || '3';
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
        subUnitsInput.value = '3';
        subYearSelect.value = '';
        subSemSelect.value = '';
      }
      if (btnCancelSubjectEdit) {
        btnCancelSubjectEdit.addEventListener('click', cancelSubjectEdit);
      }

      document.querySelectorAll('.search-input').forEach(function (input) {
        input.addEventListener('input', function () {
          const q = this.value.toLowerCase();
          const panel = this.closest('.panel-block') || this.closest('[id$="-container"]');
          if (!panel) return;
          panel.querySelectorAll('tbody tr').forEach(function (row) {
            row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
          });
        });
      });

      document.querySelectorAll('.delete-form').forEach(form => {
        form.addEventListener('submit', function(e) {
          const msg = this.getAttribute('data-confirm') || 'Are you sure?';
          if (!confirm(msg)) e.preventDefault();
        });
      });
    });
  </script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/inc/app_layout.php';
?>
