<?php require_once __DIR__ . '/inc/functions.php'; ?>
<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'student') {
    header('Location: login.php');
    exit;
}

$userId = intval($_SESSION['user_id']);
$userName = '';
$studentSectionId = 0;
$conn = db_connect();
if ($conn) {
    $stmt = $conn->prepare('SELECT first_name, middle_name, last_name, section_id FROM students WHERE id = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $row = $res->fetch_assoc()) {
            $userName = make_full_name($row['first_name'], $row['middle_name'], $row['last_name']);
            $studentSectionId = intval($row['section_id'] ?? 0);
        }
        $stmt->close();
    }
    $conn->close();
}

$term = get_global_term();
$filterYear = $term['year'];
$filterSem  = $term['semester'];

$selectedYear = $filterYear;
$selectedSem  = $filterSem;

$viewingActive = is_grade_viewing_active($selectedYear, $selectedSem);
$studentAccess = check_student_grade_access($userId, $selectedYear, $selectedSem);
$canViewGrades = $viewingActive || $studentAccess;

revoke_expired_access_grants();

$validPairs = get_student_valid_terms($userId);
$allTerms = get_available_terms($userId, 'student');
$defaultYearOptions = get_term_options()['years'];
$yearOptions = $allTerms['years'] ?: $defaultYearOptions;
$yearOptions = array_values(array_unique(array_merge($yearOptions, $defaultYearOptions)));

if (!$selectedYear) $selectedYear = $yearOptions[0] ?? '2025-2026';
if (!$selectedSem) $selectedSem = '1st Semester';

$hasExplicitTerm = isset($_GET['global_year'], $_GET['global_sem']) || isset($_GET['school_year'], $_GET['semester']) || isset($_GET['academic_year'], $_GET['semester']);
if (!$hasExplicitTerm) {
    $autoTerm = get_first_available_student_term($validPairs, $selectedYear);
    if ($autoTerm) {
        $selectedYear = $autoTerm['year'];
        $selectedSem  = $autoTerm['semester'];
    }
}

$semOptions = get_semesters_for_year($validPairs, $selectedYear);
if (empty($semOptions)) $semOptions = ['1st Semester', '2nd Semester', 'Summer'];
if (!in_array($selectedSem, $semOptions, true)) $semOptions[] = $selectedSem;

$_SESSION['student_filter_year'] = $selectedYear;
$_SESSION['student_filter_sem']  = $selectedSem;

$grades = get_student_grades($userId, $selectedYear, $selectedSem);
$assignedSubjects = get_student_assigned_subjects($userId, $selectedYear, $selectedSem);

$displayRows = [];
$seenSubjectCodes = [];

foreach ($assignedSubjects as $subj) {
    $subjectCode = $subj['subject_code'] ?? '';
    $gradeMatch = null;

    foreach ($grades as $g) {
        if (($g['subject_code'] ?? '') === $subjectCode) {
            $gradeMatch = $g;
            break;
        }
    }

    $displayRows[] = [
        'subject_code'  => $subjectCode,
        'subject_title' => $subj['title'] ?? ($gradeMatch['subject_title'] ?? ''),
        'units'         => $subj['units'] ?? ($gradeMatch['units'] ?? 3),
        'prelim'        => $gradeMatch['prelim'] ?? null,
        'midterm'       => $gradeMatch['midterm'] ?? null,
        'finals'        => $gradeMatch['finals'] ?? null,
        'average'       => (isset($gradeMatch['prelim'], $gradeMatch['midterm'], $gradeMatch['finals']) && $gradeMatch['prelim'] !== null && $gradeMatch['midterm'] !== null && $gradeMatch['finals'] !== null) ? ($gradeMatch['average'] ?? null) : null,
        'gwa'           => (isset($gradeMatch['prelim'], $gradeMatch['midterm'], $gradeMatch['finals']) && $gradeMatch['prelim'] !== null && $gradeMatch['midterm'] !== null && $gradeMatch['finals'] !== null) ? ($gradeMatch['gwa'] ?? null) : null,
        'remarks'       => (isset($gradeMatch['prelim'], $gradeMatch['midterm'], $gradeMatch['finals']) && $gradeMatch['prelim'] !== null && $gradeMatch['midterm'] !== null && $gradeMatch['finals'] !== null) ? ($gradeMatch['remarks'] ?? null) : null,
    ];
    $seenSubjectCodes[$subjectCode] = true;
}

