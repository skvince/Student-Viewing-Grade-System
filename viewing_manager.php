<?php
require_once __DIR__ . '/inc/functions.php';
$pageTitle = 'Grade Viewing';
$activeNav = 'viewing';
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
    $schoolYear = trim($_POST['school_year'] ?? $selectedYear);
    $semester = normalize_semester($_POST['semester'] ?? $selectedSem);
    $startDate = trim($_POST['start_date'] ?? '');
    $endDate = trim($_POST['end_date'] ?? '');
    $isActive = isset($_POST['is_active']) ? (bool)$_POST['is_active'] : true;
    $scheduleId = isset($_POST['schedule_id']) ? (int)$_POST['schedule_id'] : 0;

    $startDt = DateTime::createFromFormat('Y-m-d\TH:i', $startDate);
    $endDt = DateTime::createFromFormat('Y-m-d\TH:i', $endDate);

    if ($action === 'save_schedule') {
        if (!$schoolYear || !$semester || !$startDate || !$endDate || !$startDt || !$endDt) {
            $message = 'All fields are required. End date must be after start date.';
            $messageType = 'error';
        } elseif ($endDt <= $startDt) {
            $message = 'End date must be after start date.';
            $messageType = 'error';
        } else {
           $startFormatted = $startDt->format('Y-m-d H:i:s');
          $endFormatted = $endDt->format('Y-m-d H:i:s');
            $result = save_grade_viewing_schedule($schoolYear, $semester, $startFormatted, $endFormatted, $isActive);
            if ($result !== null) {
                audit_log('save_viewing_schedule', $_SESSION['user_id'], null, $schoolYear, $semester);
                $message = 'Grade viewing schedule saved successfully.';
                $messageType = 'success';
            } else {
                $message = 'Failed to save schedule.';
                $messageType = 'error';
            }
        }
    } elseif ($action === 'toggle_schedule' && $scheduleId) {
        $ok = toggle_grade_viewing_schedule($scheduleId, !$isActive);
        if ($ok) {
            $statusLabel = !$isActive ? 'enabled' : 'disabled';
            audit_log('toggle_viewing_schedule', $_SESSION['user_id'], null, null, null, null, null, null, $scheduleId);
            $message = 'Schedule ' . $statusLabel . ' successfully.';
            $messageType = 'success';
        } else {
            $message = 'Failed to update schedule.';
            $messageType = 'error';
        }
    }
}

$schedules = get_grade_viewing_schedules($selectedYear, '');
$schedulesByPeriod = [];
foreach ($schedules as $s) {
    $key = $s['semester'];
    $schedulesByPeriod[$key] = $s;
}

