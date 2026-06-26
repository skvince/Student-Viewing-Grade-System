<?php
require_once __DIR__ . '/inc/functions.php';
$pageTitle = 'Section & Department';
$activeNav = 'sections';
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


$sections = [];
$departments = [];
$saveError = '';
$saveSuccess = '';

$conn = db_connect();
if ($conn) {
    $conn->query(
        "CREATE TABLE IF NOT EXISTS departments (" .
        "id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, " .
        "department_code VARCHAR(100) NOT NULL, " .
        "name VARCHAR(255) NOT NULL, " .
        "created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, " .
        "UNIQUE KEY unique_department_code (department_code)" .
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_department'])) {
        if (!verify_csrf()) { header('Location: ' . $_SERVER['PHP_SELF']); exit; }
        $deptCode = trim($_POST['dept_code'] ?? '');
        $deptName = trim($_POST['dept_name'] ?? '');
        if ($deptCode && $deptName) {
            $stmt = $conn->prepare("INSERT INTO departments (department_code, name) VALUES (?, ?)");
            if ($stmt) {
                $stmt->bind_param('ss', $deptCode, $deptName);
                if ($stmt->execute()) {
                    $stmt->close();
                    audit_log('create_department');
                    header('Location: ' . $_SERVER['PHP_SELF']);
                    exit;
                } else {
                    $saveError = 'Department save failed: ' . $stmt->error;
                    $stmt->close();
                }
            } else {
                $saveError = 'Department prepare failed: ' . $conn->error;
            }
        } else {
            $saveError = 'Department code and name are required.';
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_department'])) {
        if (!verify_csrf()) { header('Location: ' . $_SERVER['PHP_SELF']); exit; }
        $deptId = intval($_POST['dept_id'] ?? 0);
        $deptCode = trim($_POST['dept_code'] ?? '');
        $deptName = trim($_POST['dept_name'] ?? '');
        if ($deptId && $deptCode && $deptName) {
            $stmt = $conn->prepare("UPDATE departments SET department_code = ?, name = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('ssi', $deptCode, $deptName, $deptId);
                if ($stmt->execute()) {
                    $stmt->close();
                    audit_log('update_department');
                    header('Location: ' . $_SERVER['PHP_SELF']);
                    exit;
                } else {
                    $saveError = 'Department update failed: ' . $stmt->error;
                    $stmt->close();
                }
            } else {
                $saveError = 'Department update prepare failed: ' . $conn->error;
            }
        } else {
            $saveError = 'Department code and name are required.';
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_department'])) {
        if (!verify_csrf()) { header('Location: ' . $_SERVER['PHP_SELF']); exit; }
        $deptId = intval($_POST['dept_id'] ?? 0);
        if ($deptId) {
            $chk = $conn->prepare("SELECT id FROM sections WHERE department = (SELECT name FROM departments WHERE id = ?) LIMIT 1");
            $inUse = false;
            if ($chk) {
                $chk->bind_param('i', $deptId);
                $chk->execute();
                $chk->store_result();
                $inUse = ($chk->num_rows > 0);
                $chk->close();
            }
            if (!$inUse) {
                $stmt = $conn->prepare("DELETE FROM departments WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param('i', $deptId);
                    $stmt->execute();
                    $stmt->close();
                    audit_log('delete_department');
                }
            } else {
                $saveError = 'Cannot delete: department is in use by sections.';
            }
        }
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_section'])) {
        if (!verify_csrf()) { header('Location: ' . $_SERVER['PHP_SELF']); exit; }
        $name = trim($_POST['sec_name'] ?? '');
        $department = trim($_POST['sec_department'] ?? '');
        $schoolYear = trim($_POST['sec_year'] ?? '');
        $semester = trim($_POST['sec_semester'] ?? '');
        $section_code = trim($_POST['sec_code'] ?? '');

        if ($name) {
            if (! $section_code) {
                $slug = preg_replace('/[^A-Za-z0-9]+/', '-', strtolower($name));
                $section_code = 'SEC-' . strtoupper(substr($slug, 0, 5)) . '-' . substr(time(), -4);
            }

            $stmt = $conn->prepare("INSERT INTO sections (section_code, name, department, school_year, semester) VALUES (?, ?, ?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param('sssss', $section_code, $name, $department, $schoolYear, $semester);
                if ($stmt->execute()) {
                    $stmt->close();
                    audit_log('create_section');
                    header('Location: ' . $_SERVER['PHP_SELF']);
                    exit;
                } else {
                    $saveError = 'Section save failed: ' . $stmt->error;
                    $stmt->close();
                }
            } else {
                $saveError = 'Section prepare failed: ' . $conn->error;
            }
        } else {
            $saveError = 'Section name is required.';
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_section'])) {
        if (!verify_csrf()) { header('Location: ' . $_SERVER['PHP_SELF']); exit; }
        $sectionId = intval($_POST['section_id'] ?? 0);
        $name = trim($_POST['sec_name'] ?? '');
        $department = trim($_POST['sec_department'] ?? '');
        $schoolYear = trim($_POST['sec_year'] ?? '');
        $semester = trim($_POST['sec_semester'] ?? '');
        $section_code = trim($_POST['sec_code'] ?? '');

        if ($sectionId && $name) {
            if (! $section_code) {
                $slug = preg_replace('/[^A-Za-z0-9]+/', '-', strtolower($name));
                $section_code = 'SEC-' . strtoupper(substr($slug, 0, 5)) . '-' . substr(time(), -4);
            }
            $stmt = $conn->prepare("UPDATE sections SET section_code = ?, name = ?, department = ?, school_year = ?, semester = ? WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('sssssi', $section_code, $name, $department, $schoolYear, $semester, $sectionId);
                if ($stmt->execute()) {
                    $stmt->close();
                    audit_log('update_section');
                    header('Location: ' . $_SERVER['PHP_SELF']);
                    exit;
                } else {
                    $saveError = 'Section update failed: ' . $stmt->error;
                    $stmt->close();
                }
            } else {
                $saveError = 'Section update prepare failed: ' . $conn->error;
            }
        } else {
            $saveError = 'Section name is required.';
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_section'])) {
        if (!verify_csrf()) { header('Location: ' . $_SERVER['PHP_SELF']); exit; }
        $sectionId = intval($_POST['section_id'] ?? 0);
        if ($sectionId) {
            $chk = $conn->prepare("SELECT id FROM students WHERE section_id = ? LIMIT 1");
            $inUse = false;
            if ($chk) {
                $chk->bind_param('i', $sectionId);
                $chk->execute();
                $chk->store_result();
                $inUse = ($chk->num_rows > 0);
                $chk->close();
            }
            if (!$inUse) {
                $stmt = $conn->prepare("DELETE FROM sections WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param('i', $sectionId);
                    $stmt->execute();
                    $stmt->close();
                    audit_log('delete_section');
                }
            } else {
                $saveError = 'Cannot delete: section has assigned students.';
            }
        }
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }


    foreach (['teacher_id' => "INT UNSIGNED DEFAULT NULL", 'school_year' => "VARCHAR(20) DEFAULT NULL", 'semester' => "VARCHAR(20) DEFAULT NULL"] as $column => $definition) {
        $check = $conn->query("SHOW COLUMNS FROM sections LIKE '{$column}'");
        if ($check && $check->num_rows === 0) {
            $conn->query("ALTER TABLE sections ADD COLUMN {$column} {$definition}");
        }
        if ($check) {
            $check->free();
        }
    }

    $res = $conn->query("SELECT id, department_code, name FROM departments ORDER BY created_at DESC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $departments[] = $row;
        }
        $res->free();
    }

    $editingDepartment = null;
    if (isset($_GET['edit_department'])) {
        $editingDeptId = intval($_GET['edit_department']);
        $q = $conn->prepare("SELECT id, department_code, name FROM departments WHERE id = ? LIMIT 1");
        if ($q) {
            $q->bind_param('i', $editingDeptId);
            $q->execute();
            $r = $q->get_result()->fetch_assoc();
            $q->close();
        }
        if (!empty($r)) $editingDepartment = $r;
    }

    $editingSection = null;
    if (isset($_GET['edit_section'])) {
        $editingSecId = intval($_GET['edit_section']);
        $q = $conn->prepare("SELECT id, section_code, name, department, school_year, semester FROM sections WHERE id = ? LIMIT 1");
        if ($q) {
            $q->bind_param('i', $editingSecId);
            $q->execute();
            $r = $q->get_result()->fetch_assoc();
            $q->close();
        }
        if (!empty($r)) $editingSection = $r;
    }

    $stmt = $conn->prepare(
        "SELECT id, section_code, name, department, school_year, semester " .
        "FROM sections " .
        "WHERE school_year = ? AND semester = ? " .
        "ORDER BY created_at DESC"
    );
    if ($stmt) {
        $stmt->bind_param('ss', $selectedYear, $selectedSem);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $sections[] = $row;
            }
            $res->free();
        }
        $stmt->close();
    }
    $conn->close();
}
?>


    <div class="tab-content" style="display: block;">
      <h1 class="view-title">Section & Department</h1>
      <div class="view-subtitle">Institutional Divisions</div>

<?php if ($saveError): ?>
      <div style="background-color: #fde8e8; border: 1px solid #dc2626; color: #991b1b; padding: 16px; border-radius: 8px; margin-bottom: 24px;">
        <strong>Error:</strong> <?php echo htmlspecialchars($saveError); ?>
      </div>
<?php endif; ?>

      <!-- Departments Panel Block -->
      <div class="panel-block">
        <div class="block-header">
          <h2 class="block-title">Create / Manage Departments</h2>
        </div>
        <form id="department-form" method="post">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="<?php echo $editingDepartment ? 'update_department' : 'add_department'; ?>" value="1" />
          <?php if ($editingDepartment): ?>
            <input type="hidden" name="dept_id" value="<?= intval($editingDepartment['id']) ?>" />
          <?php endif; ?>
          <div class="grid-2col">
            <div class="form-group">
              <label for="dept-code-input">Department Code</label>
              <input type="text" id="dept-code-input" name="dept_code" class="form-control" placeholder="Enter department code" required value="<?php echo htmlspecialchars($editingDepartment['department_code'] ?? ''); ?>" />
            </div>
            <div class="form-group">
              <label for="dept-name-input">Department Name Description</label>
              <input type="text" id="dept-name-input" name="dept_name" class="form-control" placeholder="Enter department name" required value="<?php echo htmlspecialchars($editingDepartment['name'] ?? ''); ?>" />
            </div>
          </div>
          <div class="form-buttons-row">
            <button type="submit" class="btn-submit">
              <i class="fa-solid <?php echo $editingDepartment ? 'fa-floppy-disk' : 'fa-plus'; ?>"></i>
              <?php echo $editingDepartment ? 'Update Department' : 'Save Department'; ?>
            </button>
            <?php if ($editingDepartment): ?>
              <a href="section.php" class="btn-cancel">Cancel</a>
            <?php endif; ?>
          </div>
        </form>

        <div class="table-responsive" style="margin-top: 20px">
          <!-- Local Table Search Header Wrapper -->
          <div style="
                display: flex;
                justify-content: flex-end;
                margin-bottom: 10px;
              ">
            <div class="search-wrapper" style="position: relative; min-width: 250px">
              <i class="fa-solid fa-magnifying-glass" style="
                    position: absolute;
                    left: 10px;
                    top: 50%;
                    transform: translateY(-50%);
                    color: #aaa;
                  "></i>
              <input id="dept-search-input" type="text" class="table-search-input" placeholder="Search departments..." style="
                    width: 100%;
                    padding: 6px 12px 6px 32px;
                    border-radius: 4px;
                    border: 1px solid #ccc;
                  " />
            </div>
          </div>
          <table id="departments-table">
            <thead>
              <tr>
                <th>Department Code</th>
                <th>Department Name</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="departments-table-body">
<?php
if (count($departments)) {
    foreach ($departments as $department) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($department['department_code']) . '</td>';
        echo '<td>' . htmlspecialchars($department['name']) . '</td>';
        echo '<td class="actions-cell">';
        echo '<a href="?edit_department=' . intval($department['id']) . '#department-block" class="icon-button" title="Edit department" style="text-decoration:none;"><i class="fa-solid fa-pen-to-square" style="color:#10b981;"></i></a>';
        echo '<form method="post" class="delete-form" data-confirm="Delete this department?">' . csrf_field() . '<input type="hidden" name="delete_department" value="1"><input type="hidden" name="dept_id" value="' . intval($department['id']) . '"><button type="submit" class="icon-button" title="Delete department" style="background:transparent;border:none;padding:2px;"><i class="fa-solid fa-trash-can" style="color:#ef4444;"></i></button></form>';
        echo '</td>';
        echo '</tr>';
    }
} else {
    echo '<tr><td colspan="3" style="text-align:center; color:#666; padding:18px;">No departments available.</td></tr>';
}
?>
          </tbody>
          </table>
        </div>
      </div>

      <!-- Sections Panel Block -->
      <div class="panel-block">
        <div class="block-header">
          <h2 class="block-title">Create / Manage Sections</h2>
        </div>
        <form id="section-form" method="post">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="<?php echo $editingSection ? 'update_section' : 'add_section'; ?>" value="1" />
          <?php if ($editingSection): ?>
            <input type="hidden" name="section_id" value="<?= intval($editingSection['id']) ?>" />
          <?php endif; ?>
          <input type="hidden" name="sec_code" id="sec-code-input" value="<?php echo htmlspecialchars($editingSection['section_code'] ?? ''); ?>" />
          <script>
            document.getElementById('section-form').addEventListener('submit', function () {
              const nameInput = document.getElementById('sec-name-input');
              const codeInput = document.getElementById('sec-code-input');
              if (nameInput && codeInput) codeInput.value = nameInput.value.trim();
            });
          </script>
          <div class="grid-3col">
            <div class="form-group">
              <label for="sec-name-input">Section Name</label>
              <input type="text" id="sec-name-input" name="sec_name" class="form-control" placeholder="Enter section name" required value="<?php echo htmlspecialchars($editingSection['name'] ?? ''); ?>" />
            </div>
          <div class="form-group">
            <label for="sec-year-select">School Year</label>
            <select id="sec-year-select" name="sec_year" class="form-control" required>
              <option value="" disabled <?php echo !$editingSection && $selectedYear==='' ? 'selected' : ''; ?>>Select academic year...</option>
              <option value="2025-2026" <?= ($editingSection['school_year'] ?? $selectedYear) === '2025-2026' ? 'selected' : '' ?>>S.Y. 2025-2026</option>
              <option value="2026-2027" <?= ($editingSection['school_year'] ?? $selectedYear) === '2026-2027' ? 'selected' : '' ?>>S.Y. 2026-2027</option>
            </select>
          </div>
          <div class="form-group">
            <label for="sec-sem-select">Semester</label>
            <select id="sec-sem-select" name="sec_semester" class="form-control" required>
              <option value="" disabled <?php echo !$editingSection && $selectedSem==='' ? 'selected' : ''; ?>>Select semester term...</option>
              <option value="1st Semester" <?= ($editingSection['semester'] ?? $selectedSem) === '1st Semester' ? 'selected' : '' ?>>1st Semester</option>
              <option value="2nd Semester" <?= ($editingSection['semester'] ?? $selectedSem) === '2nd Semester' ? 'selected' : '' ?>>2nd Semester</option>
              <option value="Summer" <?= ($editingSection['semester'] ?? $selectedSem) === 'Summer' ? 'selected' : '' ?>>Summer</option>
            </select>
          </div>
            <div class="form-group">
              <label for="sec-dept-select">Department</label>
              <select id="sec-dept-select" name="sec_department" class="form-control" required>
                <option value="" disabled <?php echo !$editingSection ? 'selected' : ''; ?>>Select a department...</option>
                <?php foreach ($departments as $department): ?>
                  <option value="<?php echo htmlspecialchars($department['name']); ?>" <?php echo ($editingSection['department'] ?? '') === $department['name'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($department['department_code'] . ' - ' . $department['name']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="form-buttons-row">
            <button type="submit" class="btn-submit">
              <i class="fa-solid <?php echo $editingSection ? 'fa-floppy-disk' : 'fa-plus'; ?>"></i>
              <?php echo $editingSection ? 'Update Section' : 'Save Section'; ?>
            </button>
            <?php if ($editingSection): ?>
              <a href="section.php" class="btn-cancel">Cancel</a>
            <?php endif; ?>
          </div>
        </form>

        <div class="table-responsive" style="margin-top: 20px">
          <!-- Local Table Search Header Wrapper -->
          <div style="
                display: flex;
                justify-content: flex-end;
                margin-bottom: 10px;
              ">
            <div class="search-wrapper" style="position: relative; min-width: 250px">
              <i class="fa-solid fa-magnifying-glass" style="
                    position: absolute;
                    left: 10px;
                    top: 50%;
                    transform: translateY(-50%);
                    color: #aaa;
                  "></i>
              <input id="section-search-input" type="text" class="table-search-input" placeholder="Search sections..." style="
                    width: 100%;
                    padding: 6px 12px 6px 32px;
                    border-radius: 4px;
                    border: 1px solid #ccc;
                  " />
            </div>
          </div>
          <table id="sections-table">
            <thead>
              <tr>
                <th>Section ID</th>
                <th>Section Name</th>
                <th>School Year</th>
                <th>Semester</th>
                <th>Department</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="sections-table-body">
<?php
if (count($sections)) {
    foreach ($sections as $section) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($section['section_code'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($section['name']) . '</td>';
        echo '<td>' . htmlspecialchars($section['school_year'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($section['semester'] ?? '') . '</td>';
        echo '<td>' . htmlspecialchars($section['department'] ?? '') . '</td>';
        echo '<td class="actions-cell">';
        echo '<a href="?edit_section=' . intval($section['id']) . '#section-form" class="icon-button" title="Edit section" style="text-decoration:none;"><i class="fa-solid fa-pen-to-square" style="color:#10b981;"></i></a>';
        echo '<form method="post" class="delete-form" data-confirm="Delete this section?">' . csrf_field() . '<input type="hidden" name="delete_section" value="1"><input type="hidden" name="section_id" value="' . intval($section['id']) . '"><button type="submit" class="icon-button" title="Delete section" style="background:transparent;border:none;padding:2px;"><i class="fa-solid fa-trash-can" style="color:#ef4444;"></i></button></form>';
        echo '</td>';
        echo '</tr>';
    }
} else {
    echo '<tr><td colspan="6" style="text-align:center; color:#666; padding:18px;">No sections available.</td></tr>';
}
?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
  <script>
    const yearSelect = document.getElementById('global-filter-year');
    const semSelect = document.getElementById('global-filter-sem');

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
      if (yearSelect) yearSelect.addEventListener('change', syncGlobalFilter);
      if (semSelect) semSelect.addEventListener('change', syncGlobalFilter);

      const departmentForm = document.getElementById('department-form');
      const sectionForm = document.getElementById('section-form');
      const departmentTableBody = document.getElementById('departments-table-body');
      const sectionTableBody = document.getElementById('sections-table-body');
      const deptSearchInput = document.getElementById('dept-search-input');
      const sectionSearchInput = document.getElementById('section-search-input');
      const deptCodeInput = document.getElementById('dept-code-input');
      const deptNameInput = document.getElementById('dept-name-input');
      const secNameInput = document.getElementById('sec-name-input');
      const secYearSelect = document.getElementById('sec-year-select');
      const secSemSelect = document.getElementById('sec-sem-select');
      const secDeptSelect = document.getElementById('sec-dept-select');

      function renderEmptyRow(tableBody, colspan, message) {
        tableBody.innerHTML = `
          <tr>
            <td colspan="${colspan}" style="text-align:center; color:#666; padding:18px;">${message}</td>
          </tr>
        `;
      }

      function addDepartmentOption(code, name) {
        if (!secDeptSelect) return;
        const optionValue = name;
        const existing = Array.from(secDeptSelect.options).find(opt => opt.value === optionValue);
        if (existing) return;

        const option = document.createElement('option');
        option.value = optionValue;
        option.textContent = `${code} - ${name}`;
        secDeptSelect.appendChild(option);
      }

      function removeDepartmentOption(name) {
        if (!secDeptSelect) return;
        const option = Array.from(secDeptSelect.options).find(opt => opt.value === name);
        if (option) {
          option.remove();
        }
      }

      function normalizeText(text) {
        return text.toLowerCase().trim();
      }

      function addDepartmentRow(code, name) {
        const row = document.createElement('tr');
        row.innerHTML = `
          <td>${code}</td>
          <td>${name}</td>
          <td class="actions-cell">
            <i class="fa-solid fa-trash-can" role="button" aria-label="Delete department"></i>
          </td>
        `;
        row.querySelector('.fa-trash-can').addEventListener('click', () => {
          row.remove();
          removeDepartmentOption(name);
          if (!departmentTableBody.querySelector('tr')) {
            renderEmptyRow(departmentTableBody, 3, 'No departments available.');
          }
        });
        if (departmentTableBody.querySelector('td[colspan]')) {
          departmentTableBody.innerHTML = '';
        }
        departmentTableBody.appendChild(row);
        addDepartmentOption(code, name);
      }

      function filterTableRows(input, tableBody, placeholderText) {
        const query = normalizeText(input.value);
        const rows = Array.from(tableBody.querySelectorAll('tr')).filter(row => !row.querySelector('td[colspan]'));
        let visibleCount = 0;

        rows.forEach(row => {
          const text = normalizeText(row.textContent);
          const match = text.includes(query);
          row.style.display = match ? '' : 'none';
          if (match) visibleCount++;
        });

        const placeholderRow = tableBody.querySelector('td[colspan]')?.closest('tr');
        const colspan = tableBody.id === 'departments-table-body' ? 3 : 7;
        if (visibleCount === 0) {
          if (!placeholderRow) {
            renderEmptyRow(tableBody, colspan, placeholderText);
          }
        } else if (placeholderRow) {
          placeholderRow.remove();
        }
      }

      if (deptSearchInput) {
        deptSearchInput.addEventListener('input', function () {
          filterTableRows(this, departmentTableBody, 'No departments matched your search.');
        });
      }

      if (sectionSearchInput) {
        sectionSearchInput.addEventListener('input', function () {
          filterTableRows(this, sectionTableBody, 'No sections matched your search.');
        });
      }

      document.querySelectorAll('.delete-form').forEach(form => {
        form.addEventListener('submit', function(e) {
          const msg = this.getAttribute('data-confirm') || 'Are you sure?';
          if (!confirm(msg)) e.preventDefault();
        });
      });

      if (!departmentTableBody.querySelector('tr')) {
        renderEmptyRow(departmentTableBody, 3, 'No departments available.');
      }
    });
  </script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/inc/app_layout.php';
?>
