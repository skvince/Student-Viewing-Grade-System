<?php
require_once __DIR__ . '/inc/functions.php';
$pageTitle = 'Students Management';
$activeNav = 'students';
$content = '';
ob_start();
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$term = get_global_term();
$selectedYear = $term['year'];
$selectedSem  = $term['semester'];
$studentSaveError = '';
    $submittedFirstName = '';
    $submittedMiddleName = '';
    $submittedLastName = '';
    $submittedSectionId = 0;
    $submittedDepartment = '';
    $sections = [];
    $departments = [];
    $editingStudentId = 0;
    $editingStudentData = [];

    $departments = get_departments();

      $conn = db_connect();
      if ($conn) {
        $stmt = $conn->prepare("SELECT id, name AS section_code, name FROM sections WHERE school_year = ? AND semester = ? ORDER BY name ASC");
        if ($stmt) {
          $stmt->bind_param('ss', $selectedYear, $selectedSem);
          $stmt->execute();
          $sectionRes = $stmt->get_result();
          $stmt->close();
        }
        if ($sectionRes) {
          while ($row = $sectionRes->fetch_assoc()) {
            $sections[] = $row;
          }
          $sectionRes->free();
        }
        $conn->close();
      }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_student'])) {
        if (!verify_csrf()) { header('Location: ' . $_SERVER['PHP_SELF']); exit; }
      $studentIdToDelete = intval($_POST['student_id'] ?? 0);
      if ($studentIdToDelete) {
        delete_student($studentIdToDelete);
        audit_log('delete_student');
      }
      header('Location: ' . $_SERVER['PHP_SELF']);
      exit;
    }

    // Handle update student form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_student'])) {
        if (!verify_csrf()) { header('Location: ' . $_SERVER['PHP_SELF']); exit; }
      $studentId = intval($_POST['student_id'] ?? 0);
      $firstName = trim($_POST['first_name'] ?? '');
      $middleName = trim($_POST['middle_name'] ?? '');
      $lastName = trim($_POST['last_name'] ?? '');
      $submittedSectionId = intval($_POST['section_id'] ?? 0);
      $submittedDepartment = trim($_POST['department'] ?? '');

      if (!$studentId || !$firstName || !$lastName) {
        $studentSaveError = 'First name and last name are required.';
      } else {
        $ok = update_student($studentId, $firstName, $middleName, $lastName, $submittedSectionId ?: null, $submittedDepartment ?: null, null);
        if ($ok) {
          audit_log('update_student');
          header('Location: ' . $_SERVER['PHP_SELF']);
          exit;
        } else {
          $studentSaveError = 'Student update failed.';
        }
      }
    }

    // Handle add student form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_student'])) {
        if (!verify_csrf()) { header('Location: ' . $_SERVER['PHP_SELF']); exit; }
      $firstName = trim($_POST['first_name'] ?? '');
      $middleName = trim($_POST['middle_name'] ?? '');
      $lastName = trim($_POST['last_name'] ?? '');
      $submittedSectionId = intval($_POST['section_id'] ?? 0);
      $submittedDepartment = trim($_POST['department'] ?? '');
      $submittedFirstName = $firstName;
      $submittedMiddleName = $middleName;
      $submittedLastName = $lastName;

      if (! $firstName || ! $lastName) {
        $studentSaveError = 'First name and last name are required.';
      } else {
        $result = create_student($firstName, $middleName, $lastName, $submittedSectionId ?: null, $submittedDepartment ?: null);
      if ($result['success']) {
          audit_log('create_student');
          $_SESSION['last_student_created'] = [
              'student_id' => $result['student_id'] ?? '',
              'temp_password' => $result['temp_password'] ?? '',
              'student_name' => $firstName . ' ' . ($middleName ? ($middleName . ' ') : '') . $lastName,
          ];
          header('Location: ' . $_SERVER['PHP_SELF']);
          exit;
        } else {
          $studentSaveError = $result['error'];
        }
      }
    }

    if (isset($_GET['edit_student'])) {
      $editingStudentId = intval($_GET['edit_student']);
      $conn = db_connect();
      if ($conn) {
        $stmt = $conn->prepare(
          'SELECT s.id, s.first_name, s.middle_name, s.last_name, s.section_id, s.department,
                  sec.name AS section_code, sec.name AS section_name
           FROM students s
           LEFT JOIN sections sec ON s.section_id = sec.id
           WHERE s.id = ?
           LIMIT 1'
        );
        if ($stmt) {
          $stmt->bind_param('i', $editingStudentId);
          $stmt->execute();
          $res = $stmt->get_result();
          if ($res && $row = $res->fetch_assoc()) $editingStudentData = $row;
          $stmt->close();
        }
        $conn->close();
      }
    }


    $formFirstName = $editingStudentData['first_name'] ?? $submittedFirstName ?? '';
    $formMiddleName = $editingStudentData['middle_name'] ?? $submittedMiddleName ?? '';
    $formLastName = $editingStudentData['last_name'] ?? $submittedLastName ?? '';
    $formDepartment = $editingStudentData['department'] ?? $submittedDepartment ?? '';
    $formSectionId = intval($editingStudentData['section_id'] ?? $submittedSectionId ?? 0);
    $isEditingStudent = (bool) $editingStudentData;
    $lastStudentCreated = $_SESSION['last_student_created'] ?? null;
    unset($_SESSION['last_student_created']);

