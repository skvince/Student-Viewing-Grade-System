<?php
require_once __DIR__ . '/inc/functions.php';
$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
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

$conn = db_connect();
$totalTeachers = 0;
$totalStudents = 0;
$totalAssignments = 0;
if ($conn) {
<<<<<<< HEAD
    $stmt = $conn->prepare("SELECT COUNT(*) AS count FROM teachers");
    if ($stmt) { $stmt->execute(); $res = $stmt->get_result(); $totalTeachers = (int)($res->fetch_assoc()['count'] ?? 0); $stmt->close(); }

    $stmt = $conn->prepare("SELECT COUNT(*) AS count FROM students");
    if ($stmt) { $stmt->execute(); $res = $stmt->get_result(); $totalStudents = (int)($res->fetch_assoc()['count'] ?? 0); $stmt->close(); }

    $stmt = $conn->prepare("SELECT COUNT(*) AS count FROM assignments WHERE school_year = ? AND semester = ?");
    if ($stmt) {
        $stmt->bind_param('ss', $selectedYear, $selectedSem);
        $stmt->execute();
        $res = $stmt->get_result();
        $totalAssignments = (int)($res->fetch_assoc()['count'] ?? 0);
        $stmt->close();
=======
    $totalTeachers = (int) $conn->query("SELECT COUNT(*) AS count FROM teachers")->fetch_assoc()['count'];
    $totalStudents = (int) $conn->query("SELECT COUNT(*) AS count FROM students")->fetch_assoc()['count'];
    $totalAssignments = (int) $conn->query("SELECT COUNT(*) AS count FROM assignments WHERE school_year = '" . $conn->real_escape_string($selectedYear) . "' AND semester = '" . $conn->real_escape_string($selectedSem) . "'")->fetch_assoc()['count'];
    $res = $conn->query(
        "SELECT " .
        "  a.module, a.school_year, a.semester, " .
        "  TRIM(CONCAT(t.first_name, ' ', IF(t.middle_name <> '', CONCAT(t.middle_name, ' '), ''), t.last_name)) AS teacher_name, " .
        "  s.name AS section_name " .
        "FROM assignments a " .
        "LEFT JOIN teachers t ON a.teacher_id = t.id " .
        "LEFT JOIN sections s ON a.section_id = s.id " .
        "WHERE a.school_year = '" . $conn->real_escape_string($selectedYear) . "' AND a.semester = '" . $conn->real_escape_string($selectedSem) . "' " .
        "ORDER BY a.created_at DESC LIMIT 10"
    );
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $recentAssignments[] = $row;
        }
        $res->free();
>>>>>>> 3b6f20fcc5342bc3e2d7bf193e6c1f9123790c85
    }
    $conn->close();
}

$studentStatsByDept = get_department_student_stats($selectedYear, $selectedSem);
$teacherStatsByDept = get_department_teacher_stats($selectedYear, $selectedSem);
$overallStudentTotal = 0;
foreach ($studentStatsByDept as $st) $overallStudentTotal += (int)($st['total_students'] ?? 0);
$overallTeacherTotal = 0;
foreach ($teacherStatsByDept as $st) $overallTeacherTotal += (int)($st['total_teachers'] ?? 0);
?>
    <div class="tab-content" style="display: block;">

      <div class="dashboard-grid">
        <div class="dash-card">
          <a href="teachers.php" class="dash-card-action" title="Add Teacher"><i class="fa-solid fa-plus"></i></a>
          <div class="dash-card-icon teachers"><i class="fa-solid fa-chalkboard-user"></i></div>
          <div class="dash-card-body">
            <div class="dash-card-label">Teachers</div>
            <div class="dash-card-value"><?= htmlspecialchars($totalTeachers) ?></div>
          </div>
        </div>
        <div class="dash-card">
          <a href="students.php" class="dash-card-action" title="Add Student"><i class="fa-solid fa-plus"></i></a>
          <div class="dash-card-icon students"><i class="fa-solid fa-user-graduate"></i></div>
          <div class="dash-card-body">
            <div class="dash-card-label">Students</div>
            <div class="dash-card-value"><?= htmlspecialchars($totalStudents) ?></div>
          </div>
        </div>
        <div class="dash-card">
          <a href="assign.php" class="dash-card-action" title="New Assignment"><i class="fa-solid fa-plus"></i></a>
          <div class="dash-card-icon assignments"><i class="fa-solid fa-book-open"></i></div>
          <div class="dash-card-body">
            <div class="dash-card-label">Assignments</div>
            <div class="dash-card-value"><?= htmlspecialchars($totalAssignments) ?></div>
          </div>
        </div>
      </div>

      <?php
      $upcomingDeadlines = get_upcoming_deadlines($selectedYear, $selectedSem, 5);
      ?>

      <div class="stats-grid">
        <div class="panel-block">
          <div class="header-row">
            <div>
              <div class="section-title">Student Department Statistics</div>
              <div class="section-subtitle">Distribution for <?= htmlspecialchars($selectedYear) ?> / <?= htmlspecialchars($selectedSem) ?></div>
            </div>
          </div>
          <div class="stats-table-wrap">
            <table class="stats-table">
                <thead>
                  <tr>
                    <th>Department</th>
                    <th>Students</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($studentStatsByDept as $st): ?>
                    <?php
                      $dept = htmlspecialchars($st['department'] ?? 'Unknown');
                      $count = (int)($st['total_students'] ?? 0);
                    ?>
                    <tr>
                      <td class="dept-cell" title="<?= $dept ?>"><?= $dept ?></td>
                      <td><strong><?= $count ?></strong></td>
                    </tr>
                  <?php endforeach; ?>
                  <?php if (empty($studentStatsByDept)): ?>
                    <tr><td colspan="2" style="text-align:center;color:#6b7280;padding:18px;">No data available.</td></tr>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="panel-block">
          <div class="header-row">
            <div>
              <div class="section-title">Teacher Department Statistics</div>
              <div class="section-subtitle">Distribution for <?= htmlspecialchars($selectedYear) ?> / <?= htmlspecialchars($selectedSem) ?></div>
            </div>
          </div>
          <div class="stats-table-wrap">
            <table class="stats-table">
              <thead>
                <tr>
                  <th>Department</th>
                  <th>Teachers</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($teacherStatsByDept as $st): ?>
                  <?php
                    $dept = htmlspecialchars($st['department'] ?? 'Unknown');
                    $count = (int)($st['total_teachers'] ?? 0);
                  ?>
                  <tr>
                    <td class="dept-cell" title="<?= $dept ?>"><?= $dept ?></td>
                    <td><strong><?= $count ?></strong></td>
                  </tr>
                <?php endforeach; ?>
                <?php if (empty($teacherStatsByDept)): ?>
                  <tr><td colspan="2" style="text-align:center;color:#6b7280;padding:18px;">No data available.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/inc/app_layout.php';
?>
