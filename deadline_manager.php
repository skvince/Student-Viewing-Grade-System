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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $gradingPeriod = $_POST['grading_period'] ?? '';
    $deadline = $_POST['deadline'] ?? '';
    $status = $_POST['status'] ?? 'open';
    $extendedUntil = $_POST['extended_until'] ?? null;

    if ($action === 'save_deadline' && $gradingPeriod && $deadline) {
        $deadlineDt = DateTime::createFromFormat('Y-m-d\TH:i', $deadline);
        $deadlineFormatted = $deadlineDt ? $deadlineDt->format('Y-m-d H:i:s') : '';

        if ($status === 'open' || $status === 'closed') {
            $extendedUntil = null;
        }

        $result = set_deadline($selectedYear, $selectedSem, $gradingPeriod, $deadlineFormatted, $status, $extendedUntil);
        if ($result) {
            audit_log('set_deadline_' . $gradingPeriod, $_SESSION['user_id'], $gradingPeriod);
            $message = ucfirst($gradingPeriod) . ' deadline saved successfully.';
            $messageType = 'success';
        } else {
            $message = 'Failed to save deadline.';
            $messageType = 'error';
        }
    } elseif ($action === 'reopen_deadline' && $gradingPeriod) {
        $futureDate = (new DateTime())->modify('+7 days')->format('Y-m-d H:i:s');
        $result = set_deadline($selectedYear, $selectedSem, $gradingPeriod, $futureDate, 'open', null);
        if ($result) {
            audit_log('reopen_deadline_' . $gradingPeriod, $_SESSION['user_id'], $gradingPeriod);
            $message = ucfirst($gradingPeriod) . ' deadline reopened successfully.';
            $messageType = 'success';
        } else {
            $message = 'Failed to reopen deadline.';
            $messageType = 'error';
        }
    } elseif ($action === 'extend_deadline' && $gradingPeriod && $extendedUntil) {
        $existing = get_deadline($selectedYear, $selectedSem, $gradingPeriod);
        $currentDeadline = $existing['deadline'] ?? (new DateTime())->format('Y-m-d H:i:s');

        $extendedDt = DateTime::createFromFormat('Y-m-d\TH:i', $extendedUntil);
        $extendedFormatted = $extendedDt ? $extendedDt->format('Y-m-d H:i:s') : null;

        if ($extendedFormatted) {
            $result = set_deadline($selectedYear, $selectedSem, $gradingPeriod, $currentDeadline, 'extended', $extendedFormatted);
            if ($result) {
                audit_log('extend_deadline_' . $gradingPeriod, $_SESSION['user_id'], $gradingPeriod);
                $message = ucfirst($gradingPeriod) . ' deadline extended successfully.';
                $messageType = 'success';
            } else {
                $message = 'Failed to extend deadline.';
                $messageType = 'error';
            }
        }
    }
}

