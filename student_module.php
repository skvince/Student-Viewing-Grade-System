<?php require_once __DIR__ . '/inc/functions.php'; ?>
<?php
session_start();
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'student') {
    if (isset($_GET['student_id'])) {
        $_SESSION['user_role'] = 'student';
        $_SESSION['user_id'] = intval($_GET['student_id']);
    } else {
        header('Location: login.php');
        exit;
    }
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

<<<<<<< HEAD
$filterYear = $_GET['academic_year'] ?? '';
$filterSem = $_GET['semester'] ?? '';
=======
$term = get_global_term();
$filterYear = $term['year'];
$filterSem  = $term['semester'];
>>>>>>> fb2d24f95a6588be3c3b58f632cfbc2919f0b160

// Keep student term consistent with the global filter.
$_SESSION['student_filter_year'] = $filterYear;
$_SESSION['student_filter_sem']  = $filterSem;

<<<<<<< HEAD
// Auto-detect current year/sem if not provided
if (!$filterYear && !empty($yearOptions)) $filterYear = $yearOptions[0];
elseif (!$filterYear) $filterYear = '2025-2026';
if (!$filterSem && !empty($semOptions)) $filterSem = $semOptions[0];
elseif (!$filterSem) $filterSem = '1st Semester';

$grades = get_student_grades($userId, $filterYear, $filterSem);
$assignedSubjects = get_student_assigned_subjects($userId, $filterYear, $filterSem);
=======

$validPairs = get_student_valid_terms($userId);
$allTerms = get_available_terms($userId, 'student');
$defaultYearOptions = get_term_options()['years'];
$yearOptions = $allTerms['years'] ?: $defaultYearOptions;
$yearOptions = array_values(array_unique(array_merge($yearOptions, $defaultYearOptions)));

$selectedYear = $filterYear;
$selectedSem  = $filterSem;

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
>>>>>>> fb2d24f95a6588be3c3b58f632cfbc2919f0b160

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
        'subject_code' => $subjectCode,
        'subject_title' => $subj['title'] ?? ($gradeMatch['subject_title'] ?? ''),
        'units' => $subj['units'] ?? ($gradeMatch['units'] ?? 3),
        'prelim' => $gradeMatch['prelim'] ?? null,
        'midterm' => $gradeMatch['midterm'] ?? null,
        'finals' => $gradeMatch['finals'] ?? null,
        'average' => $gradeMatch['average'] ?? null,
        'gwa' => $gradeMatch['gwa'] ?? null,
        'remarks' => $gradeMatch['remarks'] ?? null,
    ];
    $seenSubjectCodes[$subjectCode] = true;
}

