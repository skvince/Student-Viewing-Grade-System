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

$requestType = $_GET['tab'] ?? 'change';

revoke_expired_permissions();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) { header('Location: ' . $_SERVER['PHP_SELF'] . '?tab=' . urlencode($requestType)); exit; }
    $action = $_POST['action'] ?? '';
    $requestId = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
    $response = trim($_POST['admin_response'] ?? '');
    $startRaw = trim($_POST['access_start'] ?? '');
    $endRaw = trim($_POST['access_end'] ?? '');

    $accessStart = null;
    if ($startRaw !== '') {
        $dt = DateTime::createFromFormat('Y-m-d\TH:i', $startRaw);
        $accessStart = $dt ? $dt->format('Y-m-d H:i:s') : null;
    }
    $accessEnd = null;
    if ($endRaw !== '') {
        $dt = DateTime::createFromFormat('Y-m-d\TH:i', $endRaw);
        $accessEnd = $dt ? $dt->format('Y-m-d H:i:s') : null;
    }

    if ($action === 'approve' && $requestId) {
        $ok = update_grade_change_request_status($requestId, 'approved', $_SESSION['user_id'], $response, $accessStart, $accessEnd);
        $flashPopupType = $ok ? 'success' : 'error';
        $flashPopupTitle = $ok ? 'Success' : 'Error';
        $flashPopupMessage = $ok ? 'Request approved.' : 'Failed to approve request.';
    } elseif ($action === 'reject' && $requestId) {
        $ok = update_grade_change_request_status($requestId, 'rejected', $_SESSION['user_id'], $response, null);
        $flashPopupType = $ok ? 'success' : 'error';
        $flashPopupTitle = $ok ? 'Success' : 'Error';
        $flashPopupMessage = $ok ? 'Request rejected.' : 'Failed to reject request.';
    } elseif ($action === 'close' && $requestId) {
        $conn = db_connect();
        if ($conn) {
            $stmt = $conn->prepare("UPDATE grade_change_requests SET status = 'closed', updated_at = NOW() WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('i', $requestId);
                $ok = $stmt->execute();
                $stmt->close();
                $flashPopupType = $ok ? 'success' : 'error';
                $flashPopupTitle = $ok ? 'Success' : 'Error';
                $flashPopupMessage = $ok ? 'Request closed.' : 'Failed to close request.';
            }
            $conn->close();
        }
    } elseif ($action === 'approve_access' && $requestId) {
        $conn = db_connect();
        if ($conn) {
            $accessStart = null;
            $accessEnd = null;
            if ($startRaw !== '') {
                $accessStart = (new DateTime($startRaw))->format('Y-m-d H:i:s');
            }
            if ($endRaw !== '') {
                $accessEnd = (new DateTime($endRaw))->format('Y-m-d H:i:s');
            }
            $stmt = $conn->prepare("UPDATE grade_access_requests SET status = 'approved', admin_response = ?, admin_id = ?, access_start = ?, access_end = ?, updated_at = NOW() WHERE id = ?");
            if ($stmt) {
                $adminResponse = $response !== '' ? $response : null;
                $stmt->bind_param('sissi', $adminResponse, $_SESSION['user_id'], $accessStart, $accessEnd, $requestId);
                $ok = $stmt->execute();
                $stmt->close();
                $flashPopupType = $ok ? 'success' : 'error';
                $flashPopupTitle = $ok ? 'Success' : 'Error';
                $flashPopupMessage = $ok ? 'Student access request approved.' : 'Failed to approve access request.';
                if ($ok) {
                    create_notification(0, 'student', 'Grade Access Approved', 'Your grade access request has been approved.', 'success', 'student_module.php');
                }
            }
            $conn->close();
        }
    } elseif ($action === 'reject_access' && $requestId) {
        $conn = db_connect();
        if ($conn) {
            $stmt = $conn->prepare("UPDATE grade_access_requests SET status = 'rejected', admin_response = ?, admin_id = ?, updated_at = NOW() WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('ssi', $response, $_SESSION['user_id'], $requestId);
                $ok = $stmt->execute();
                $stmt->close();
                $flashPopupType = $ok ? 'success' : 'error';
                $flashPopupTitle = $ok ? 'Success' : 'Error';
                $flashPopupMessage = $ok ? 'Student access request rejected.' : 'Failed to reject access request.';
            }
            $conn->close();
        }
    }
}

$changeRequests = [];
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
        while ($r = $res->fetch_assoc()) $changeRequests[] = $r;
        $stmt->close();
    }
    $conn->close();
}