foreach ($grades as $g) {
    $subjectCode = $g['subject_code'] ?? '';
    if ($subjectCode === '' || isset($seenSubjectCodes[$subjectCode])) continue;

    $displayRows[] = [
        'subject_code'  => $subjectCode,
        'subject_title' => $g['subject_title'] ?? '',
        'units'         => $g['units'] ?? 3,
        'prelim'        => $g['prelim'] ?? null,
        'midterm'       => $g['midterm'] ?? null,
        'finals'        => $g['finals'] ?? null,
        'average'       => (isset($g['prelim'], $g['midterm'], $g['finals']) && $g['prelim'] !== null && $g['midterm'] !== null && $g['finals'] !== null) ? ($g['average'] ?? null) : null,
        'gwa'           => (isset($g['prelim'], $g['midterm'], $g['finals']) && $g['prelim'] !== null && $g['midterm'] !== null && $g['finals'] !== null) ? ($g['gwa'] ?? null) : null,
        'remarks'       => (isset($g['prelim'], $g['midterm'], $g['finals']) && $g['prelim'] !== null && $g['midterm'] !== null && $g['finals'] !== null) ? ($g['remarks'] ?? null) : null,
    ];
    $seenSubjectCodes[$subjectCode] = true;
}

$totalUnits = 0;
$totalWeightedGwa = 0;
$totalWeightedAvg = 0;
$subjectsPassed = 0;
foreach ($displayRows as $r) {
    $totalUnits += $r['units'] ?? 3;
    if ($gwa = $r['gwa'] ?? null) {
        $gwaNum = (float)str_replace(',', '.', $gwa);
        if (is_numeric($gwaNum)) $totalWeightedGwa += $gwaNum * ($r['units'] ?? 3);
    }
    if ($avg = $r['average'] ?? null) {
        $avgNum = (float)str_replace('%', '', $avg);
        if (is_numeric($avgNum)) $totalWeightedAvg += $avgNum * ($r['units'] ?? 3);
    }
    if ($r['remarks'] === 'Passed') $subjectsPassed++;
}
$totalGwa = $totalUnits > 0 ? $totalWeightedGwa / $totalUnits : 0;
$totalAvg = $totalUnits > 0 ? $totalWeightedAvg / $totalUnits : 0;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Student Portal - CSCQC</title>
  <link rel="icon" type="image/png" href="https://cscqcph.com/images/bg/cscqcph.png"/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