$selectedYearSchedules = $schedules;
?>

    <div class="tab-content" style="display: block;">
      <?php if ($message): ?><div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div><?php endif; ?>

      <h1 class="view-title">Grade Viewing Schedules</h1>
      <p class="view-subtitle">Manage when students can view their grades</p>

    <div class="panel-block">
      <div class="block-header">
        <h2 class="block-title">Set Viewing Schedule</h2>
      </div>
      <form method="post" id="viewing-form">
        <?php echo csrf_field(); ?>
        <div class="grid-3col">
          <?php foreach (['1st Semester', '2nd Semester', 'Summer'] as $sem): ?>
            <?php $sched = $schedulesByPeriod[$sem] ?? null; ?>
            <div class="schedule-form-group">
              <h3><?php echo htmlspecialchars($sem); ?></h3>
              <div class="form-group">
                <label for="start_date_<?php echo strtolower(str_replace(' ', '_', $sem)); ?>">Start Date</label>
                <input type="datetime-local" id="start_date_<?php echo strtolower(str_replace(' ', '_', $sem)); ?>" name="start_date_<?php echo strtolower(str_replace(' ', '_', $sem)); ?>" class="form-control" value="<?php echo htmlspecialchars($sched ? date('Y-m-d\TH:i', strtotime($sched['start_date'])) : ''); ?>" />
              </div>
              <div class="form-group">
                <label for="end_date_<?php echo strtolower(str_replace(' ', '_', $sem)); ?>">End Date</label>
                <input type="datetime-local" id="end_date_<?php echo strtolower(str_replace(' ', '_', $sem)); ?>" name="end_date_<?php echo strtolower(str_replace(' ', '_', $sem)); ?>" class="form-control" value="<?php echo htmlspecialchars($sched ? date('Y-m-d\TH:i', strtotime($sched['end_date'])) : ''); ?>" />
              </div>
              <div class="form-group">
                <label for="is_active_<?php echo strtolower(str_replace(' ', '_', $sem)); ?>">Status</label>
                <select id="is_active_<?php echo strtolower(str_replace(' ', '_', $sem)); ?>" name="is_active_<?php echo strtolower(str_replace(' ', '_', $sem)); ?>" class="form-control">
                  <option value="1" <?php echo (!$sched || $sched['is_active']) ? 'selected' : ''; ?>>Active</option>
                  <option value="0" <?php echo ($sched && !$sched['is_active']) ? 'selected' : ''; ?>>Disabled</option>
                </select>
              </div>
              <button type="button" class="btn-submit btn-save-viewing" data-semester="<?php echo htmlspecialchars($sem); ?>" data-year="<?php echo htmlspecialchars($selectedYear); ?>">
                <i class="fa-solid fa-floppy-disk"></i> Save
              </button>
            </div>
          <?php endforeach; ?>
        </div>
      </form>
    </div>

    <div class="panel-block">
      <div class="block-header">
        <h2 class="block-title">All Schedules</h2>
      </div>
      <div class="table-responsive">
        <table>
          <thead>
            <tr>
              <th>Academic Year</th>
              <th>Semester</th>
              <th>Start Date</th>
              <th>End Date</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($selectedYearSchedules as $sched): ?>
              <tr>
                <td><?php echo htmlspecialchars($sched['school_year']); ?></td>
                <td><?php echo htmlspecialchars($sched['semester']); ?></td>
                <td>
                  <?php echo date('M d, Y h:i A', strtotime($sched['start_date'])); ?>
                </td>
                <td>
                  <?php echo date('M d, Y h:i A', strtotime($sched['end_date'])); ?>
                </td>
                <td>
                  <?php if ($sched['is_active']): ?>
                    <span class="status-badge status-active">Active</span>
                  <?php else: ?>
                    <span class="status-badge status-inactive">Disabled</span>
                  <?php endif; ?>
                </td>
                <td>
                  <button type="button" class="btn-action btn-toggle-viewing" data-id="<?php echo (int)$sched['id']; ?>" data-active="<?php echo $sched['is_active'] ? '1' : '0'; ?>">
                    <i class="fa-solid fa-<?php echo $sched['is_active'] ? 'toggle-on' : 'toggle-off'; ?>"></i>
                    <?php echo $sched['is_active'] ? 'Disable' : 'Enable'; ?>
                  </button>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (empty($selectedYearSchedules)): ?>
              <tr><td colspan="6" style="text-align:center;color:#6b7280;padding:18px;">No schedules found for <?php echo htmlspecialchars($selectedYear); ?></td></tr>
            <?php endif; ?>
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

    function saveViewingSchedule(semester, year) {
      const semKey = semester.toLowerCase().replace(/ /g, '_');
      const startDate = document.getElementById('start_date_' + semKey).value;
      const endDate = document.getElementById('end_date_' + semKey).value;
      const isActive = document.getElementById('is_active_' + semKey).value;

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
      actionInput.value = 'save_schedule';
      form.appendChild(actionInput);

      const yearInput = document.createElement('input');
      yearInput.type = 'hidden';
      yearInput.name = 'school_year';
      yearInput.value = year;
      form.appendChild(yearInput);

      const semInput = document.createElement('input');
      semInput.type = 'hidden';
      semInput.name = 'semester';
      semInput.value = semester;
      form.appendChild(semInput);

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

      const activeInput = document.createElement('input');
      activeInput.type = 'hidden';
      activeInput.name = 'is_active';
      activeInput.value = isActive;
      form.appendChild(activeInput);

      document.body.appendChild(form);
      form.submit();
    }

    function toggleViewingSchedule(id, currentActive) {
      const newActive = currentActive === '1' ? '0' : '1';
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
      actionInput.value = 'toggle_schedule';
      form.appendChild(actionInput);

      const idInput = document.createElement('input');
      idInput.type = 'hidden';
      idInput.name = 'schedule_id';
      idInput.value = id;
      form.appendChild(idInput);

      const activeInput = document.createElement('input');
      activeInput.type = 'hidden';
      activeInput.name = 'is_active';
      activeInput.value = currentActive;
      form.appendChild(activeInput);

      document.body.appendChild(form);
      form.submit();
    }

document.addEventListener('DOMContentLoaded', function() {
       if (yearSelect) yearSelect.addEventListener('change', syncGlobalFilter);
       if (semSelect) semSelect.addEventListener('change', syncGlobalFilter);

       document.querySelectorAll('.btn-save-viewing').forEach(btn => {
         btn.addEventListener('click', function() {
           var semester = this.getAttribute('data-semester');
           var year = this.getAttribute('data-year');
           saveViewingSchedule(semester, year);
         });
       });

       document.querySelectorAll('.btn-toggle-viewing').forEach(btn => {
         btn.addEventListener('click', function() {
           var id = this.getAttribute('data-id');
           var currentActive = this.getAttribute('data-active');
           var action = currentActive === '1' ? 'disable' : 'enable';
           var self = this;
           Swal.fire({
             title: 'Confirm ' + action.charAt(0).toUpperCase() + action.slice(1),
             text: 'Are you sure you want to ' + action + ' this schedule?',
             icon: 'question',
             showCancelButton: true,
             confirmButtonColor: '#0e4429',
             cancelButtonColor: '#6b7280',
             confirmButtonText: 'Yes, ' + action.charAt(0).toUpperCase() + action.slice(1),
             cancelButtonText: 'Cancel'
           }).then(function(result) {
             if (result.isConfirmed) {
               toggleViewingSchedule(id, currentActive);
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

<script>
  document.addEventListener('DOMContentLoaded', function() {
    <?php if ($message): ?>
      showAlert('<?php echo $messageType; ?>', '<?php echo $messageType === 'success' ? 'Success' : ($messageType === 'error' ? 'Error' : 'Notice'); ?>', '<?php echo addslashes(htmlspecialchars($message)); ?>');
    <?php endif; ?>
  });
</script>
?>