$accessRequests = [];
$conn = db_connect();
if ($conn) {
    $filterYear = $selectedYear;
    $filterSem = $selectedSem;
    $stmt = $conn->prepare("SELECT gar.id, gar.student_id, gar.school_year, gar.semester, gar.reason, gar.status, gar.admin_response, gar.access_start, gar.access_end, gar.created_at,
            s.first_name, s.middle_name, s.last_name, s.student_id AS student_id_num
        FROM grade_access_requests gar
        LEFT JOIN students s ON gar.student_id = s.id
        WHERE gar.school_year = ? AND gar.semester = ?
        ORDER BY gar.created_at DESC");
    if ($stmt) {
        $stmt->bind_param('ss', $filterYear, $filterSem);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $accessRequests[] = $r;
        $stmt->close();
    }
    $conn->close();
}
?>

<div class="tab-content" style="display: block;">
  <h1 class="view-title">Grade Requests</h1>
  <p class="view-subtitle">Manage grade change and access requests for <?php echo htmlspecialchars($selectedYear . ' ' . $selectedSem); ?></p>

  <div class="panel-block">
    <div class="block-header">
      <h2 class="block-title">Request Types</h2>
    </div>

    <div style="display:flex;gap:8px;margin-bottom:16px;">
      <a href="?tab=change&global_year=<?= urlencode($selectedYear) ?>&global_sem=<?= urlencode($selectedSem) ?>"
         class="btn-action <?= $requestType === 'change' ? 'active' : '' ?>"
         style="background:<?= $requestType === 'change' ? '#0e4429' : '#e5e7eb' ?>;color:<?= $requestType === 'change' ? '#fff' : '#374151' ?>;">
        <i class="fa-solid fa-pen-to-square"></i> Grade Change Requests
      </a>
      <a href="?tab=access&global_year=<?= urlencode($selectedYear) ?>&global_sem=<?= urlencode($selectedSem) ?>"
         class="btn-action <?= $requestType === 'access' ? 'active' : '' ?>"
         style="background:<?= $requestType === 'access' ? '#0e4429' : '#e5e7eb' ?>;color:<?= $requestType === 'access' ? '#fff' : '#374151' ?>;">
        <i class="fa-solid fa-eye"></i> Grade Access Requests
      </a>
    </div>

    <?php if ($requestType === 'change'): ?>
    <div class="block-title">Grade Change Requests</div>
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
          <?php if (empty($changeRequests)): ?>
            <tr><td colspan="9" style="text-align:center;padding:24px;">No grade change requests found.</td></tr>
          <?php else: foreach ($changeRequests as $req): ?>
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
                  <form method="post" style="display:inline;" class="approve-change-form" data-request-id="<?php echo (int)$req['id']; ?>">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="request_id" value="<?php echo (int)$req['id']; ?>">
                    <input type="text" name="admin_response" placeholder="Optional note" style="width:120px;padding:6px;border:1px solid #d1d5db;border-radius:4px;font-size:0.75rem;">
                    <div style="display:flex; flex-direction:column; gap:4px; margin-top:4px;">
                      <input type="datetime-local" name="access_start" style="width:150px;padding:6px;border:1px solid #d1d5db;border-radius:4px;font-size:0.75rem;" title="Start date for editing permission">
                      <input type="datetime-local" name="access_end" style="width:150px;padding:6px;border:1px solid #d1d5db;border-radius:4px;font-size:0.75rem;" title="End date for editing permission">
                    </div>
                    <button type="submit" class="btn btn-approve" style="margin-top:4px;"><i class="fa-solid fa-check"></i> Approve</button>
                  </form>
                  <form method="post" style="display:inline;margin-left:4px;" class="reject-change-form" data-request-id="<?php echo (int)$req['id']; ?>">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="request_id" value="<?php echo (int)$req['id']; ?>">
                    <button type="submit" class="btn btn-reject"><i class="fa-solid fa-xmark"></i> Reject</button>
                  </form>
                <?php endif; ?>
                <?php if (($req['status'] ?? '') !== 'closed' && ($req['status'] ?? '') !== 'rejected'): ?>
                  <form method="post" style="display:inline;margin-left:4px;" class="close-change-form">
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

    <?php elseif ($requestType === 'access'): ?>
    <div class="block-title">Grade Access Requests</div>
    <div class="table-responsive">
      <table>
        <thead>
          <tr>
            <th>ID</th>
            <th>Student ID</th>
            <th>Student Name</th>
            <th>Reason</th>
            <th>Status</th>
            <th>Admin Response</th>
            <th>Access Period</th>
            <th>Submitted</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($accessRequests)): ?>
            <tr><td colspan="9" style="text-align:center;padding:24px;">No grade access requests found.</td></tr>
          <?php else: foreach ($accessRequests as $req): ?>
            <tr>
              <td><?php echo (int)$req['id']; ?></td>
              <td><?php echo htmlspecialchars($req['student_id_num'] ?? ''); ?></td>
              <td><?php echo htmlspecialchars(make_full_name($req['first_name'] ?? '', $req['middle_name'] ?? '', $req['last_name'] ?? '')); ?></td>
              <td><?php echo htmlspecialchars($req['reason'] ?? '--'); ?></td>
              <td><span class="status-badge badge-<?php echo htmlspecialchars($req['status'] ?? 'pending'); ?>"><?php echo htmlspecialchars(ucfirst($req['status'] ?? 'pending')); ?></span></td>
              <td><?php echo htmlspecialchars($req['admin_response'] ?? '--'); ?></td>
              <td>
                <?php if ($req['access_start'] && $req['access_end']): ?>
                  <?php echo date('M d, Y', strtotime($req['access_start'])) . ' - ' . date('M d, Y', strtotime($req['access_end'])); ?>
                <?php else: ?>
                  <span style="color:#6b7280;">--</span>
                <?php endif; ?>
              </td>
              <td><?php echo date('M d, Y h:i A', strtotime($req['created_at'])); ?></td>
              <td>
                <?php if (($req['status'] ?? '') === 'pending'): ?>
                  <form method="post" style="display:inline;" class="approve-access-form" data-request-id="<?php echo (int)$req['id']; ?>">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="approve_access">
                    <input type="hidden" name="request_id" value="<?php echo (int)$req['id']; ?>">
                    <input type="text" name="admin_response" placeholder="Optional note" style="width:120px;padding:6px;border:1px solid #d1d5db;border-radius:4px;font-size:0.75rem;">
                    <input type="datetime-local" name="expires_at" style="width:150px;padding:6px;border:1px solid #d1d5db;border-radius:4px;font-size:0.75rem;margin-top:4px;" title="Access end date (optional)">
                    <button type="submit" class="btn btn-approve" style="margin-top:4px;"><i class="fa-solid fa-check"></i> Approve</button>
                  </form>
                  <form method="post" style="display:inline;margin-left:4px;" class="reject-access-form" data-request-id="<?php echo (int)$req['id']; ?>">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="reject_access">
                    <input type="hidden" name="request_id" value="<?php echo (int)$req['id']; ?>">
                    <button type="submit" class="btn btn-reject"><i class="fa-solid fa-xmark"></i> Reject</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.approve-change-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var self = this;
            Swal.fire({
                title: 'Confirm Approval',
                text: 'Are you sure you want to approve this grade change request?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#0e4429',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, Approve',
                cancelButtonText: 'Cancel'
            }).then(function(result) {
                if (result.isConfirmed) {
                    self.submit();
                }
            });
        });
    });

    document.querySelectorAll('.reject-change-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var self = this;
            Swal.fire({
                title: 'Confirm Rejection',
                text: 'Are you sure you want to reject this grade change request?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, Reject',
                cancelButtonText: 'Cancel'
            }).then(function(result) {
                if (result.isConfirmed) {
                    self.submit();
                }
            });
        });
    });

    document.querySelectorAll('.close-change-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var self = this;
            Swal.fire({
                title: 'Confirm Close',
                text: 'Are you sure you want to close this grade change request?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#6366f1',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, Close',
                cancelButtonText: 'Cancel'
            }).then(function(result) {
                if (result.isConfirmed) {
                    self.submit();
                }
            });
        });
    });

    document.querySelectorAll('.approve-access-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var self = this;
            Swal.fire({
                title: 'Confirm Approval',
                text: 'Are you sure you want to approve this grade access request?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#0e4429',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, Approve',
                cancelButtonText: 'Cancel'
            }).then(function(result) {
                if (result.isConfirmed) {
                    self.submit();
                }
            });
        });
    });

    document.querySelectorAll('.reject-access-form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var self = this;
            Swal.fire({
                title: 'Confirm Rejection',
                text: 'Are you sure you want to reject this grade access request?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, Reject',
                cancelButtonText: 'Cancel'
            }).then(function(result) {
                if (result.isConfirmed) {
                    self.submit();
                }
            });
        });
    });
});
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/inc/app_layout.php';
?>