?>


        <div class="tab-content" style="display: block;">
          <h1 class="view-title">Students Management</h1>
          <p class="view-subtitle">Manage registered institution students</p>

          <?php if ($lastStudentCreated): ?>
          <div style="background:#dcfce7;color:#166534;padding:12px 16px;border-radius:8px;border:1px solid #bbf7d0;margin-bottom:16px;font-size:0.9rem;">
            <strong>Student created:</strong> <?php echo htmlspecialchars($lastStudentCreated['student_name'] ?? ''); ?>
            &nbsp;|&nbsp;
            <strong>User ID:</strong> <?php echo htmlspecialchars($lastStudentCreated['student_id'] ?? ''); ?>
            &nbsp;|&nbsp;
            <strong>Password:</strong> <?php echo htmlspecialchars($lastStudentCreated['temp_password'] ?? ''); ?>
          </div>
          <?php endif; ?>

          <div class="panel-block">
            <div class="block-header" style="
                  display: flex;
                  justify-content: space-between;
                  align-items: center;
                  flex-wrap: wrap;
                  gap: 15px;
                  margin-bottom: 15px;
                ">
              <h2 class="block-title" style="margin: 0">Students Registry</h2>
              <div class="header-actions" style="display: flex; gap: 15px; align-items: center">
                <!-- Local Table Search -->
                <div class="search-wrapper" style="position: relative; min-width: 250px">
                  <i class="fa-solid fa-magnifying-glass" style="
                        position: absolute;
                        left: 10px;
                        top: 50%;
                        transform: translateY(-50%);
                        color: #aaa;
                      "></i>
                  <input type="text" class="table-search-input" placeholder="Search students..." style="
                        width: 100%;
                        padding: 6px 12px 6px 32px;
                        border-radius: 4px;
                        border: 1px solid #ccc;
                      " />
                </div>
                <button type="button" id="btn-add-student" class="btn-add" style="<?php echo $isEditingStudent ? 'display:none;' : ''; ?>">
                  <i class="fa-solid fa-plus"></i> Add Student
                </button>
              </div>
            </div>
              <div id="modal-backdrop" class="modal-backdrop" style="<?php echo $isEditingStudent ? 'display:flex;' : 'display:none;'; ?>">
                <div id="modal-card" class="modal-card">
                  <button type="button" class="modal-close" id="btn-close-student-modal">
                    <i class="fa-solid fa-xmark"></i>
                  </button>
                  <?php if ($studentSaveError): ?>
                    <p style="color:#b91c1c; margin-bottom: 16px;"><?php echo htmlspecialchars($studentSaveError); ?></p>
                  <?php endif; ?>
                   <div class="request-form-header">
                     <h3 id="form-title">Add Student</h3>
                     <p id="form-subtitle">Enter the student details below.</p>
                   </div>
                  <form method="post" id="student-form">
                    <?php echo csrf_field(); ?>
                   <input type="hidden" name="student_id" id="student-id-field" value="<?php echo intval($editingStudentData['id'] ?? 0); ?>">
                   <div class="grid-3col">
                     <div class="form-group">
                       <label for="first_name">First Name</label>
                       <input type="text" id="first_name" name="first_name" class="form-control" required placeholder="First name" value="<?php echo htmlspecialchars($formFirstName); ?>" />
                     </div>
                     <div class="form-group">
                       <label for="middle_name">Middle Name</label>
                       <input type="text" id="middle_name" name="middle_name" class="form-control" placeholder="Middle name" value="<?php echo htmlspecialchars($formMiddleName); ?>" />
                     </div>
                     <div class="form-group">
                       <label for="last_name">Last Name</label>
                       <input type="text" id="last_name" name="last_name" class="form-control" required placeholder="Last name" value="<?php echo htmlspecialchars($formLastName); ?>" />
                     </div>
                   </div>
                   <div class="form-group">
                     <label for="department">Department</label>
                     <select id="department" name="department" class="form-control">
                       <option value="">Select department...</option>
                       <?php foreach ($departments as $dept): ?>
                          <option value="<?php echo htmlspecialchars($dept['name']); ?>" <?php echo $formDepartment === $dept['name'] ? 'selected' : ''; ?>>
                           <?php echo htmlspecialchars($dept['name']); ?>
                         </option>
                       <?php endforeach; ?>
                     </select>
                   </div>
                   <div class="form-group">
                     <label for="section_id">Section</label>
                     <select name="section_id" id="section_id" class="form-control" required>
                       <option value="" selected>Select section...</option>

    <?php foreach ($sections as $sectionOption): ?>
                       <option value="<?php echo htmlspecialchars($sectionOption['id']); ?>"<?php echo $formSectionId == $sectionOption['id'] ? ' selected' : ''; ?>>
                          <?php echo htmlspecialchars($sectionOption['name']); ?>
                       </option>
    <?php endforeach; ?>

    <?php if ($isEditingStudent && $formSectionId && !empty($editingStudentData['section_code']) && !empty($editingStudentData['section_name'])): ?>
      <?php $alreadyInList = false; ?>
      <?php foreach ($sections as $sectionOptionCheck): ?>
        <?php if (intval($sectionOptionCheck['id']) === intval($formSectionId)) { $alreadyInList = true; break; } ?>
      <?php endforeach; ?>
      <?php if (!$alreadyInList): ?>
        <option value="<?php echo htmlspecialchars($formSectionId); ?>" selected>
          <?php echo htmlspecialchars($editingStudentData['section_name']); ?>
        </option>
      <?php endif; ?>
    <?php endif; ?>

                     </select>
                   </div>
                   <div class="form-buttons-row card-action-row">
                     <button type="submit" id="submit-btn" name="add_student" class="btn-submit">Register</button>
                   </div>
                 </form>
              </div>
            </div>
            <div class="table-responsive">
              <table id="students-table">
                <thead>
              <tr>
                <th>User ID</th>
                <th>Name</th>
                <th>Department</th>
                <th>Section</th>
                <th>Password</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php
            $conn = db_connect();
    if ($conn) {
// Keep students registry term-agnostic (global filter should not hide CRUD rows)
                $res = $conn->query(
                    "SELECT s.id, s.student_id, s.first_name, s.middle_name, s.last_name, s.section_id, s.department, sec.name AS section_name, sec.section_code " .
                    "FROM students s " .
                    "LEFT JOIN sections sec ON s.section_id = sec.id " .
                    "ORDER BY s.last_name ASC, s.first_name ASC"
                );
                if ($res) {
                    if ($res->num_rows) {
                        while ($row = $res->fetch_assoc()) {
                            $fullName = htmlspecialchars($row['first_name'] . ' ' . ($row['middle_name'] ? $row['middle_name'] . ' ' : '') . $row['last_name']);
                            $passwordHint = htmlspecialchars(generate_password($row['last_name'], (int)$row['id']));
                            echo '<tr>';
                             echo '<td>' . htmlspecialchars(!empty($row['student_id']) ? $row['student_id'] : ('S-' . sprintf('%03d', $row['id']))) . '</td>';
                            echo '<td>' . $fullName . '</td>';
                            echo '<td>' . htmlspecialchars($row['department'] ?? '') . '</td>';
                            $sectionDisplay = trim($row['section_name'] ?? '');
                            echo '<td>' . htmlspecialchars($sectionDisplay) . '</td>';
                            echo '<td><div class="password-cell"><span class="password-dots" id="pwd-dots-' . intval($row['id']) . '">••••••••</span><span class="password-text" id="pwd-text-' . intval($row['id']) . '" style="display:none;">' . $passwordHint . '</span><button type="button" class="icon-button toggle-password-btn" data-user-id="' . intval($row['id']) . '" title="Show/Hide password"><i class="fa-solid fa-eye" id="pwd-eye-' . intval($row['id']) . '"></i></button></div></td>';
                            echo '<td class="actions-cell">';
                            echo '<a href="?edit_student=' . intval($row['id']) . '" class="icon-button" title="Edit student" style="text-decoration:none;"><i class="fa-solid fa-pen-to-square" style="color:#10b981;"></i></a>';
                            echo '<form method="post" class="delete-form" data-confirm="Delete this student?">' . csrf_field() . '<input type="hidden" name="delete_student" value="1"><input type="hidden" name="student_id" value="' . intval($row['id']) . '"><button type="submit" class="icon-button" title="Delete student"><i class="fa-solid fa-trash-can"></i></button></form>';
                            echo '</td>';
                            echo '</tr>';
                        }
                    } else {
                        echo '<tr><td colspan="6" style="text-align:center;color:#6b7280;padding:18px;">No students found.</td></tr>';
                    }
                } else {
                    echo '<tr><td colspan="6" style="text-align:center;color:#6b7280;padding:18px;">Query error.</td></tr>';
                }
                $conn->close();
            } else {
                echo '<tr><td colspan="6" style="text-align:center;color:#6b7280;padding:18px;">Database unavailable.</td></tr>';
            }
            ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
      <script>
        const btnAddStudent = document.getElementById('btn-add-student');
        const modalBackdrop = document.getElementById('modal-backdrop');
        const btnCloseStudentModal = document.getElementById('btn-close-student-modal');
        const yearSelect = document.getElementById('global-filter-year');
        const semSelect = document.getElementById('global-filter-sem');
        const studentIdField = document.getElementById('student-id-field');
        const submitBtn = document.getElementById('submit-btn');

        function openStudentModal() {
          if (!modalBackdrop) return;
          modalBackdrop.style.display = 'flex';
          if (btnAddStudent) btnAddStudent.style.display = 'none';
          resetForm();
        }

        function closeStudentModal() {
          if (!modalBackdrop) return;
          modalBackdrop.style.display = 'none';
          if (btnAddStudent) btnAddStudent.style.display = 'inline-flex';
          resetForm();
        }

        function editStudent(id, firstName, middleName, lastName, department, sectionId) {
          document.getElementById('student-id-field').value = id;
          document.getElementById('first_name').value = firstName;
          document.getElementById('middle_name').value = middleName;
          document.getElementById('last_name').value = lastName;
          document.getElementById('department').value = department || '';
          document.getElementById('section_id').value = sectionId || '';
          document.getElementById('form-title').textContent = 'Edit Student';
          document.getElementById('form-subtitle').textContent = 'Update the student details below.';
          document.getElementById('submit-btn').name = 'update_student';
          document.getElementById('submit-btn').textContent = 'Update Student';
          document.getElementById('modal-backdrop').style.display = 'flex';
          document.getElementById('btn-add-student').style.display = 'none';
        }

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

        function resetForm() {
          document.getElementById('student-id-field').value = '';
          document.getElementById('student-form').reset();
          document.getElementById('form-title').textContent = 'Add Student';
          document.getElementById('form-subtitle').textContent = 'Enter the student details below.';
          document.getElementById('submit-btn').name = 'add_student';
          document.getElementById('submit-btn').textContent = 'Register';
        }

        function togglePassword(id) {
          const dots = document.getElementById('pwd-dots-' + id);
          const text = document.getElementById('pwd-text-' + id);
          const eye = document.getElementById('pwd-eye-' + id);
          if (dots.style.display === 'none') {
            dots.style.display = '';
            text.style.display = 'none';
            eye.className = 'fa-solid fa-eye';
          } else {
            dots.style.display = 'none';
            text.style.display = '';
            eye.className = 'fa-solid fa-eye-slash';
          }
        }

        function deleteStudent(id) {
          const form = document.createElement('form');
          form.method = 'POST';
          form.innerHTML = '<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES); ?>"><input type="hidden" name="delete_student" value="1"><input type="hidden" name="student_id" value="' + id + '">';
          document.body.appendChild(form);
          form.submit();
        }

        document.addEventListener('DOMContentLoaded', function() {
          if (btnAddStudent) {
            btnAddStudent.addEventListener('click', openStudentModal);
          }
          if (btnCloseStudentModal) {
            btnCloseStudentModal.addEventListener('click', closeStudentModal);
          }
          if (modalBackdrop) {
            modalBackdrop.addEventListener('click', function(e) {
              if (e.target === modalBackdrop) closeStudentModal();
            });
          }
          if (yearSelect) {
            yearSelect.addEventListener('change', syncGlobalFilter);
          }
          if (semSelect) {
            semSelect.addEventListener('change', syncGlobalFilter);
          }

          document.querySelectorAll('.table-search-input').forEach(input => {
            input.addEventListener('input', function() {
              const query = this.value.toLowerCase();
              document.querySelectorAll('#students-table tbody tr').forEach(row => {
                row.style.display = row.textContent.toLowerCase().includes(query) ? '' : 'none';
              });
            });
          });

          document.querySelectorAll('.toggle-password-btn').forEach(btn => {
            btn.addEventListener('click', function() {
              const userId = this.getAttribute('data-user-id');
              if (userId) togglePassword(userId);
            });
          });

          document.querySelectorAll('.delete-form').forEach(form => {
            form.addEventListener('submit', function(e) {
              const msg = this.getAttribute('data-confirm') || 'Are you sure?';
              if (!confirm(msg)) e.preventDefault();
            });
          });

          if (studentIdField && studentIdField.value) {
            submitBtn.name = 'update_student';
            submitBtn.textContent = 'Update Student';
          }
        });
      </script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/inc/app_layout.php';
?>