foreach ($grades as $g) {
    $subjectCode = $g['subject_code'] ?? '';
    if ($subjectCode === '' || isset($seenSubjectCodes[$subjectCode])) continue;

    $displayRows[] = [
        'subject_code' => $subjectCode,
        'subject_title' => $g['subject_title'] ?? '',
        'units' => $g['units'] ?? 3,
        'prelim' => $g['prelim'] ?? null,
        'midterm' => $g['midterm'] ?? null,
        'finals' => $g['finals'] ?? null,
        'average' => $g['average'] ?? null,
        'gwa' => $g['gwa'] ?? null,
        'remarks' => $g['remarks'] ?? null,
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
  :root { --bg-color:#f1f4f2; --text-color:#333333; --text-muted:#666666; --primary-green:#0e4429; --light-green-bg:#d8ebd4; --card-bg:#ffffff; --border-color:#e5e7eb; --success-badge:#14532d; --success-badge-bg:#dcfce7; }
  * { box-sizing:border-box; margin:0; padding:0; font-family:"Inter",sans-serif; }
  body { background-color:var(--bg-color); color:var(--text-color); min-height:100vh; padding:20px 40px; }
  .header-nav { background-color:var(--primary-green); color:#ffffff; padding:14px 24px; border-radius:8px; display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; box-shadow:0 1px 3px rgba(0,0,0,0.1); flex-wrap:wrap; gap:16px; }
  .brand-info h1 { font-size:1rem; font-weight:700; letter-spacing:0.3px; }
  .brand-info p { font-size:0.8rem; opacity:0.85; margin-top:2px; }
  .user-controls { display:flex; align-items:center; gap:20px; flex-wrap:wrap; }
  .student-profile { display:flex; align-items:center; gap:8px; font-size:0.9rem; font-weight:500; background-color:rgba(255,255,255,0.1); padding:6px 14px; border-radius:20px; }
  .logout-btn { background-color:transparent; color:#ffffff; border:1px solid rgba(255,255,255,0.4); border-radius:6px; padding:8px 16px; font-size:0.85rem; font-weight:500; cursor:pointer; display:flex; align-items:center; gap:8px; text-decoration:none; transition:all 0.2s; }
  .logout-btn:hover { background-color:#ffffff; color:var(--primary-green); border-color:#ffffff; }
  .filter-card { background-color:var(--card-bg); border-radius:8px; border:1px solid var(--border-color); padding:20px; margin-bottom:24px; }
  .filter-card legend { font-size:0.85rem; font-weight:600; color:var(--text-muted); margin-bottom:12px; }
  .filter-group-selectors { display:flex; gap:16px; flex-wrap:wrap; }
  .form-control { padding:8px 36px 8px 12px; border:1px solid #d1d5db; border-radius:6px; background-color:#fff; font-size:0.85rem; color:#1f2937; appearance:none; background-image:url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%234b5563' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e"); background-repeat:no-repeat; background-position:right 12px center; background-size:14px; cursor:pointer; min-width:180px; }
  .grades-panel-block { background-color:var(--card-bg); border-radius:8px; border:1px solid var(--border-color); padding:24px; box-shadow:0 1px 3px rgba(0,0,0,0.02); margin-bottom:24px; }
  .grades-panel-header { margin-bottom:20px; }
  .grades-panel-header h2 { font-size:1rem; color:var(--primary-green); font-weight:600; }
  .grades-panel-header p { font-size:0.75rem; color:var(--text-muted); margin-top:2px; }
  .table-responsive { width:100%; overflow-x:auto; margin-bottom:24px; }
  .grades-table { width:100%; border-collapse:collapse; text-align:left; font-size:0.85rem; min-width:850px; }
  .grades-table th { color:#8492a6; font-weight:600; text-transform:uppercase; font-size:0.75rem; padding:12px 8px; border-bottom:1px solid var(--border-color); }
  .grades-table td { padding:14px 8px; color:#1f2937; border-bottom:1px solid #f3f4f6; }
  .text-center { text-align:center; }
  .th-center { text-align:center !important; }
  .highlight-gwa { color:var(--primary-green); font-weight:700; }
  .badge-passed { background-color:var(--success-badge-bg); color:var(--success-badge); padding:4px 12px; border-radius:12px; font-size:0.75rem; font-weight:600; display:inline-block; }
  .badge-failed { background-color:#fde8e8; color:#9b1c1c; padding:4px 12px; border-radius:12px; font-size:0.75rem; font-weight:600; display:inline-block; }
  .summary-highlights-strip { background-color:var(--light-green-bg); border-radius:6px; padding:16px 24px; display:grid; grid-template-columns:repeat(4, 1fr); gap:16px; }
  .metric-item-box p { font-size:0.75rem; color:#4b5563; font-weight:500; margin-bottom:4px; }
  .metric-item-box data { font-size:1.35rem; font-weight:700; color:var(--primary-green); display:block; }
@media (max-width:768px) { .summary-highlights-strip { grid-template-columns:repeat(2, 1fr); } }
   @media (max-width:480px) { .summary-highlights-strip { grid-template-columns:1fr; } }
</style>
</head>
<body>

  <header class="header-nav">
    <div class="brand-info">
      <h1>Student — College Of St. Catherine</h1>
      <p>Quezon City</p>
    </div>
    <div class="user-controls">
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
    <form action="#" method="GET" class="filter-card" id="term-filter-form">
      <fieldset style="border:none;">
        <legend>Select Year & Semester</legend>
        <div class="filter-group-selectors">
<<<<<<< HEAD
          <select name="academic_year" id="filter-year" class="form-control">
=======
          <select name="global_year" class="form-control" onchange="this.form.submit()">
>>>>>>> fb2d24f95a6588be3c3b58f632cfbc2919f0b160
            <?php foreach ($yearOptions as $y): ?>
              <option value="<?php echo htmlspecialchars($y); ?>" <?php echo $filterYear===$y?'selected':''; ?>><?php echo htmlspecialchars($y); ?></option>
            <?php endforeach; ?>
          </select>
<<<<<<< HEAD
          <select name="semester" id="filter-semester" class="form-control">
=======
          <select name="global_sem" class="form-control" onchange="this.form.submit()">
>>>>>>> fb2d24f95a6588be3c3b58f632cfbc2919f0b160
            <?php foreach ($semOptions as $s): ?>
              <option value="<?php echo htmlspecialchars($s); ?>" <?php echo $filterSem===$s?'selected':''; ?>><?php echo htmlspecialchars($s); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </fieldset>
    </form>

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
              <tr><td colspan="9" style="text-align:center;color:#666;padding:24px;">No subjects assigned for School Year <?php echo htmlspecialchars($filterYear ?: ''); ?> / <?php echo htmlspecialchars($filterSem ?: ''); ?>.</td></tr>
            <?php else: ?>
              <?php foreach ($displayRows as $g): ?>
                <tr>
                  <td><?php echo htmlspecialchars($g['subject_code'] ?? ''); ?></td>
                  <td><?php echo htmlspecialchars($g['subject_title'] ?? ''); ?></td>
                  <td class="text-center"><?php echo htmlspecialchars($g['units'] ?? 3); ?></td>
                  <td class="text-center"><?php echo htmlspecialchars($g['prelim'] ?? '--'); ?></td>
                  <td class="text-center"><?php echo htmlspecialchars($g['midterm'] ?? '--'); ?></td>
                  <td class="text-center"><?php echo htmlspecialchars($g['finals'] ?? '--'); ?></td>
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
          <data value="0" id="summary-total-units"><?php echo $totalUnits; ?></data>
        </div>
        <div class="metric-item-box">
          <p>Subjects Passed</p>
          <data value="0" id="summary-subjects-passed"><?php echo $subjectsPassed; ?></data>
        </div>
        <div class="metric-item-box">
          <p>Total GWA</p>
          <data value="0" id="summary-total-gwa"><?php echo number_format($totalGwa, 2); ?></data>
        </div>
        <div class="metric-item-box">
          <p>Total Average</p>
          <data value="0" id="summary-total-avg"><?php echo number_format($totalAvg, 2); ?>%</data>
        </div>
      </footer>
</section>
   </main>

<script>
// Variables
const filterYear = document.getElementById('filter-year');
const filterSemester = document.getElementById('filter-semester');

// Handlers
function applyFilters() {
    const form = document.getElementById('term-filter-form');
    form.submit();
}

// Listeners
filterYear?.addEventListener('change', applyFilters);
filterSemester?.addEventListener('change', applyFilters);
</script>

</body>
</html>
