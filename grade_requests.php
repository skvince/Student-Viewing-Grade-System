<?php
require_once __DIR__ . '/inc/functions.php';
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

$message = '';
$messageType = '';

revoke_expired_permissions();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $requestId = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
    $response = trim($_POST['admin_response'] ?? '');
    $expiresValue = trim($_POST['expires_at'] ?? '');

    $expiresAt = null;
    if ($expiresValue !== '') {
        $dt = DateTime::createFromFormat('Y-m-d\TH:i', $expiresValue);
        $expiresAt = $dt ? $dt->format('Y-m-d H:i:s') : null;
    }

    if ($action === 'approve' && $requestId) {
        $ok = update_grade_change_request_status($requestId, 'approved', $_SESSION['user_id'], $response, $expiresAt);
        $message = $ok ? 'Request approved.' : 'Failed to approve request.';
        $messageType = $ok ? 'success' : 'error';
    } elseif ($action === 'reject' && $requestId) {
        $ok = update_grade_change_request_status($requestId, 'rejected', $_SESSION['user_id'], $response, null);
        $message = $ok ? 'Request rejected.' : 'Failed to reject request.';
        $messageType = $ok ? 'success' : 'error';
    } elseif ($action === 'close' && $requestId) {
        $conn = db_connect();
        if ($conn) {
            $stmt = $conn->prepare("UPDATE grade_change_requests SET status = 'closed', updated_at = NOW() WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('i', $requestId);
                $ok = $stmt->execute();
                $stmt->close();
                $message = $ok ? 'Request closed.' : 'Failed to close request.';
                $messageType = $ok ? 'success' : 'error';
            }
            $conn->close();
        }
    }
}