$deadlines = get_all_deadlines($selectedYear, $selectedSem);
$deadlinesByPeriod = [];
foreach ($deadlines as $d) {
    $deadlinesByPeriod[$d['grading_period']] = $d;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>CSCQC Portal - Grade Deadlines</title>
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

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
      font-family: "Inter", sans-serif;
    }

    body {
      background-color: var(--bg-color);
      color: var(--text-color);
      display: grid;
      grid-template-columns: 260px 1fr;
      min-height: 100vh;
    }

    .mobile-header {
      display: none;
      background-color: var(--sidebar-bg);
      border-bottom: 1px solid var(--border-color);
      padding: 12px 20px;
      align-items: center;
      justify-content: space-between;
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      z-index: 100;
      height: 60px;
    }

    .menu-toggle-btn {
      font-size: 1.3rem;
      color: var(--primary-green);
      cursor: pointer;
      padding: 4px 8px;
    }

    #sidebar-toggle {
      display: none;
    }

    .sidebar {
      background-color: var(--sidebar-bg);
      border-right: 1px solid var(--border-color);
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      padding: 20px 0;
      height: 100vh;
      position: sticky;
      top: 0;
      z-index: 90;
    }

    .brand {
      display: flex;
      align-items: center;
      padding: 0 24px;
      margin-bottom: 30px;
    }

    .brand i {
      font-size: 1.8rem;
      color: var(--primary-green);
      margin-right: 12px;
    }

    .brand-text h2 {
      font-size: 1rem;
      font-weight: 700;
      color: #111827;
    }

    .brand-text p {
      font-size: 0.75rem;
      color: var(--text-muted);
    }

    .nav-menu {
      list-style: none;
      flex-grow: 1;
    }

    .nav-menu a {
      display: flex;
      align-items: center;
      padding: 12px 24px;
      color: var(--text-muted);
      font-size: 0.9rem;
      font-weight: 500;
      cursor: pointer;
      transition: all 0.2s;
      margin-bottom: 4px;
      border-left: 4px solid transparent;
      text-decoration: none;
    }

    .nav-menu a i {
      margin-right: 12px;
      font-size: 1.1rem;
      width: 20px;
      text-align: center;
    }

    .nav-menu a:hover,
    .nav-menu a.active {
      background-color: var(--active-nav-bg);
      color: var(--primary-green);
      font-weight: 600;
    }

    .logout-btn {
      margin: 0 20px;
      padding: 12px;
      background-color: var(--primary-green);
      color: white;
      border: none;
      border-radius: 6px;
      font-weight: 500;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      text-decoration: none;
      font-size: 0.9rem;
      transition: background 0.2s;
    }

    .logout-btn:hover {
      background-color: var(--primary-green-hover);
    }

    .main-content {
      padding: 40px;
      min-width: 0;
    }

    .global-term-container {
      display: flex;
      align-items: center;
      gap: 24px;
      background-color: #0c3e21;
      padding: 16px 24px;
      border-radius: 12px;
      margin-bottom: 24px;
      box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
      flex-wrap: wrap;
    }

    .filter-group {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .global-term-container label {
      color: #ffffff;
      font-size: 0.9rem;
      font-weight: 700;
      display: flex;
      align-items: center;
      gap: 8px;
      white-space: nowrap;
    }

    .global-term-container label i {
      font-size: 1rem;
    }

    .global-select {
      padding: 8px 14px;
      border-radius: 6px;
      border: 1px solid transparent;
      font-size: 0.875rem;
      background-color: #ffffff;
      color: #1f2937;
      font-weight: 500;
      cursor: pointer;
      outline: none;
      min-width: 140px;
      transition: border-color 0.2s, box-shadow 0.2s;
    }

    .global-select:focus {
      border-color: #22c55e;
      box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.2);
    }

    .view-title {
      font-size: 1.25rem;
      color: var(--primary-green);
      font-weight: 600;
      margin-bottom: 4px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .view-subtitle {
      font-size: 0.85rem;
      color: var(--text-muted);
      margin-bottom: 24px;
    }

    .panel-block {
      background-color: var(--card-bg);
      border-radius: 8px;
      padding: 24px;
      margin-bottom: 24px;
      box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    }

    .block-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 16px;
      gap: 16px;
      flex-wrap: wrap;
    }

    .block-title {
      font-size: 1rem;
      color: var(--primary-green);
      font-weight: 600;
    }

    .form-group {
      margin: 10px 5px 20px;
    }

    .form-group label {
      display: block;
      font-size: 0.85rem;
      color: var(--text-muted);
      margin-bottom: 8px;
      font-weight: 500;
    }

    .form-control {
      width: 100%;
      padding: 10px 12px;
      border: 1px solid #d1d5db;
      border-radius: 6px;
      background-color: #fff;
      font-size: 0.9rem;
      color: #1f2937;
      outline: none;
    }

    select.form-control {
      appearance: none;
      background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%234b5563' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
      background-repeat: no-repeat;
      background-position: right 12px center;
      background-size: 16px;
      padding-right: 36px;
    }

    .form-control:focus {
      border-color: var(--primary-green);
      box-shadow: 0 0 0 3px rgba(14, 68, 41, 0.15);
    }

    .btn-submit {
      background-color: var(--primary-green);
      color: white;
      border: none;
      margin-bottom: 8px;
      margin-left: 7px;
      padding: 10px 20px;
      border-radius: 6px;
      font-size: 0.9rem;
      font-weight: 500;
      cursor: pointer;
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .btn-submit:hover {
      background-color: var(--primary-green-hover);
    }

    .btn-cancel {
      background-color: #e5e7eb;
      color: #374151;
      border: none;
      padding: 10px 20px;
      border-radius: 6px;
      font-size: 0.9rem;
      font-weight: 500;
      cursor: pointer;
    }

    .btn-action {
      background-color: var(--primary-green);
      color: white;
      border: none;
      padding: 6px 12px;
      border-radius: 4px;
      font-size: 0.8rem;
      font-weight: 500;
      cursor: pointer;
      margin: 2px;
    }

    .btn-action:hover {
      background-color: var(--primary-green-hover);
    }

    .btn-reopen {
      background-color: #059669;
    }

    .btn-extend {
      background-color: #2563eb;
    }

    .btn-reopen:hover {
      background-color: #047857;
    }

    .btn-extend:hover {
      background-color: #1d4ed8;
    }

    .grid-3col {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 20px;
    }

    .deadline-form-group {
      background-color: var(--card-bg);
      border: 1px solid var(--border-color);
      border-radius: 8px;
      padding: 16px;
    }

    .deadline-form-group h3 {
      font-size: 1rem;
      color: var(--primary-green);
      margin-bottom: 12px;
    }

    .table-responsive {
      width: 100%;
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
      border: 1px solid var(--border-color);
      border-radius: 6px;
      background-color: #ffffff;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      text-align: left;
      font-size: 0.85rem;
      min-width: 650px;
    }

    th {
      color: #4b5563;
      background-color: #f9fafb;
      font-weight: 600;
      text-transform: uppercase;
      font-size: 0.75rem;
      padding: 12px 16px;
      border-bottom: 1px solid var(--border-color);
    }

    td {
      padding: 14px 16px;
      color: #1f2937;
      border-bottom: 1px solid #f3f4f6;
      vertical-align: middle;
    }

    .status-badge {
      display: inline-block;
      padding: 4px 12px;
      border-radius: 9999px;
      font-size: 0.75rem;
      font-weight: 600;
      text-transform: uppercase;
    }

    .status-open {
      background-color: #d1fae5;
      color: #065f46;
    }

    .status-closed {
      background-color: #fee2e2;
      color: #991b1b;
    }

    .status-extended {
      background-color: #dbeafe;
      color: #1e40af;
    }

    .modal-backdrop {
      position: fixed;
      inset: 0;
      display: none;
      align-items: center;
      justify-content: center;
      background: rgba(15, 23, 42, 0.4);
      backdrop-filter: blur(10px);
      z-index: 999;
      padding: 24px;
    }

    .modal-card {
      background-color: #ffffff;
      border: 1px solid #d1d5db;
      border-radius: 18px;
      padding: 24px;
      margin-bottom: 24px;
      box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08);
      position: relative;
      max-width: 400px;
      width: 100%;
    }

    .modal-close {
      position: absolute;
      top: 18px;
      right: 18px;
      background: transparent;
      border: none;
      color: #6b7280;
      font-size: 1.1rem;
      cursor: pointer;
      padding: 8px;
      line-height: 1;
    }

    .alert-success {
      background-color: #d1fae5;
      color: #065f46;
      padding: 12px 16px;
      border-radius: 6px;
      margin-bottom: 16px;
      font-size: 0.85rem;
    }

    .alert-error {
      background-color: #fee2e2;
      color: #991b1b;
      padding: 12px 16px;
      border-radius: 6px;
      margin-bottom: 16px;
      font-size: 0.85rem;
    }

    @media (max-width: 1024px) {
      body {
        grid-template-columns: 1fr;
        padding-top: 60px;
      }

      .mobile-header {
        display: flex;
      }

      .sidebar {
        position: fixed;
        top: 60px;
        left: 0;
        bottom: 0;
        transform: translateX(-100%);
        width: 260px;
        height: calc(100vh - 60px);
        box-shadow: 4px 0 10px rgba(0, 0, 0, 0.1);
        transition: transform 0.3s ease;
      }

      #sidebar-toggle:checked~.sidebar {
        transform: translateX(0);
      }

      .main-content {
        padding: 20px;
      }

      .grid-3col {
        grid-template-columns: 1fr;
        gap: 0;
      }
    }

    @media (max-width: 768px) {
      .header-actions {
        width: 100%;
      }

      .search-wrapper,
      .search-input {
        width: 100% !important;
      }
    }

    @media (max-width: 480px) {
      .panel-block {
        padding: 16px;
      }
    }
  </style>
