<?php
require_once __DIR__ . '/inc/functions.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
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

revoke_expired_access_grants();

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_request'])) {
    if (!verify_csrf()) { header('Location: ' . $_SERVER['PHP_SELF']); exit; }
    $reqYear = trim($_POST['school_year'] ?? '');
    $reqSem = normalize_semester($_POST['semester'] ?? '');
    $reason = trim($_POST['reason'] ?? '');

    if (!$reqYear || !$reqSem) {
        $message = 'Academic Year and Semester are required.';
        $messageType = 'error';
    } elseif (is_grade_viewing_active($reqYear, $reqSem)) {
        $message = 'Grade viewing is currently active for the selected term. No request needed.';
        $messageType = 'error';
    } elseif (check_student_grade_access($userId, $reqYear, $reqSem)) {
        $message = 'You already have access to view grades for the selected term.';
        $messageType = 'error';
    } else {
        $requestId = create_grade_access_request($userId, $reqYear, $reqSem, $reason ?: null);
        if ($requestId) {
            $message = 'Grade access request submitted successfully. You can track its status below.';
            $messageType = 'success';
        } else {
            $message = 'Failed to submit request. Please try again.';
            $messageType = 'error';
        }
    }
}

$requests = get_student_access_requests($userId);
$term = get_global_term();
$filterYear = $term['year'];
$filterSem  = $term['semester'];

$defaultYearOptions = get_term_options()['years'];
$allTerms = get_available_terms($userId, 'student');
$yearOptions = $allTerms['years'] ?: $defaultYearOptions;
$yearOptions = array_values(array_unique(array_merge($yearOptions, $defaultYearOptions)));

