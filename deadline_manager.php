<?php
require_once __DIR__ . '/inc/functions.php';
$pageTitle = 'Grade Deadlines';
$activeNav = 'deadlines';
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) { header('Location: ' . $_SERVER['PHP_SELF']); exit; }
    $action = $_POST['action'] ?? '';
    $gradingPeriod = $_POST['grading_period'] ?? '';
    $deadline = $_POST['deadline'] ?? '';
    $status = $_POST['status'] ?? 'open';
    $extendedUntil = $_POST['extended_until'] ?? null;

    if ($action === 'save_deadline' && $gradingPeriod) {
        $startDate = trim($_POST['start_date'] ?? '');
        $endDate = trim($_POST['end_date'] ?? '');
        $startDt = DateTime::createFromFormat('Y-m-d\TH:i', $startDate);
        $endDt = DateTime::createFromFormat('Y-m-d\TH:i', $endDate);
        $startFormatted = $startDt ? $startDt->format('Y-m-d H:i:s') : null;
        $endFormatted = $endDt ? $endDt->format('Y-m-d H:i:s') : null;

        if ($status === 'open' || $status === 'closed') {
            $extendedUntil = null;
        }

        $result = set_deadline($selectedYear, $selectedSem, $gradingPeriod, $startFormatted, $endFormatted, $status, $extendedUntil);
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
        $result = set_deadline($selectedYear, $selectedSem, $gradingPeriod, null, $futureDate, 'open', null);
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
        $currentDeadline = $existing['end_date'] ?? (new DateTime())->format('Y-m-d H:i:s');

        $extendedDt = DateTime::createFromFormat('Y-m-d\TH:i', $extendedUntil);
        $extendedFormatted = $extendedDt ? $extendedDt->format('Y-m-d H:i:s') : null;

        if ($extendedFormatted) {
            $result = set_deadline($selectedYear, $selectedSem, $gradingPeriod, null, $currentDeadline, 'extended', $extendedFormatted);
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

    <div class="tab-content" style="display: block;">
      <h1 class="view-title">Grade Deadlines</h1>
      <p class="view-subtitle">Manage submission deadlines for each grading period</p>

      <div class="panel-block">
      <div class="block-header">
        <h2 class="block-title">Set Deadlines</h2>
      </div>
      <form method="post" id="deadline-form">
        <?php echo csrf_field(); ?>
        <div class="grid-3col">
          <?php foreach (['prelim', 'midterm', 'finals'] as $period): ?>
            <?php $deadline = $deadlinesByPeriod[$period] ?? null; ?>
            <?php $status = get_deadline_status($selectedYear, $selectedSem, $period); ?>
            <?php $startValue = $deadline ? date('Y-m-d\TH:i', strtotime($deadline['start_date'])) : ''; ?>
            <?php $endValue = $deadline ? date('Y-m-d\TH:i', strtotime($deadline['end_date'])) : ''; ?>
            <div class="deadline-form-group">
              <h3><?php echo ucfirst($period); ?></h3>
              <div class="form-group">
                <label for="start_date_<?php echo $period; ?>">Open Date</label>
                <input type="datetime-local" id="start_date_<?php echo $period; ?>" name="start_date_<?php echo $period; ?>" class="form-control" value="<?php echo htmlspecialchars($startValue); ?>" />
              </div>
              <div class="form-group">
                <label for="end_date_<?php echo $period; ?>">Close Date</label>
                <input type="datetime-local" id="end_date_<?php echo $period; ?>" name="end_date_<?php echo $period; ?>" class="form-control" value="<?php echo htmlspecialchars($endValue); ?>" />
              </div>
              <div class="form-group">
                <label for="status_<?php echo $period; ?>">Status</label>
                <select id="status_<?php echo $period; ?>" name="status_<?php echo $period; ?>" class="form-control">
                  <option value="open" <?php echo $status==='open'?'selected':''; ?>>Open</option>
                  <option value="closed" <?php echo $status==='closed'?'selected':''; ?>>Closed</option>
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
              <th>Open Date</th>
              <th>Close Date</th>
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
                  <?php if ($deadline && $deadline['start_date']): ?>
                    <?php echo date('M d, Y h:i A', strtotime($deadline['start_date'])); ?>
                  <?php else: ?>
                    <span style="color:#6b7280;">-</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($deadline && $deadline['end_date']): ?>
                    <?php echo date('M d, Y h:i A', strtotime($deadline['end_date'])); ?>
                  <?php else: ?>
                    <span style="color:#6b7280;">-</span>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="status-badge badge-<?php echo $status; ?>"><?php echo $status; ?></span>
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
        <?php echo csrf_field(); ?>
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
      const startDate = document.getElementById('start_date_' + period).value;
      const endDate = document.getElementById('end_date_' + period).value;
      const status = document.getElementById('status_' + period).value;

      const form = document.createElement('form');
      form.method = 'post';
      form.style.display = 'none';

      const tokenInput = document.createElement('input');
      tokenInput.type = 'hidden';
      tokenInput.name = 'csrf_token';
      tokenInput.value = '<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES); ?>';
      form.appendChild(tokenInput);
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

      const startInput = document.createElement('input');
      startInput.type = 'hidden';
      startInput.name = 'start_date';
      startInput.value = startDate;
      form.appendChild(startInput);

      const endInput = document.createElement('input');
      endInput.type = 'hidden';
      endInput.name = 'end_date';
      endInput.value = endDate;
      form.appendChild(endInput);

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

      const tokenInput = document.createElement('input');
      tokenInput.type = 'hidden';
      tokenInput.name = 'csrf_token';
      tokenInput.value = '<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES); ?>';
      form.appendChild(tokenInput);
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

<?php
$content = ob_get_clean();
require_once __DIR__ . '/inc/app_layout.php';
?>