</head>
<body>
  <input type="checkbox" id="sidebar-toggle" />
  <header class="mobile-header">
    <div class="brand" style="padding:0;margin:0;"><img src="https://cscqcph.com/images/bg/cscqcph.png" alt="CSCQC" style="width:32px;height:32px;object-fit:contain;font-size:1.5rem;color:var(--primary-green);margin-right:10px;"><div class="brand-text"><h2 style="font-size:.9rem;">Admin Panel</h2></div></div>
    <label for="sidebar-toggle" class="menu-toggle-btn"><i class="fa-solid fa-bars"></i></label>
  </header>
  <aside class="sidebar">
    <div>
      <div class="brand"><img src="https://cscqcph.com/images/bg/cscqcph.png" alt="CSCQC" style="width:32px;height:32px;object-fit:contain;margin-right:12px;"><div class="brand-text"><h2>Admin Panel</h2><p>CSCQC</p></div></div>
      <nav class="nav-menu" aria-label="Main Navigation">
        <a href="admin.php"><i class="fa-solid fa-table-cells-large"></i> Dashboard</a>
        <a href="teachers.php"><i class="fa-solid fa-users"></i> Teachers</a>
        <a href="section.php"><i class="fa-solid fa-book-open"></i> Section &amp; Dept</a>
        <a href="students.php"><i class="fa-solid fa-user-graduate"></i> Students</a>
        <a href="assign.php"><i class="fa-solid fa-gear"></i> Assign Module</a>
        <a href="deadline_manager.php" class="active"><i class="fa-solid fa-calendar-days"></i> Grade Deadlines</a>
        <a href="grade_requests.php"><i class="fa-solid fa-clipboard-list"></i> Grade  Requests</a>
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
          <label for="global-filter-year">
            <i class="fa-solid fa-calendar-days" aria-hidden="true"></i>
            Academic Year:
          </label>
          <select id="global-filter-year" class="global-select">
            <option value="2025-2026" <?php echo $selectedYear==='2025-2026'?'selected':''; ?>>2025-2026</option>
            <option value="2026-2027" <?php echo $selectedYear==='2026-2027'?'selected':''; ?>>2026-2027</option>
          </select>
        </div>

        <div class="filter-group">
          <label for="global-filter-sem">
            <i class="fa-solid fa-clock" aria-hidden="true"></i> Semester:
          </label>
          <select id="global-filter-sem" class="global-select">
            <option value="1st Semester" <?php echo $selectedSem==='1st Semester'?'selected':''; ?>>1st Semester</option>
            <option value="2nd Semester" <?php echo $selectedSem==='2nd Semester'?'selected':''; ?>>2nd Semester</option>
            <option value="Summer" <?php echo $selectedSem==='Summer'?'selected':''; ?>>Summer</option>
          </select>
        </div>
      </div>
    </form>

    <?php if ($message): ?>
      <div class="alert-<?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <h1 class="view-title">Grade Deadlines</h1>
    <p class="view-subtitle">Manage submission deadlines for each grading period</p>

    <div class="panel-block">
      <div class="block-header">
        <h2 class="block-title">Set Deadlines</h2>
      </div>
      <form method="post" id="deadline-form">
        <div class="grid-3col">
          <?php foreach (['prelim', 'midterm', 'finals'] as $period): ?>
            <?php $deadline = $deadlinesByPeriod[$period] ?? null; ?>
            <?php $status = get_deadline_status($selectedYear, $selectedSem, $period); ?>
            <?php $deadlineValue = $deadline ? date('Y-m-d\TH:i', strtotime($deadline['deadline'])) : ''; ?>
            <div class="deadline-form-group">
              <h3><?php echo ucfirst($period); ?></h3>
              <div class="form-group">
                <label for="deadline_<?php echo $period; ?>">Deadline</label>
                <input type="datetime-local" id="deadline_<?php echo $period; ?>" name="deadline_<?php echo $period; ?>" class="form-control" value="<?php echo htmlspecialchars($deadlineValue); ?>" />
              </div>
              <div class="form-group">
                <label for="status_<?php echo $period; ?>">Status</label>
                <select id="status_<?php echo $period; ?>" name="status_<?php echo $period; ?>" class="form-control">
                  <option value="open" <?php echo $status==='open'?'selected':''; ?>>Open</option>
                  <option value="closed" <?php echo $status==='closed'?'selected':''; ?>>Closed</option>
                  <option value="extended" <?php echo $status==='extended'?'selected':''; ?>>Extended</option>
                </select>
              </div>
              <button type="button" class="btn-submit btn-save-deadline" data-period="<?php echo $period; ?>">
                <i class="fa-solid fa-floppy-disk"></i> Save
              </button>
            </div>
          <?php endforeach; ?>
        </div>
      </form>
    </div>

    <div class="panel-block">
      <div class="block-header">
        <h2 class="block-title">Current Deadlines</h2>
      </div>
      <div class="table-responsive">
        <table>
          <thead>
            <tr>
              <th>Period</th>
              <th>Deadline</th>
              <th>Status</th>
              <th>Extended Until</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach (['prelim', 'midterm', 'finals'] as $period): ?>
              <?php $deadline = $deadlinesByPeriod[$period] ?? null; ?>
              <?php $status = get_deadline_status($selectedYear, $selectedSem, $period); ?>
              <tr>
                <td><?php echo ucfirst($period); ?></td>
                <td>
                  <?php if ($deadline): ?>
                    <?php echo date('M d, Y h:i A', strtotime($deadline['deadline'])); ?>
                  <?php else: ?>
                    <span style="color:#6b7280;">Not set</span>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="status-badge status-<?php echo $status; ?>"><?php echo $status; ?></span>
                </td>
                <td>
                  <?php if ($deadline && $deadline['extended_until']): ?>
                    <?php echo date('M d, Y h:i A', strtotime($deadline['extended_until'])); ?>
                  <?php else: ?>
                    <span style="color:#6b7280;">-</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($status === 'closed'): ?>
                    <button type="button" class="btn-action btn-reopen btn-reopen-deadline" data-period="<?php echo $period; ?>">
                      <i class="fa-solid fa-unlock"></i> Reopen
                    </button>
                  <?php endif; ?>
                  <?php if ($status !== 'closed'): ?>
                    <button type="button" class="btn-action btn-extend btn-extend-deadline" data-period="<?php echo $period; ?>">
                      <i class="fa-solid fa-clock"></i> Extend
                    </button>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div id="extend-modal-backdrop" class="modal-backdrop">
    <div class="modal-card">
      <button type="button" class="modal-close" id="btn-close-extend-modal">
        <i class="fa-solid fa-xmark"></i>
      </button>
      <h3 style="margin-bottom:16px;">Extend Deadline</h3>
      <form method="post" id="extend-form">
        <input type="hidden" name="action" value="extend_deadline" />
        <input type="hidden" name="grading_period" id="extend-grading-period" value="" />
        <div class="form-group">
          <label for="extend-deadline">Extended Until</label>
          <input type="datetime-local" id="extend-deadline" name="extended_until" class="form-control" required />
        </div>
        <div class="form-buttons-row">
          <button type="submit" class="btn-submit">
            <i class="fa-solid fa-floppy-disk"></i> Save
          </button>
          <button type="button" class="btn-cancel" id="btn-cancel-extend">Cancel</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    const yearSelect = document.getElementById('global-filter-year');
    const semSelect = document.getElementById('global-filter-sem');
    const extendModalBackdrop = document.getElementById('extend-modal-backdrop');
    const btnCloseExtendModal = document.getElementById('btn-close-extend-modal');
    const btnCancelExtend = document.getElementById('btn-cancel-extend');

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

    function saveDeadline(period) {
      const deadline = document.getElementById('deadline_' + period).value;
      const status = document.getElementById('status_' + period).value;

      const form = document.createElement('form');
      form.method = 'post';
      form.style.display = 'none';

      const actionInput = document.createElement('input');
      actionInput.type = 'hidden';
      actionInput.name = 'action';
      actionInput.value = 'save_deadline';
      form.appendChild(actionInput);

      const periodInput = document.createElement('input');
      periodInput.type = 'hidden';
      periodInput.name = 'grading_period';
      periodInput.value = period;
      form.appendChild(periodInput);

      const deadlineInput = document.createElement('input');
      deadlineInput.type = 'hidden';
      deadlineInput.name = 'deadline';
      deadlineInput.value = deadline;
      form.appendChild(deadlineInput);

      const statusInput = document.createElement('input');
      statusInput.type = 'hidden';
      statusInput.name = 'status';
      statusInput.value = status;
      form.appendChild(statusInput);

      document.body.appendChild(form);
      form.submit();
    }

    function reopenDeadline(period) {
      const form = document.createElement('form');
      form.method = 'post';
      form.style.display = 'none';

      const actionInput = document.createElement('input');
      actionInput.type = 'hidden';
      actionInput.name = 'action';
      actionInput.value = 'reopen_deadline';
      form.appendChild(actionInput);

      const periodInput = document.createElement('input');
      periodInput.type = 'hidden';
      periodInput.name = 'grading_period';
      periodInput.value = period;
      form.appendChild(periodInput);

      document.body.appendChild(form);
      form.submit();
    }

    function openExtendModal(period) {
      document.getElementById('extend-grading-period').value = period;
      extendModalBackdrop.style.display = 'flex';
    }

    function closeExtendModal() {
      extendModalBackdrop.style.display = 'none';
      document.getElementById('extend-form').reset();
    }

    document.addEventListener('DOMContentLoaded', function() {
      if (yearSelect) yearSelect.addEventListener('change', syncGlobalFilter);
      if (semSelect) semSelect.addEventListener('change', syncGlobalFilter);

      document.querySelectorAll('.btn-save-deadline').forEach(btn => {
        btn.addEventListener('click', function() {
          saveDeadline(this.getAttribute('data-period'));
        });
      });

      document.querySelectorAll('.btn-reopen-deadline').forEach(btn => {
        btn.addEventListener('click', function() {
          reopenDeadline(this.getAttribute('data-period'));
        });
      });

      document.querySelectorAll('.btn-extend-deadline').forEach(btn => {
        btn.addEventListener('click', function() {
          openExtendModal(this.getAttribute('data-period'));
        });
      });

      if (btnCloseExtendModal) {
        btnCloseExtendModal.addEventListener('click', closeExtendModal);
      }
      if (btnCancelExtend) {
        btnCancelExtend.addEventListener('click', closeExtendModal);
      }
      if (extendModalBackdrop) {
        extendModalBackdrop.addEventListener('click', function(e) {
          if (e.target === extendModalBackdrop) closeExtendModal();
        });
      }
    });
  </script>
</body>
</html>

