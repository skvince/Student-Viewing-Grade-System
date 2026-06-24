<?php
require_once __DIR__ . '/inc/functions.php';
$pageTitle = 'Grade Requests';
$activeNav = 'requests';
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

$message = '';
$messageType = '';

revoke_expired_permissions();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) { header('Location: ' . $_SERVER['PHP_SELF']); exit; }
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

    <div class="tab-content" style="display: block;">
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
                       <?php echo csrf_field(); ?>
                       <input type="hidden" name="action" value="approve">
                      <input type="hidden" name="request_id" value="<?php echo (int)$req['id']; ?>">
                      <input type="text" name="admin_response" placeholder="Optional note" style="width:140px;padding:6px;border:1px solid #d1d5db;border-radius:4px;font-size:0.75rem;">
                      <input type="datetime-local" name="expires_at" style="width:160px;padding:6px;border:1px solid #d1d5db;border-radius:4px;font-size:0.75rem;margin-top:4px;" title="Leave empty for permanent">
                      <button type="submit" class="btn btn-approve" style="margin-top:4px;"><i class="fa-solid fa-check"></i> Approve</button>
                    </form>
                     <form method="post" style="display:inline;margin-left:4px;">
                       <?php echo csrf_field(); ?>
                       <input type="hidden" name="action" value="reject">
                      <input type="hidden" name="request_id" value="<?php echo (int)$req['id']; ?>">
                      <button type="submit" class="btn btn-reject"><i class="fa-solid fa-xmark"></i> Reject</button>
                    </form>
                  <?php endif; ?>
                  <?php if (($req['status'] ?? '') !== 'closed' && ($req['status'] ?? '') !== 'rejected'): ?>
                     <form method="post" style="display:inline;margin-left:4px;">
                       <?php echo csrf_field(); ?>
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

<?php
$content = ob_get_clean();
require_once __DIR__ . '/inc/app_layout.php';
?>
 