$requests = [];
$conn = db_connect();
if ($conn) {
    $filterYear = $selectedYear;
    $filterSem = $selectedSem;
    $stmt = $conn->prepare("SELECT cr.id, cr.teacher_id, cr.section_id, cr.subject_id, cr.school_year, cr.semester, cr.grading_period, cr.reason, cr.status, cr.admin_response, cr.expires_at, cr.created_at,
            t.first_name, t.middle_name, t.last_name,
            s.name AS section_name,
            sj.subject_code, sj.title AS subject_title
        FROM grade_change_requests cr
        LEFT JOIN teachers t ON cr.teacher_id = t.id
        LEFT JOIN sections s ON cr.section_id = s.id
        LEFT JOIN subjects sj ON cr.subject_id = sj.id
        WHERE cr.school_year = ? AND cr.semester = ?
        ORDER BY cr.created_at DESC");
    if ($stmt) {
        $stmt->bind_param('ss', $filterYear, $filterSem);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $requests[] = $r;
        $stmt->close();
    }
    $conn->close();
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>CSCQC Portal - Grade Change Requests</title>
  <link rel="icon" type="image/png" href="https://cscqcph.com/images/bg/cscqcph.png" />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
<style>
  :root {
      --bg-color: #f1f4f2;
      --sidebar-bg: #ffffff;
      --text-color: #333333;
      --text-muted: #666666;
      --primary-green: #0e4429;
      --primary-green-hover: #165c39;
      --light-green-bg: #d8ebd4;
      --active-nav-bg: #b9deb3;
      --border-color: #e5e7eb;
      --card-bg: #ffffff;
      --danger-red: #dc2626;
      --edit-blue: #059669;
    }
  * { box-sizing:border-box; margin:0; padding:0; font-family:"Inter",sans-serif; }
  body { background-color:var(--bg-color); color:var(--text-color); display:grid; grid-template-columns:260px 1fr; min-height:100vh; }
  .main-content { padding:40px; min-width:0; }
  .global-term-container {
      display:flex; align-items:center; gap:24px; background-color:#0c3e21;
      padding:16px 24px; border-radius:12px; margin-bottom:24px; box-shadow:0 4px 6px -1px rgba(0,0,0,0.1); flex-wrap:wrap;
    }
  .filter-group { display:flex; align-items:center; gap:12px; }
  .global-term-container label { color:#ffffff; font-size:0.9rem; font-weight:700; display:flex; align-items:center; gap:8px; white-space:nowrap; }
  .global-select { padding:8px 14px; border-radius:6px; border:1px solid transparent; font-size:0.875rem; background-color:#ffffff; color:#1f2937; font-weight:500; cursor:pointer; outline:none; min-width:140px; }
  .global-select:focus { border-color:#22c55e; box-shadow:0 0 0 3px rgba(34,197,94,0.2); }
  .view-title { font-size:1.25rem; color:var(--primary-green); font-weight:600; margin-bottom:4px; text-transform:uppercase; letter-spacing:0.5px; }
  .view-subtitle { font-size:0.85rem; color:var(--text-muted); margin-bottom:24px; }
  .panel-block { background-color:var(--card-bg); border-radius:8px; padding:24px; margin-bottom:24px; box-shadow:0 1px 3px rgba(0,0,0,0.05); }
  .block-title { font-size:1rem; color:var(--primary-green); font-weight:600; margin-bottom:12px; }
  .table-responsive { width:100%; overflow-x:auto; border:1px solid var(--border-color); border-radius:6px; background-color:#ffffff; }
  table { width:100%; border-collapse:collapse; text-align:left; font-size:0.85rem; }
  th { color:#4b5563; background-color:#f9fafb; font-weight:600; text-transform:uppercase; font-size:0.75rem; padding:12px 16px; border-bottom:1px solid var(--border-color); }
  td { padding:14px 16px; color:#1f2937; border-bottom:1px solid #f3f4f6; vertical-align:middle; }
  .status-badge { font-size:0.72rem; font-weight:800; text-transform:uppercase; padding:4px 12px; border-radius:50px; display:inline-block; letter-spacing:0.5px; }
  .badge-pending { background-color:#fef3c7; color:#92400e; }
  .badge-approved { background-color:#d1fae5; color:#065f46; }
  .badge-rejected { background-color:#fee2e2; color:#991b1b; }
  .badge-closed { background-color:#e5e7eb; color:#374151; }
  .btn { padding:8px 16px; border-radius:6px; font-size:0.85rem; font-weight:600; cursor:pointer; border:none; color:white; display:inline-flex; align-items:center; gap:6px; }
  .btn-approve { background-color:#059669; }
  .btn-reject { background-color:#dc2626; }
  .btn-close { background-color:#6b7280; }
  .btn-modal { background-color:var(--primary-green); }
  .sidebar {
    background-color:var(--sidebar-bg); border-right:1px solid var(--border-color); display:flex; flex-direction:column; justify-content:space-between; padding:20px 0; height:100vh; position:sticky; top:0; z-index:90;
  }
  .nav-menu a { display:flex; align-items:center; padding:12px 24px; color:var(--text-muted); font-size:0.9rem; font-weight:500; cursor:pointer; margin-bottom:4px; border-left:4px solid transparent; text-decoration:none; }
  .nav-menu a i { margin-right:12px; font-size:1.1rem; width:20px; text-align:center; }
  .nav-menu a.active, .nav-menu a:hover { background-color:var(--active-nav-bg); color:var(--primary-green); font-weight:600; }
  .logout-btn { margin:0 20px; padding:12px; background-color:var(--primary-green); color:white; border:none; border-radius:6px; font-weight:500; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:8px; text-decoration:none; font-size:0.9rem; }
  .alert { padding:12px 16px; border-radius:6px; margin-bottom:16px; font-size:0.85rem; }
  .alert-success { background-color:#d1fae5; color:#065f46; }
  .alert-error { background-color:#fee2e2; color:#991b1b; }
  .modal-backdrop { position:fixed; inset:0; display:none; align-items:center; justify-content:center; background:rgba(15,23,42,0.4); backdrop-filter:blur(10px); z-index:999; padding:24px; }
  .modal-card { background-color:#ffffff; border:1px solid #d1d5db; border-radius:18px; padding:24px; box-shadow:0 8px 24px rgba(15,23,42,0.08); position:relative; max-width:480px; width:100%; }
  .modal-close { position:absolute; top:18px; right:18px; background:transparent; border:none; color:#6b7280; font-size:1.1rem; cursor:pointer; padding:8px; }
  .form-group { margin:10px 5px 20px; }
  .form-group label { display:block; font-size:0.85rem; color:var(--text-muted); margin-bottom:8px; font-weight:500; }
  .form-control { width:100%; padding:10px 12px; border:1px solid #d1d5db; border-radius:6px; background-color:#fff; font-size:0.9rem; color:#1f2937; outline:none; }
  select.form-control { appearance:none; padding-right:36px; }
  .form-control:focus { border-color:var(--primary-green); box-shadow:0 0 0 3px rgba(14,68,41,0.15); }
  @media (max-width:1024px) { body { grid-template-columns:1fr; } }
</style>
</head>
<body>
  <aside class="sidebar">
    <div>
      <div class="brand" style="display:flex;align-items:center;padding:0 24px;margin-bottom:30px;">
        <img src="https://cscqcph.com/images/bg/cscqcph.png" alt="CSCQC" style="width:32px;height:32px;object-fit:contain;font-size:1.8rem;color:var(--primary-green);margin-right:12px;">
        <div class="brand-text"><h2 style="font-size:1rem;font-weight:700;color:#111827;">Admin Panel</h2><p style="font-size:0.75rem;color:var(--text-muted);">CSCQC</p></div>
      </div>
      <nav class="nav-menu">
        <a href="admin.php"><i class="fa-solid fa-table-cells-large"></i> Dashboard</a>
        <a href="teachers.php"><i class="fa-solid fa-users"></i> Teachers</a>
        <a href="section.php"><i class="fa-solid fa-book-open"></i> Section &amp; Dept</a>
        <a href="students.php"><i class="fa-solid fa-user-graduate"></i> Students</a>
        <a href="assign.php"><i class="fa-solid fa-gear"></i> Assign Module</a>
        <a href="deadline_manager.php"><i class="fa-solid fa-calendar-days"></i> Grade Deadlines</a>
        <a href="grade_requests.php" class="active"><i class="fa-solid fa-clipboard-list"></i> Grade Requests</a>
      </nav>
    </div>
    <a href="login.php?logout=1" class="logout-btn"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
  </aside>
  <div class="main-content">
    <form method="get" action="" style="margin-bottom:0;">
      <input type="hidden" name="global_year" id="hidden-global-year" value="<?php echo htmlspecialchars($selectedYear); ?>">
      <input type="hidden" name="global_sem" id="hidden-global-sem" value="<?php echo htmlspecialchars($selectedSem); ?>">
      <div class="global-term-container">
        <div class="filter-group">
          <label><i class="fa-solid fa-calendar-days"></i> Academic Year:</label>
          <select id="global-filter-year" class="global-select">
            <option value="2025-2026" <?php echo $selectedYear==='2025-2026'?'selected':''; ?>>2025–2026</option>
            <option value="2026-2027" <?php echo $selectedYear==='2026-2027'?'selected':''; ?>>2026–2027</option>
          </select>
        </div>
        <div class="filter-group">
          <label><i class="fa-solid fa-clock"></i> Semester:</label>
          <select id="global-filter-sem" class="global-select">
            <option value="1st Semester" <?php echo $selectedSem==='1st Semester'?'selected':''; ?>>1st Semester</option>
            <option value="2nd Semester" <?php echo $selectedSem==='2nd Semester'?'selected':''; ?>>2nd Semester</option>
            <option value="Summer" <?php echo $selectedSem==='Summer'?'selected':''; ?>>Summer</option>
          </select>
        </div>
      </div>
    </form>

    <?php if ($message): ?>
      <div class="alert alert-<?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <h1 class="view-title">Grade Change Requests</h1>
    <p class="view-subtitle">Review and approve or reject teacher requests for <?php echo htmlspecialchars($selectedYear . ' ' . $selectedSem); ?></p>

    <div class="panel-block">
      <div class="block-title">All Requests</div>
      <div class="table-responsive">
        <table>
          <thead>
            <tr>
              <th>ID</th>
              <th>Teacher</th>
              <th>Section</th>
              <th>Subject</th>
              <th>Period</th>
              <th>Reason</th>
              <th>Status</th>
              <th>Submitted</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($requests)): ?>
              <tr><td colspan="9" style="text-align:center;padding:24px;">No requests found.</td></tr>
            <?php else: foreach ($requests as $req): ?>
              <tr>
                <td><?php echo (int)$req['id']; ?></td>
                <td><?php echo htmlspecialchars(make_full_name($req['first_name'] ?? '', $req['middle_name'] ?? '', $req['last_name'] ?? '')); ?></td>
                <td><?php echo htmlspecialchars($req['section_name'] ?? ''); ?></td>
                <td><?php echo htmlspecialchars(($req['subject_code'] ?? '') . ' - ' . ($req['subject_title'] ?? '')); ?></td>
                <td><?php echo htmlspecialchars(ucfirst($req['grading_period'] ?? '')); ?></td>
                <td><?php echo htmlspecialchars($req['reason'] ?? ''); ?></td>
                <td><span class="status-badge badge-<?php echo htmlspecialchars($req['status'] ?? 'pending'); ?>"><?php echo htmlspecialchars(ucfirst($req['status'] ?? 'pending')); ?></span></td>
                <td><?php echo date('M d, Y h:i A', strtotime($req['created_at'])); ?></td>
                <td>
                  <?php if (($req['status'] ?? '') === 'pending'): ?>
                    <form method="post" style="display:inline;">
                      <input type="hidden" name="action" value="approve">
                      <input type="hidden" name="request_id" value="<?php echo (int)$req['id']; ?>">
                      <input type="text" name="admin_response" placeholder="Optional note" style="width:140px;padding:6px;border:1px solid #d1d5db;border-radius:4px;font-size:0.75rem;">
                      <input type="datetime-local" name="expires_at" style="width:160px;padding:6px;border:1px solid #d1d5db;border-radius:4px;font-size:0.75rem;margin-top:4px;" title="Leave empty for permanent">
                      <button type="submit" class="btn btn-approve" style="margin-top:4px;"><i class="fa-solid fa-check"></i> Approve</button>
                    </form>
                    <form method="post" style="display:inline;margin-left:4px;">
                      <input type="hidden" name="action" value="reject">
                      <input type="hidden" name="request_id" value="<?php echo (int)$req['id']; ?>">
                      <button type="submit" class="btn btn-reject"><i class="fa-solid fa-xmark"></i> Reject</button>
                    </form>
                  <?php endif; ?>
                  <?php if (($req['status'] ?? '') !== 'closed' && ($req['status'] ?? '') !== 'rejected'): ?>
                    <form method="post" style="display:inline;margin-left:4px;">
                      <input type="hidden" name="action" value="close">
                      <input type="hidden" name="request_id" value="<?php echo (int)$req['id']; ?>">
                      <button type="submit" class="btn btn-close"><i class="fa-solid fa-ban"></i> Close</button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
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

    document.addEventListener('DOMContentLoaded', function() {
      if (yearSelect) yearSelect.addEventListener('change', syncGlobalFilter);
      if (semSelect) semSelect.addEventListener('change', syncGlobalFilter);
    });
  </script>
</body>
</html>

