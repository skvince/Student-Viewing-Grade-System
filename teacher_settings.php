<?php
require_once __DIR__ . '/inc/functions.php';
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'teacher') {
    header('Location: login.php');
    exit;
}

$userId = intval($_SESSION['user_id']);
$userName = '';
$profilePicture = null;
$conn = db_connect();
if ($conn) {
    $stmt = $conn->prepare('SELECT first_name, middle_name, last_name, profile_picture FROM teachers WHERE id = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $row = $res->fetch_assoc()) {
            $userName = make_full_name($row['first_name'], $row['middle_name'], $row['last_name']);
            $profilePicture = $row['profile_picture'] ?? null;
        }
        $stmt->close();
    }
    $conn->close();
}

$notifications = get_teacher_notifications($userId);
$unreadCount = count(array_filter($notifications, fn($n) => !$n['is_read']));

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) { header('Location: ' . $_SERVER['PHP_SELF']); exit; }
    if (isset($_POST['change_password'])) {
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
            $result = change_user_password($userId, 'teacher', $currentPassword, $newPassword);
            if ($result['success']) {
                $message = 'Password changed successfully.';
                $messageType = 'success';
            } else {
                $message = $result['error'];
                $messageType = 'error';
            }
        }
    } elseif (isset($_POST['upload_picture']) && isset($_FILES['profile_picture'])) {
        $result = save_teacher_profile_picture($userId, $_FILES['profile_picture']);
        if ($result['success']) {
            $message = 'Profile picture updated successfully.';
            $messageType = 'success';
            $profilePicture = $result['filename'];
        } else {
            $message = $result['error'];
            $messageType = 'error';
        }
    } elseif (isset($_POST['remove_picture'])) {
        $ok = delete_teacher_profile_picture($userId);
        if ($ok) {
            $message = 'Profile picture removed successfully.';
            $messageType = 'success';
            $profilePicture = null;
            create_notification($userId, 'teacher', 'Profile Picture Removed', 'Your profile picture has been removed.', 'info', 'teacher_settings.php');
        } else {
            $message = 'Failed to remove profile picture.';
            $messageType = 'error';
        }
    }
}

$activeNav = 'settings';
ob_start();
?>
<?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?>"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<div class="settings-grid">
    <section class="panel-block">
        <h2 class="panel-title">Profile Picture</h2>
        <div class="profile-picture-area">
            <?php if ($profilePicture && file_exists(__DIR__ . '/uploads/teachers/' . $profilePicture)): ?>
                <img src="uploads/teachers/<?php echo htmlspecialchars($profilePicture); ?>" alt="Profile" class="profile-picture-preview" />
            <?php else: ?>
                <div class="profile-picture-placeholder"><i class="fa-solid fa-user"></i></div>
            <?php endif; ?>
            <div>
                 <form method="post" enctype="multipart/form-data" style="margin-bottom:8px;">
                     <?php echo csrf_field(); ?>
                     <input type="file" name="profile_picture" accept="image/*" required style="margin-bottom:8px;display:block;" />
                    <button type="submit" name="upload_picture" class="btn-submit"><i class="fa-solid fa-upload"></i> Upload</button>
                </form>
                <?php if ($profilePicture): ?>
                     <form method="post" onsubmit="return confirm('Remove profile picture?');">
                         <?php echo csrf_field(); ?>
                         <button type="submit" name="remove_picture" class="btn-danger"><i class="fa-solid fa-trash"></i> Remove</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <p style="font-size:0.8rem;color:var(--text-muted);">Allowed formats: JPG, PNG, GIF, WebP. Max size: 2MB.</p>
    </section>

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
            <div class="form-buttons-row">
                <button type="submit" name="change_password" class="btn-submit"><i class="fa-solid fa-key"></i> Change Password</button>
                <button type="reset" class="btn-cancel">Reset</button>
            </div>
        </form>
    </section>
</div>

<section class="panel-block">
    <h2 class="panel-title">Recent Notifications</h2>
    <?php if (empty($notifications)): ?>
        <p style="color:var(--text-muted);font-size:0.9rem;">No notifications yet.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr><th>Title</th><th>Message</th><th>Type</th><th>Date</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($notifications as $notif): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($notif['title']); ?></strong></td>
                            <td><?php echo htmlspecialchars($notif['message']); ?></td>
                            <td><span class="status-badge badge-<?php echo htmlspecialchars($notif['type']); ?>"><?php echo htmlspecialchars($notif['type']); ?></span></td>
                            <td><?php echo date('M d, Y h:i A', strtotime($notif['created_at'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php
$settingsContent = ob_get_clean();
$activeNav = 'settings';
require_once __DIR__ . '/inc/teacher_layout.php';
?>
