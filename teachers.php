<?php
require_once __DIR__ . '/inc/functions.php';
$pageTitle = 'Teachers Management';
$activeNav = 'teachers';
$content = '';
ob_start();
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$term = get_global_term();
$selectedYear = $term['year'];
$selectedSem  = $term['semester'];
$selectedDepartment = trim($_GET['department'] ?? '');


$teacherSaveError = '';
$editingTeacherId = 0;
$editingTeacherData = [];
$isEditingTeacher = false;
$departments = [];

// Credentials hint for the last created teacher.
$lastTeacherCreated = $_SESSION['last_teacher_created'] ?? null;
unset($_SESSION['last_teacher_created']);

$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_error']);
$teacherSaveError = $teacherSaveError ?: $flashError;

$conn = db_connect();
if ($conn) {
    $res = $conn->query("SELECT id, name FROM departments ORDER BY name ASC");
    if ($res) {
        while ($row = $res->fetch_assoc()) $departments[] = $row;
        $res->free();
    }
    $conn->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_teacher'])) {
    if (!verify_csrf()) { header("Location: " . $_SERVER["PHP_SELF"]); exit; }
    $teacherId = intval($_POST['teacher_id'] ?? 0);
    if ($teacherId) {
        $result = delete_teacher($teacherId);
        if ($result['success']) {
            audit_log('delete_teacher');
        } else {
            $teacherSaveError = $result['error'] ?? 'Failed to delete teacher.';
        }
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_teacher'])) {
    if (!verify_csrf()) { header('Location: ' . $_SERVER['PHP_SELF']); exit; }
    $teacherId = intval($_POST['teacher_id'] ?? 0);
    $firstName = trim($_POST['first_name'] ?? '');
    $middleName = trim($_POST['middle_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $department = trim($_POST['department'] ?? '');

    if (!$teacherId || !$firstName || !$lastName) {
        $teacherSaveError = 'First name and last name are required.';
    } else {
        $ok = update_teacher($teacherId, $firstName, $middleName, $lastName, $department ?: null, null);
        if ($ok) {
            audit_log('update_teacher');
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        } else {
            $teacherSaveError = 'Teacher update failed.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_teacher'])) {
    if (!verify_csrf()) { header('Location: ' . $_SERVER['PHP_SELF']); exit; }
    $firstName = trim($_POST['first_name'] ?? '');
    $middleName = trim($_POST['middle_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $department = trim($_POST['department'] ?? '');

    if (!$firstName || !$lastName) {
        $teacherSaveError = 'First name and last name are required.';
    } else {
        $result = create_teacher($firstName, $middleName, $lastName, $department ?: null);
        if ($result['success']) {
            // Store the generated credentials so the admin can see them after redirect.
            $_SESSION['last_teacher_created'] = [
                'teacher_id' => $result['teacher_id'] ?? '',
                'temp_password' => $result['temp_password'] ?? '',
                'teacher_name' => $firstName . ' ' . ($middleName ? ($middleName . ' ') : '') . $lastName,
            ];

            audit_log('create_teacher');
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        } else {
            $teacherSaveError = $result['error'];
        }
    }
}

if (isset($_GET['edit_teacher'])) {
    $editingTeacherId = intval($_GET['edit_teacher']);
    $conn = db_connect();
    if ($conn) {
        $stmt = $conn->prepare("SELECT id, first_name, middle_name, last_name, department FROM teachers WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $editingTeacherId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $r = $res->fetch_assoc()) $editingTeacherData = $r;
            $stmt->close();
        }
        $conn->close();
    }
}

$isEditingTeacher = (bool) $editingTeacherData;

?>


    <div class="tab-content">
      <h1 class="view-title">Teachers Management</h1>
      <p class="view-subtitle">Manage Teachers Account</p>

    <div class="panel-block">
        <div class="block-header">
          <h2 class="block-title">Teachers Registry</h2>
          <div class="header-actions">
            <select id="department-filter" class="global-select" style="width: 220px;" onchange="window.location.href='?department='+encodeURIComponent(this.value)">
              <option value="">All Departments</option>
              <?php foreach ($departments as $dept): ?>
                <option value="<?php echo htmlspecialchars($dept['name']); ?>" <?php echo $selectedDepartment === $dept['name'] ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($dept['name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="search-wrapper">
              <i class="fa-solid fa-magnifying-glass" style = "margin-top: 11.2px;"></i>
              <input type="text" class="search-input" id="teachers-search-input" placeholder="Search teachers..." style = "margin-top: 11.2px;" />
            </div>
<button type="button" id="btn-add-teacher" class="btn-add" style="width: 220px; <?php echo ($teacherSaveError || $isEditingTeacher) ? 'display:none;' : ''; ?>">
               <i class="fa-solid fa-plus"></i> Add Teacher
             </button>
           </div>
         </div>

       <?php if ($lastTeacherCreated): ?>
         <div class="alert alert-success" style="margin-bottom:24px;">
           Teacher <strong><?php echo htmlspecialchars($lastTeacherCreated['teacher_name']); ?></strong> created successfully.
           ID: <code><?php echo htmlspecialchars($lastTeacherCreated['teacher_id']); ?></code>
           Temp Password: <code><?php echo htmlspecialchars($lastTeacherCreated['temp_password']); ?></code>
         </div>
       <?php endif; ?>

       <div id="teacher-modal-backdrop" class="modal-backdrop" style="display:<?php echo ($teacherSaveError || $isEditingTeacher) ? 'flex' : 'none'; ?>;">
            <div class="modal-card">
              <button type="button" class="modal-close" id="btn-close-teacher-modal">
                <i class="fa-solid fa-xmark"></i>
              </button>
              <?php if ($teacherSaveError): ?>
                <p style="color:#b91c1c; margin-bottom: 16px;"><?php echo htmlspecialchars($teacherSaveError); ?></p>
              <?php endif; ?>
              <div class="request-form-header">
                <h3 id="form-title"><?php echo $isEditingTeacher ? 'Edit Teacher' : 'Add Teacher'; ?></h3>
                <p id="form-subtitle"><?php echo $isEditingTeacher ? 'Update the teacher details below.' : 'Enter the teacher details below.'; ?></p>
              </div>
            <form method="post" id="teacher-form">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="teacher_id" id="teacher-id-field" value="<?php echo intval($editingTeacherData['id'] ?? 0); ?>">
              <div class="grid-3col">
                <div class="form-group">
                  <label for="first_name">First Name</label>
                  <input type="text" id="first_name" name="first_name" class="form-control" required value="<?php echo htmlspecialchars($editingTeacherData['first_name'] ?? ''); ?>" />
                </div>
                <div class="form-group">
                  <label for="middle_name">Middle Name</label>
                  <input type="text" id="middle_name" name="middle_name" class="form-control" value="<?php echo htmlspecialchars($editingTeacherData['middle_name'] ?? ''); ?>" />
                </div>
                <div class="form-group">
                  <label for="last_name">Last Name</label>
                  <input type="text" id="last_name" name="last_name" class="form-control" required value="<?php echo htmlspecialchars($editingTeacherData['last_name'] ?? ''); ?>" />
                </div>
              </div>
              <div class="form-group">
                <label for="department">Department</label>
                <select id="department" name="department" class="form-control">
                  <option value="">Select department...</option>
                  <?php foreach ($departments as $dept): ?>
                    <option value="<?php echo htmlspecialchars($dept['name']); ?>" <?php echo ($editingTeacherData['department'] ?? '') === $dept['name'] ? 'selected' : ''; ?>>
                      <?php echo htmlspecialchars($dept['name']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-buttons-row card-action-row">
                <button type="submit" id="submit-btn" name="<?php echo $isEditingTeacher ? 'update_teacher' : 'add_teacher'; ?>" class="btn-submit"><?php echo $isEditingTeacher ? 'Update Teacher' : 'Save Teacher'; ?></button>
                 <button type="button" id="btn-cancel-teacher" class="btn-cancel">Cancel</button>
              </div>
            </form>
          </div>
        </div>
        <div class="table-responsive">
          <table id="teachers-table">
            <thead>
              <tr>
                <th>T-ID</th>
                <th>Name</th>
                <th>Department</th>
                <th>Password</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
            <?php
            $conn = db_connect();
            if ($conn) {
                $where = [];
                $params = [];
                $types = '';
                if ($selectedDepartment !== '') {
                    $where[] = 't.department = ?';
                    $params[] = $selectedDepartment;
                    $types .= 's';
                }
                $sql = "SELECT t.id, t.teacher_id, t.first_name, t.middle_name, t.last_name, t.department FROM teachers t";
                if ($where) {
                    $sql .= ' WHERE ' . implode(' AND ', $where);
                }
                $sql .= ' ORDER BY t.last_name ASC, t.first_name ASC';
                $stmt = $conn->prepare($sql);
                if ($params) {
                    $stmt->bind_param($types, ...$params);
                }
                $stmt->execute();
                $res = $stmt->get_result();
                $stmt->close();
                if ($res) {
                    if ($res->num_rows) {
                        while ($row = $res->fetch_assoc()) {
                            $fullName = htmlspecialchars($row['first_name'] . ' ' . ($row['middle_name'] ? $row['middle_name'] . ' ' : '') . $row['last_name']);
                            $passwordHint = htmlspecialchars(generate_password($row['last_name'], (int)$row['id']));
                            echo '<tr>';
                            echo '<td>' . htmlspecialchars($row['teacher_id'] ?? ('T-' . sprintf('%03d', $row['id']))) . '</td>';
                            echo '<td>' . $fullName . '</td>';
                            echo '<td>' . htmlspecialchars($row['department'] ?? '') . '</td>';
                            echo '<td><div class="password-cell"><span class="password-dots" id="pwd-dots-' . intval($row['id']) . '">••••••••</span><span class="password-text" id="pwd-text-' . intval($row['id']) . '" style="display:none;">' . $passwordHint . '</span><button type="button" class="icon-button toggle-password-btn" data-user-id="' . intval($row['id']) . '" title="Show/Hide password"><i class="fa-solid fa-eye" id="pwd-eye-' . intval($row['id']) . '"></i></button></div></td>';
                            echo '<td class="actions-cell">';
                            echo '<a href="?edit_teacher=' . intval($row['id']) . '&department=' . urlencode($selectedDepartment) . '" class="icon-button" title="Edit teacher" style="text-decoration:none;"><i class="fa-solid fa-pen-to-square" style="color:#10b981;"></i></a>';
                            echo '<form method="post" class="delete-form" data-confirm="Delete this teacher?">' . csrf_field() . '<input type="hidden" name="delete_teacher" value="1"><input type="hidden" name="teacher_id" value="' . intval($row['id']) . '"><button type="submit" class="icon-button" title="Delete teacher"><i class="fa-solid fa-trash-can"></i></button></form>';
                            echo '</td>';
                            echo '</tr>';
                        }
                    } else {
                        echo '<tr><td colspan="5" style="text-align:center;color:#6b7280;padding:18px;">No teachers found' . ($selectedDepartment ? ' in ' . htmlspecialchars($selectedDepartment) : '') . '.</td></tr>';
                    }
                } else {
                    echo '<tr><td colspan="5" style="text-align:center;color:#6b7280;padding:18px;">Query error.</td></tr>';
                }
                $conn->close();
            } else {
                echo '<tr><td colspan="5" style="text-align:center;color:#6b7280;padding:18px;">Database unavailable.</td></tr>';
            }
            ?>
            </tbody>
          </table>
        </div>
    </div>
  </div>
  <script>
    const btnAddTeacher = document.getElementById('btn-add-teacher');
    const teacherModalBackdrop = document.getElementById('teacher-modal-backdrop');
    const btnCloseTeacherModal = document.getElementById('btn-close-teacher-modal');
    const btnCancelTeacher = document.getElementById('btn-cancel-teacher');
    const yearSelect = document.getElementById('global-filter-year');
    const semSelect = document.getElementById('global-filter-sem');
    const teacherIdField = document.getElementById('teacher-id-field');

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

    function openTeacherModal() {
      document.getElementById('teacher-modal-backdrop').style.display = 'flex';
      document.getElementById('btn-add-teacher').style.display = 'none';
      document.getElementById('teacher-form').reset();
      document.getElementById('teacher-id-field').value = '';
      document.getElementById('form-title').textContent = 'Add Teacher';
      document.getElementById('form-subtitle').textContent = 'Enter the teacher details below.';
      const submitBtn = document.querySelector('#teacher-form button[type="submit"]');
      submitBtn.name = 'add_teacher';
      submitBtn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Teacher';
    }

    function closeTeacherModal() {
      document.getElementById('teacher-modal-backdrop').style.display = 'none';
      document.getElementById('btn-add-teacher').style.display = 'inline-flex';
      document.getElementById('teacher-form').reset();
      document.getElementById('teacher-id-field').value = '';
      document.getElementById('form-title').textContent = 'Add Teacher';
      document.getElementById('form-subtitle').textContent = 'Enter the teacher details below.';
      const submitBtn = document.querySelector('#teacher-form button[type="submit"]');
      submitBtn.name = 'add_teacher';
      submitBtn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save Teacher';
    }

    function dismissTeacherModal() {
      const teacherIdValue = document.getElementById('teacher-id-field').value;
      if (teacherIdValue && teacherIdValue !== '0') {
        var url = new URL(window.location);
        url.searchParams.delete('edit_teacher');
        window.location.href = url.toString();
      } else {
        closeTeacherModal();
      }
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

    function filterTeachers(query) {
      query = query.toLowerCase();
      document.querySelectorAll('#teachers-table tbody tr').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(query) ? '' : 'none';
      });
    }

    document.addEventListener('DOMContentLoaded', function() {
      if (btnAddTeacher) {
        btnAddTeacher.addEventListener('click', openTeacherModal);
      }
      if (btnCloseTeacherModal) {
        btnCloseTeacherModal.addEventListener('click', dismissTeacherModal);
      }
      if (btnCancelTeacher) {
        btnCancelTeacher.addEventListener('click', dismissTeacherModal);
      }
      if (teacherModalBackdrop) {
        teacherModalBackdrop.addEventListener('click', function(e) {
          if (e.target === teacherModalBackdrop) dismissTeacherModal();
        });
      }
      if (yearSelect) {
        yearSelect.addEventListener('change', syncGlobalFilter);
      }
      if (semSelect) {
        semSelect.addEventListener('change', syncGlobalFilter);
      }

      document.querySelectorAll('.search-input').forEach(input => {
        input.addEventListener('input', function() {
          filterTeachers(this.value);
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
          e.preventDefault();
          var self = this;
          var msg = this.getAttribute('data-confirm') || 'Are you sure?';
          Swal.fire({
            title: 'Confirm Delete',
            text: msg,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc2626',
            cancelButtonColor: '#6b7280',
            confirmButtonText: 'Yes, Delete',
            cancelButtonText: 'Cancel'
          }).then(function(result) {
            if (result.isConfirmed) {
              self.submit();
            }
          });
        });
      });

      const teacherIdValue = teacherIdField ? teacherIdField.value : '';
      if (teacherIdValue && teacherIdValue !== '0') {
        document.getElementById('form-title').textContent = 'Edit Teacher';
        document.getElementById('form-subtitle').textContent = 'Update the teacher details below.';
        const submitBtn = document.querySelector('#teacher-form button[type="submit"]');
        submitBtn.name = 'update_teacher';
        submitBtn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Update Teacher';
      }
    });
  </script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/inc/app_layout.php';
?>

<script>
  document.addEventListener('DOMContentLoaded', function() {
    <?php if ($lastTeacherCreated): ?>
      showSuccess('Teacher <?php echo addslashes(htmlspecialchars($lastTeacherCreated['teacher_name'])); ?> added successfully.');
    <?php endif; ?>
    <?php if ($teacherSaveError): ?>
      showError('<?php echo addslashes(htmlspecialchars($teacherSaveError)); ?>');
    <?php endif; ?>
  });
</script>
?>