<style>
  :root {
    --bg-color: #f1f4f2;
    --text-color: #333333;
    --text-muted: #666666;
    --primary-green: #0e4429;
    --light-green-bg: #d8ebd4;
    --card-bg: #ffffff;
    --border-color: #e5e7eb;
    --success-badge: #14532d;
    --success-badge-bg: #dcfce7;
    --danger-badge-bg: #fde8e8;
    --danger-badge: #9b1c1c;
    --warning-badge-bg: #fef3c7;
    --warning-badge: #92400e;
    --info-badge-bg: #dbeafe;
    --info-badge: #1e40af;
  }
  * { box-sizing: border-box; margin: 0; padding: 0; font-family: "Inter", sans-serif; }
  body { background-color: var(--bg-color); color: var(--text-color); min-height: 100vh; padding: 20px 40px; }

  /* ── Header ── */
  .header-nav {
    background-color: var(--primary-green);
    color: #ffffff;
    padding: 14px 24px;
    border-radius: 8px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    flex-wrap: wrap;
    gap: 16px;
  }
  .brand-info h1 { font-size: 1rem; font-weight: 700; letter-spacing: 0.3px; }
  .brand-info p  { font-size: 0.8rem; opacity: 0.85; margin-top: 2px; }
  .user-controls { display: flex; align-items: center; gap: 20px; flex-wrap: wrap; }
  .student-profile {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.9rem;
    font-weight: 500;
    background-color: rgba(255,255,255,0.1);
    padding: 6px 14px;
    border-radius: 20px;
    text-decoration: none;
    color: inherit;
  }
  .logout-btn {
    background-color: transparent;
    color: #ffffff;
    border: 1px solid rgba(255,255,255,0.4);
    border-radius: 6px;
    padding: 8px 16px;
    font-size: 0.85rem;
    font-weight: 500;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    transition: all 0.2s;
  }
  .logout-btn:hover { background-color: #ffffff; color: var(--primary-green); border-color: #ffffff; }

  /* ── Alerts ── */
  .alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; font-size: 0.85rem; }
  .alert-success { background-color: #d1fae5; color: #065f46; }
  .alert-error   { background-color: #fee2e2; color: #991b1b; }
  .alert-info    { background-color: #dbeafe; color: #1e40af; }

  /* ── Grades panel ── */
  .grades-panel-block {
    background-color: var(--card-bg);
    border-radius: 8px;
    border: 1px solid var(--border-color);
    padding: 24px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.02);
    margin-bottom: 24px;
  }
  .grades-panel-header { margin-bottom: 16px; }
  .grades-panel-header h2 { font-size: 1.1rem; color: var(--primary-green); font-weight: 700; }
  .grades-panel-header p  { font-size: 0.85rem; color: var(--text-muted); margin-top: 4px; }

  /* ── Table ── */
  .table-responsive { width: 100%; overflow-x: auto; margin-bottom: 24px; }
  .grades-table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.85rem; min-width: 650px; }
  .grades-table th {
    color: #4b5563;
    background-color: #f9fafb;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.75rem;
    padding: 12px 16px;
    border-bottom: 1px solid var(--border-color);
  }
  .grades-table td { padding: 14px 16px; color: #1f2937; border-bottom: 1px solid #f3f4f6; }
  .grades-table .th-center  { text-align: center; }
  .grades-table .text-center { text-align: center; }

  /* ── Badges ── */
  .badge-passed { background-color: #dcfce7; color: #14532d; padding: 4px 12px; border-radius: 50px; font-size: 0.75rem; font-weight: 700; }
  .badge-failed { background-color: #fde8e8; color: #9b1c1c; padding: 4px 12px; border-radius: 50px; font-size: 0.75rem; font-weight: 700; }
  .highlight-gwa { color: #0e4429; font-weight: 700; }

  /* ── Summary strip ── */
  .summary-highlights-strip {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-top: 20px;
    padding-top: 16px;
    border-top: 1px solid var(--border-color);
  }
  .metric-item-box { text-align: center; }
  .metric-item-box p    { font-size: 0.8rem; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; }
  .metric-item-box data { font-size: 1.25rem; font-weight: 700; color: var(--primary-green); }

  /* ── Responsive ── */
  @media (max-width: 768px) {
    body { padding: 10px; }
  }
  @media (max-width: 480px) {
    body { padding: 10px; }
    .summary-highlights-strip { grid-template-columns: 1fr 1fr; }
  }
</style>
</head>
<body>
  <header class="header-nav">
    <div class="brand-info">
      <h1>Student — College Of St. Catherine</h1>
      <p>Quezon City</p>
    </div>
    <div class="user-controls">
      <a href="student_settings.php" class="student-profile">
        <i class="fa-solid fa-gear"></i> Settings
      </a>
      <div class="student-profile">
        <i class="fa-solid fa-circle-user"></i>
        <span><?php echo htmlspecialchars($userName ?: ''); ?></span>
      </div>
      <a href="login.php?logout=1" class="logout-btn">
        <i class="fa-solid fa-right-from-bracket"></i> Logout
      </a>
    </div>
  </header>

  <main>
    <?php if (!$canViewGrades): ?>
      <div class="alert alert-error" style="margin-bottom:24px;">
        <i class="fa-solid fa-lock"></i>
        <strong>Grade viewing is currently unavailable</strong> for Academic Year
        <?php echo htmlspecialchars($filterYear); ?> / <?php echo htmlspecialchars($filterSem); ?>.
        The viewing period has not started.
        <a href="student_requests.php" style="color:var(--primary-green);font-weight:600;text-decoration:underline;margin-left:8px;">
          Request Access
        </a>
      </div>
    <?php endif; ?>

    <?php if ($canViewGrades): ?>
    <section class="grades-panel-block">
      <header class="grades-panel-header">
        <h2>View Subject Grades</h2>
        <p>Academic Year: <?php echo htmlspecialchars($filterYear ?: 'N/A'); ?> | <?php echo htmlspecialchars($filterSem ?: 'N/A'); ?></p>
      </header>

      <div class="table-responsive">
        <table class="grades-table">
          <thead>
            <tr>
              <th scope="col">Subject Code</th>
              <th scope="col">Subject Name</th>
              <th scope="col" class="th-center">Units</th>
              <th scope="col" class="th-center">Prelim</th>
              <th scope="col" class="th-center">Midterm</th>
              <th scope="col" class="th-center">Finals</th>
              <th scope="col" class="th-center">Avg. %</th>
              <th scope="col" class="th-center">GWA (Pt)</th>
              <th scope="col" style="text-align:right;padding-right:20px;">Remarks</th>
            </tr>
          </thead>
          <tbody id="student-grades-table-body">
            <?php if (empty($displayRows)): ?>
              <tr>
                <td colspan="9" style="text-align:center;color:#666;padding:24px;">
                  No subjects assigned for School Year
                  <?php echo htmlspecialchars($filterYear ?: ''); ?> /
                  <?php echo htmlspecialchars($filterSem ?: ''); ?>.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($displayRows as $g): ?>
                <tr>
                  <td><?php echo htmlspecialchars($g['subject_code'] ?? ''); ?></td>
                  <td><?php echo htmlspecialchars($g['subject_title'] ?? ''); ?></td>
                  <td class="text-center"><?php echo htmlspecialchars($g['units'] ?? 3); ?></td>
                  <td class="text-center"><?php echo htmlspecialchars($g['prelim']  ?? '--'); ?></td>
                  <td class="text-center"><?php echo htmlspecialchars($g['midterm'] ?? '--'); ?></td>
                  <td class="text-center"><?php echo htmlspecialchars($g['finals']  ?? '--'); ?></td>
                  <td class="text-center"><?php echo htmlspecialchars($g['average'] ?? '--'); ?></td>
                  <td class="text-center highlight-gwa"><?php echo htmlspecialchars($g['gwa'] ?? '--'); ?></td>
                  <td style="text-align:right;padding-right:20px;">
                    <?php if ($g['remarks'] === 'Passed'): ?>
                      <span class="badge-passed">Passed</span>
                    <?php elseif ($g['remarks'] === 'Failed'): ?>
                      <span class="badge-failed">Failed</span>
                    <?php else: ?>
                      <span style="color:#718096;">Pending</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <footer class="summary-highlights-strip">
        <div class="metric-item-box">
          <p>Total Registered Units</p>
          <data value="<?php echo $totalUnits; ?>"><?php echo $totalUnits; ?></data>
        </div>
        <div class="metric-item-box">
          <p>Subjects Passed</p>
          <data value="<?php echo $subjectsPassed; ?>"><?php echo $subjectsPassed; ?></data>
        </div>
        <div class="metric-item-box">
          <p>Total GWA</p>
          <data value="<?php echo number_format($totalGwa, 2); ?>"><?php echo number_format($totalGwa, 2); ?></data>
        </div>
        <div class="metric-item-box">
          <p>Total Average</p>
          <data value="<?php echo number_format($totalAvg, 2); ?>"><?php echo number_format($totalAvg, 2); ?>%</data>
        </div>
      </footer>
    </section>
    <?php endif; ?>
  </main>
</body>
</html>