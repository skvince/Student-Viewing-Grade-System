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


$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    if (!verify_csrf()) { header('Location: ' . $_SERVER['PHP_SELF']); exit; }
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (!$currentPassword || !$newPassword || !$confirmPassword) {
        $message = 'All password fields are required.';
        $messageType = 'error';
    } elseif ($newPassword !== $confirmPassword) {
        $message = 'New password and confirmation do not match.';
        $messageType = 'error';
    } elseif (strlen($newPassword) < 6) {
        $message = 'New password must be at least 6 characters long.';
        $messageType = 'error';
    } else {
        $result = change_user_password($userId, 'student', $currentPassword, $newPassword);
        if ($result['success']) {
            $message = 'Password changed successfully.';
            $messageType = 'success';
            create_notification($userId, 'student', 'Password Changed', 'Your password was changed successfully.', 'success', 'student_settings.php');
        } else {
            $message = $result['error'];
            $messageType = 'error';
        }
    }
}
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
  .panel-block { background-color:var(--card-bg); border-radius:8px; border:1px solid var(--border-color); padding:24px; box-shadow:0 1px 3px rgba(0,0,0,0.02); margin-bottom:24px; max-width:720px; }
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
  .nav-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:24px; max-width:720px; }
.nav-card { background:var(--card-bg); border:1px solid var(--border-color); border-radius:8px; padding:16px; text-decoration:none; color:inherit; display:flex; align-items:center; gap:12px; transition:transform .15s ease, box-shadow .15s ease; }
.nav-card:hover { transform:translateY(-2px); box-shadow:0 4px 6px rgba(0,0,0,0.05); background:var(--light-green-bg); }
.nav-card.active { background:var(--light-green-bg); border-color:var(--primary-green); }
.nav-card i { font-size:1.5rem; color:var(--primary-green); width:24px; text-align:center; }
.nav-card span { font-weight:600; font-size:0.9rem; }
  .table-responsive { width:100%; overflow-x:auto; }
  table { width:100%; border-collapse:collapse; text-align:left; font-size:0.85rem; min-width:500px; }
  th { color:#4b5563; background-color:#f9fafb; font-weight:600; text-transform:uppercase; font-size:0.75rem; padding:12px 16px; border-bottom:1px solid var(--border-color); }
  td { padding:14px 16px; color:#1f2937; border-bottom:1px solid #f3f4f6; }
  .status-badge { font-size:0.72rem; font-weight:800; text-transform:uppercase; padding:4px 12px; border-radius:50px; display:inline-block; letter-spacing:0.5px; }
  .badge-pending { background-color:#fef3c7; color:#92400e; }
  .badge-approved { background-color:#d1fae5; color:#065f46; }
  .badge-rejected { background-color:#fee2e2; color:#991b1b; }
  .badge-expired { background-color:#e5e7eb; color:#374151; }
  .badge-info { background-color:#dbeafe; color:#1e40af; }
  @media (max-width:768px) { .nav-grid { grid-template-columns:1fr; } .form-control { width:100% !important; } body { padding:10px; } }
  @media (max-width:480px) { .nav-grid { grid-template-columns:1fr; } .form-control { width:100% !important; } body { padding:10px; } }
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
        <a href="student_requests.php" class="nav-card">
          <i class="fa-solid fa-clipboard-check"></i>
          <span>Grade Access Requests</span>
        </a>
        <a href="student_settings.php" class="nav-card active">
          <i class="fa-solid fa-user-pen"></i>
          <span>Change Password</span>
        </a>
      </div>

    <?php if ($message): ?>
      <div class="alert alert-<?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>

    <section class="panel-block">
      <h2 class="panel-title">Change Password</h2>
       <form method="post">
         <?php echo csrf_field(); ?>
         <div class="form-group">
          <label for="current_password">Current Password</label>
          <input type="password" id="current_password" name="current_password" class="form-control" required />
        </div>
        <div class="form-group">
          <label for="new_password">New Password</label>
          <input type="password" id="new_password" name="new_password" class="form-control" required minlength="6" />
        </div>
        <div class="form-group">
          <label for="confirm_password">Confirm New Password</label>
          <input type="password" id="confirm_password" name="confirm_password" class="form-control" required minlength="6" />
        </div>
        <div style="display:flex;gap:12px;align-items:center;">
          <button type="submit" name="change_password" class="btn-submit"><i class="fa-solid fa-key"></i> Change Password</button>
          <button type="reset" class="btn-cancel">Reset</button>
        </div>
      </form>
    </section>
  </main>
</body>
</html>