$validPairs = get_student_valid_terms($userId);
$semOptions = get_semesters_for_year($validPairs, $filterYear);
if (empty($semOptions)) $semOptions = ['1st Semester', '2nd Semester', 'Summer'];
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
  * { box-sizing:border-box; margin:0; padding:0; font-family:"Inter",sans-serif; }
  body { background-color:var(--bg-color); color:var(--text-color); min-height:100vh; padding:20px 40px; }
  .header-nav { background-color:var(--primary-green); color:#ffffff; padding:14px 24px; border-radius:8px; display:flex; justify-content:space-between; align-items:center; margin-bottom:24px; box-shadow:0 1px 3px rgba(0,0,0,0.1); flex-wrap:wrap; gap:16px; }
  .brand-info h1 { font-size:1rem; font-weight:700; letter-spacing:0.3px; }
  .brand-info p { font-size:0.8rem; opacity:0.85; margin-top:2px; }
  .user-controls { display:flex; align-items:center; gap:20px; flex-wrap:wrap; }
  .student-profile { display:flex; align-items:center; gap:8px; font-size:0.9rem; font-weight:500; background-color:rgba(255,255,255,0.1); padding:6px 14px; border-radius:20px; text-decoration:none; color:inherit; }
  .student-profile .badge { background:#ef4444; color:#fff; border-radius:50%; padding:2px 6px; font-size:0.7rem; font-weight:700; margin-left:4px; }
  .logout-btn { background-color:transparent; color:#ffffff; border:1px solid rgba(255,255,255,0.4); border-radius:6px; padding:8px 16px; font-size:0.85rem; font-weight:500; cursor:pointer; display:flex; align-items:center; gap:8px; text-decoration:none; transition:all 0.2s; }
  .logout-btn:hover { background-color:#ffffff; color:var(--primary-green); border-color:#ffffff; }
  .filter-card { background-color:var(--card-bg); border-radius:8px; border:1px solid var(--border-color); padding:20px; margin-bottom:24px; }
  .filter-card legend { font-size:0.85rem; font-weight:600; color:var(--text-muted); margin-bottom:12px; }
  .filter-group-selectors { display:flex; gap:16px; flex-wrap:wrap; }
  .form-control { padding:8px 36px 8px 12px; border:1px solid #d1d5db; border-radius:6px; background-color:#fff; font-size:0.85rem; color:#1f2937; appearance:none; background-image:url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%234b5563' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e"); background-repeat:no-repeat; background-position:right 12px center; background-size:14px; cursor:pointer; min-width:180px; }
  .panel-block { background-color:var(--card-bg); border-radius:8px; border:1px solid var(--border-color); padding:24px; box-shadow:0 1px 3px rgba(0,0,0,0.02); margin-bottom:24px; }
  .panel-title { font-size:1rem; color:var(--primary-green); font-weight:600; margin-bottom:12px; }
  .form-group { margin:10px 5px 20px; }
  .form-group label { display:block; font-size:0.85rem; color:var(--text-muted); margin-bottom:8px; font-weight:500; }
  .form-control { width:100%; padding:10px 12px; border:1px solid #d1d5db; border-radius:6px; background-color:#fff; font-size:0.9rem; color:#1f2937; outline:none; }
  .form-control:focus { border-color:var(--primary-green); box-shadow:0 0 0 3px rgba(14,68,41,0.15); }
  .btn-submit { background-color:var(--primary-green); color:white; border:none; padding:10px 20px; border-radius:6px; font-size:0.9rem; font-weight:500; cursor:pointer; display:flex; align-items:center; gap:6px; }
  .btn-submit:hover { background-color:#165c39; }
  .btn-cancel { background-color:#e5e7eb; color:#374151; border:none; padding:10px 20px; border-radius:6px; font-size:0.9rem; font-weight:500; cursor:pointer; }
  .alert { padding:12px 16px; border-radius:6px; margin-bottom:16px; font-size:0.85rem; }
  .alert-success { background-color:#d1fae5; color:#065f46; }
  .alert-error { background-color:#fee2e2; color:#991b1b; }
  .alert-info { background-color:#dbeafe; color:#1e40af; }
  .table-responsive { width:100%; overflow-x:auto; margin-bottom:24px; }
  table { width:100%; border-collapse:collapse; text-align:left; font-size:0.85rem; min-width:650px; }
  th { color:#4b5563; background-color:#f9fafb; font-weight:600; text-transform:uppercase; font-size:0.75rem; padding:12px 16px; border-bottom:1px solid var(--border-color); }
  td { padding:14px 16px; color:#1f2937; border-bottom:1px solid #f3f4f6; }
  .status-badge { font-size:0.72rem; font-weight:800; text-transform:uppercase; padding:4px 12px; border-radius:50px; display:inline-block; letter-spacing:0.5px; }
  .badge-pending { background-color:#fef3c7; color:#92400e; }
  .badge-approved { background-color:#d1fae5; color:#065f46; }
  .badge-rejected { background-color:#fee2e2; color:#991b1b; }
  .badge-expired { background-color:#e5e7eb; color:#374151; }
  .badge-info { background-color:#dbeafe; color:#1e40af; }
  .btn-add { background-color:var(--primary-green); color:white; border:none; padding:8px 16px; border-radius:6px; font-size:0.85rem; font-weight:500; cursor:pointer; display:flex; align-items:center; gap:6px; }
  .btn-add:hover { background-color:#165c39; }
  .nav-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:24px; max-width:720px; }
  .nav-card { background:var(--card-bg); border:1px solid var(--border-color); border-radius:8px; padding:16px; text-decoration:none; color:inherit; display:flex; align-items:center; gap:12px; transition:transform .15s ease, box-shadow .15s ease; }
  .nav-card:hover { transform:translateY(-2px); box-shadow:0 4px 6px rgba(0,0,0,0.05); background:var(--light-green-bg); }
  .nav-card.active { background:var(--light-green-bg); border-color:var(--primary-green); }
  .nav-card i { font-size:1.5rem; color:var(--primary-green); width:24px; text-align:center; }
  .nav-card span { font-weight:600; font-size:0.9rem; }
  @media (max-width:768px) { .nav-grid { grid-template-columns:1fr; } .form-control { width:100% !important; } body { padding:10px; } }
  @media (max-width:480px) { .summary-highlights-strip { grid-template-columns:1fr; } .filter-group-selectors { flex-direction:column; } .form-control { width:100% !important; } body { padding:10px; } .modal-card { padding:16px; } }
  .modal-backdrop { display:none; position:fixed; inset:0; align-items:center; justify-content:center; background:rgba(15,23,42,0.4); backdrop-filter:blur(10px); z-index:999; padding:24px; }
  .modal-card { background:#ffffff; border:1px solid #d1d5db; border-radius:18px; padding:24px; box-shadow:0 8px 24px rgba(15,23,42,0.08); position:relative; max-width:480px; width:100%; }
  .modal-close { position:absolute; top:18px; right:18px; background:transparent; border:none; color:#6b7280; font-size:1.1rem; cursor:pointer; padding:8px; }
  @media (max-width:480px) { .modal-card { padding:16px; } }
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
    <div class="nav-grid">
      <a href="student_module.php" class="nav-card">
        <i class="fa-solid fa-graduation-cap"></i>
        <span>My Grades</span>
      </a>
      <a href="student_requests.php" class="nav-card active">
        <i class="fa-solid fa-clipboard-check"></i>
        <span>Grade Access Requests</span>
      </a>
      <a href="student_settings.php" class="nav-card">
        <i class="fa-solid fa-user-pen"></i>
        <span>Change Password</span>
      </a>
    </div>

    <?php if ($message): ?>
      <div class="alert alert-<?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <section class="panel-block">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
        <div>
          <h2 class="panel-title">My Grade Access Requests</h2>
          <p style="font-size:0.8rem;color:var(--text-muted);">Track the status of your grade viewing requests</p>
        </div>
        <button type="button" class="btn-add" id="btn-new-request"><i class="fa-solid fa-plus"></i> New Request</button>
      </div>
      <div class="table-responsive">
        <table>
          <thead>
            <tr><th>ID</th><th>Academic Year</th><th>Semester</th><th>Reason</th><th>Status</th><th>Admin Response</th><th>Submitted</th></tr>
          </thead>
          <tbody>
            <?php if (empty($requests)): ?>
              <tr><td colspan="7" style="text-align:center;padding:24px;">No requests found. Click "New Request" to request grade access.</td></tr>
            <?php else: foreach ($requests as $req): ?>
              <tr>
                <td><?php echo (int)$req['id']; ?></td>
                <td><?php echo htmlspecialchars($req['school_year']); ?></td>
                <td><?php echo htmlspecialchars($req['semester']); ?></td>
                <td><?php echo htmlspecialchars($req['reason'] ?? '--'); ?></td>
                <td><span class="status-badge badge-<?php echo htmlspecialchars($req['status'] ?? 'pending'); ?>"><?php echo htmlspecialchars(ucfirst($req['status'] ?? 'pending')); ?></span></td>
                <td><?php echo htmlspecialchars($req['admin_response'] ?? '--'); ?></td>
                <td><?php echo date('M d, Y h:i A', strtotime($req['created_at'])); ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </main>

  <div id="request-modal-backdrop" class="modal-backdrop">
    <div class="modal-card">
      <button type="button" class="modal-close" id="btn-close-request-modal"><i class="fa-solid fa-xmark"></i></button>
      <h3 style="margin-bottom:16px;">Request Grade Access</h3>
      <form method="post" id="request-form">
        <?php echo csrf_field(); ?>
        <div class="form-group">
          <label for="req-school-year">Academic Year</label>
          <select name="school_year" id="req-school-year" class="form-control" required>
            <?php foreach ($yearOptions as $y): ?>
              <option value="<?php echo htmlspecialchars($y); ?>" <?php echo $filterYear===$y?'selected':''; ?>><?php echo htmlspecialchars($y); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label for="req-semester">Semester</label>
          <select name="semester" id="req-semester" class="form-control" required>
            <?php foreach ($semOptions as $s): ?>
              <option value="<?php echo htmlspecialchars($s); ?>" <?php echo $filterSem===$s?'selected':''; ?>><?php echo htmlspecialchars($s); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label for="req-reason">Reason (Optional)</label>
          <textarea name="reason" id="req-reason" class="form-control" rows="4" placeholder="Please explain why you need access..."></textarea>
        </div>
        <div style="display:flex;gap:12px;align-items:center;">
          <button type="submit" name="submit_request" class="btn-submit"><i class="fa-solid fa-paper-plane"></i> Submit Request</button>
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

    function openRequestModal() {
      if (requestModalBackdrop) requestModalBackdrop.style.display = 'flex';
    }

    function closeRequestModal() {
      if (requestModalBackdrop) requestModalBackdrop.style.display = 'none';
      const form = document.getElementById('request-form');
      if (form) form.reset();
    }

    document.addEventListener('DOMContentLoaded', function() {
      if (btnNewRequest) btnNewRequest.addEventListener('click', openRequestModal);
      if (btnCloseRequestModal) btnCloseRequestModal.addEventListener('click', closeRequestModal);
      if (btnCancelRequest) btnCancelRequest.addEventListener('click', closeRequestModal);
      if (requestModalBackdrop) {
        requestModalBackdrop.addEventListener('click', function(e) {
          if (e.target === requestModalBackdrop) closeRequestModal();
        });
      }
    });
  </script>
</body>
</html>
