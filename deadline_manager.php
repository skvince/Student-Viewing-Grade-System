<?php
require_once __DIR__ . '/inc/functions.php';

$pageTitle = 'Grade Deadlines';
$activeNav  = 'deadlines';
$content    = '';

ob_start();
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

$term = get_global_term();

$selectedYear = $_GET['global_year'] ?? $term['year'];
$selectedSem  = $_GET['global_sem']  ?? $term['semester'];

$flashPopupType = '';
$flashPopupTitle = '';
$flashPopupMessage = '';

// ── Parse datetime-local (YYYY-MM-DDTHH:MM) → 'Y-m-d H:i:s' ─────────────────
function parse_dt(string $raw): ?string {
    if (trim($raw) === '') return null;
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $raw)
       ?: DateTime::createFromFormat('Y-m-d\TH:i:s', $raw)
       ?: DateTime::createFromFormat('Y-m-d H:i:s', $raw);
    return $dt ? $dt->format('Y-m-d H:i:s') : null;
}

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verify_csrf()) {
        header('Location: ' . $_SERVER['PHP_SELF']
            . '?global_year=' . urlencode($selectedYear)
            . '&global_sem='  . urlencode($selectedSem));
        exit;
    }

    $action        = $_POST['action']         ?? '';
    $gradingPeriod = $_POST['grading_period'] ?? '';
    $status        = $_POST['status']         ?? 'open';
    $startRaw      = trim($_POST['start_date']    ?? '');
    $endRaw        = trim($_POST['end_date']       ?? '');
    $extendedRaw   = trim($_POST['extended_until'] ?? '');

    // ── save_deadline ─────────────────────────────────────────────────────────
    if ($action === 'save_deadline' && $gradingPeriod) {

        $startFormatted    = parse_dt($startRaw);
        $endFormatted      = parse_dt($endRaw);
        $extendedFormatted = parse_dt($extendedRaw);

        if ($status === 'open' || $status === 'closed') {

            if ($startRaw === '') {
                $flashPopupMessage     = 'Open Date is required.';
                $flashPopupType = 'error';
            } elseif (!$startFormatted) {
                $flashPopupMessage     = 'Open Date could not be read — please re-enter it.';
                $flashPopupType = 'error';
            } elseif ($endRaw === '') {
                $flashPopupMessage     = 'Close Date is required.';
                $flashPopupType = 'error';
            } elseif (!$endFormatted) {
                $flashPopupMessage     = 'Close Date could not be read — please re-enter it.';
                $flashPopupType = 'error';
            } elseif (strtotime($startFormatted) >= strtotime($endFormatted)) {
                $flashPopupMessage     = 'Close Date must be later than Open Date.';
                $flashPopupType = 'error';
            } else {
                $result = set_deadline($selectedYear, $selectedSem, $gradingPeriod,
                                       $startFormatted, $endFormatted, $status, null);
                if ($result) {
                    audit_log('set_deadline_' . $gradingPeriod,
                              $_SESSION['user_id'], $gradingPeriod, $selectedYear, $selectedSem);
                    $flashPopupMessage     = ucfirst($gradingPeriod) . ' deadline saved successfully.';
                    $flashPopupType = 'success';
                } else {
                    $flashPopupMessage     = 'Failed to save deadline. Please try again.';
                    $flashPopupType = 'error';
                    error_log('set_deadline failed for ' . $gradingPeriod
                        . ' in ' . $selectedYear . ' ' . $selectedSem
                        . ' start=' . $startRaw . ' end=' . $endRaw . ' status=' . $status);
                }
            }

        } elseif ($status === 'extended') {

            if (empty($extendedFormatted)) {
                $flashPopupMessage     = 'Extended Until date is required.';
                $flashPopupType = 'error';
            } else {
                $result = set_deadline($selectedYear, $selectedSem, $gradingPeriod,
                                       $startFormatted, $endFormatted, 'extended', $extendedFormatted);
                if ($result) {
                    audit_log('extend_deadline_' . $gradingPeriod,
                              $_SESSION['user_id'], $gradingPeriod, $selectedYear, $selectedSem);
                    $flashPopupMessage     = ucfirst($gradingPeriod) . ' deadline extended successfully.';
                    $flashPopupType = 'success';
                } else {
                    $flashPopupMessage     = 'Failed to extend deadline. Please try again.';
                    $flashPopupType = 'error';
                }
            }
        }

    } elseif ($action === 'reopen_deadline' && $gradingPeriod) {

        $now      = new DateTime();
        $startFmt = $now->format('Y-m-d H:i:s');
        $endFmt   = (clone $now)->modify('+7 days')->format('Y-m-d H:i:s');

        $result = set_deadline($selectedYear, $selectedSem, $gradingPeriod,
                               $startFmt, $endFmt, 'open', null);
        if ($result) {
            audit_log('reopen_deadline_' . $gradingPeriod,
                      $_SESSION['user_id'], $gradingPeriod, $selectedYear, $selectedSem);
            $flashPopupMessage     = ucfirst($gradingPeriod) . ' deadline reopened successfully.';
            $flashPopupType = 'success';
        } else {
            $flashPopupMessage     = 'Failed to reopen deadline. Please try again.';
            $flashPopupType = 'error';
        }
    }

} // end POST

// ── Data ──────────────────────────────────────────────────────────────────────
$deadlines = get_all_deadlines($selectedYear, $selectedSem);
$deadlinesByPeriod = [];
foreach ($deadlines as $d) {
    $deadlinesByPeriod[$d['grading_period']] = $d;
}
?>

<div class="tab-content" style="display: block;">
  <h1 class="view-title">Grade Deadlines</h1>
  <p class="view-subtitle">Manage submission deadlines for each grading period</p>

  <!-- ── Set Deadlines ──────────────────────────────────────────────────────── -->
  <div class="panel-block">
    <div class="block-header">
      <h2 class="block-title">Set Deadlines</h2>
    </div>

    <div class="grid-3col">
      <?php foreach (['prelim', 'midterm', 'finals'] as $period):
        $deadline   = $deadlinesByPeriod[$period] ?? null;
        $curStatus  = get_deadline_status($selectedYear, $selectedSem, $period);
        $startValue = ($deadline && !empty($deadline['start_date']))
                        ? date('Y-m-d\TH:i', strtotime($deadline['start_date'])) : '';
        $endValue   = ($deadline && !empty($deadline['end_date']))
                        ? date('Y-m-d\TH:i', strtotime($deadline['end_date'])) : '';
        $extValue   = ($deadline && !empty($deadline['extended_until']))
                        ? date('Y-m-d\TH:i', strtotime($deadline['extended_until'])) : '';
      ?>

      <!-- Each period is its own form so name="start_date" etc. are unambiguous -->
      <form method="post" class="deadline-form-group">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action"         value="save_deadline">
        <input type="hidden" name="grading_period" value="<?php echo htmlspecialchars($period); ?>">

        <h3><?php echo ucfirst($period); ?></h3>

        <div class="form-group">
          <label for="start_date_<?php echo $period; ?>">Open Date</label>
          <input type="datetime-local"
                 id="start_date_<?php echo $period; ?>"
                 name="start_date"
                 class="form-control"
                 value="<?php echo htmlspecialchars($startValue); ?>">
        </div>

        <div class="form-group">
          <label for="end_date_<?php echo $period; ?>">Close Date</label>
          <input type="datetime-local"
                 id="end_date_<?php echo $period; ?>"
                 name="end_date"
                 class="form-control"
                 value="<?php echo htmlspecialchars($endValue); ?>">
        </div>

        <div class="form-group">
          <label for="status_<?php echo $period; ?>">Status</label>
          <select id="status_<?php echo $period; ?>"
                  name="status"
                  class="form-control"
                  onchange="toggleExtGroup('<?php echo $period; ?>', this.value)">
            <option value="open"     <?php echo $curStatus === 'open'     ? 'selected' : ''; ?>>Open</option>
            <option value="closed"   <?php echo $curStatus === 'closed'   ? 'selected' : ''; ?>>Closed</option>
            <option value="extended" <?php echo $curStatus === 'extended' ? 'selected' : ''; ?>>Extended</option>
          </select>
        </div>

        <div class="form-group"
             id="extended-until-group-<?php echo $period; ?>"
             style="display:<?php echo $curStatus === 'extended' ? 'block' : 'none'; ?>;">
          <label for="extended_until_<?php echo $period; ?>">Extended Until</label>
          <input type="datetime-local"
                 id="extended_until_<?php echo $period; ?>"
                 name="extended_until"
                 class="form-control"
                 value="<?php echo htmlspecialchars($extValue); ?>">
        </div>

        <button type="submit" class="btn-submit">
          <i class="fa-solid fa-floppy-disk"></i> Save
        </button>
      </form>

      <?php endforeach; ?>
    </div>
  </div>

  <!-- ── Current Deadlines ──────────────────────────────────────────────────── -->
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
          <?php foreach (['prelim', 'midterm', 'finals'] as $period):
            $deadline  = $deadlinesByPeriod[$period] ?? null;
            $curStatus = get_deadline_status($selectedYear, $selectedSem, $period);
          ?>
          <tr>
            <td><?php echo ucfirst($period); ?></td>
            <td>
              <?php echo ($deadline && !empty($deadline['start_date']))
                ? htmlspecialchars(date('M d, Y h:i A', strtotime($deadline['start_date'])))
                : '<span style="color:#6b7280;">—</span>'; ?>
            </td>
            <td>
              <?php echo ($deadline && !empty($deadline['end_date']))
                ? htmlspecialchars(date('M d, Y h:i A', strtotime($deadline['end_date'])))
                : '<span style="color:#6b7280;">—</span>'; ?>
            </td>
            <td>
              <span class="status-badge badge-<?php echo htmlspecialchars($curStatus); ?>">
                <?php echo htmlspecialchars(ucfirst($curStatus)); ?>
              </span>
            </td>
            <td>
              <?php echo ($deadline && !empty($deadline['extended_until']))
                ? htmlspecialchars(date('M d, Y h:i A', strtotime($deadline['extended_until'])))
                : '<span style="color:#6b7280;">—</span>'; ?>
            </td>
            <td>
              <?php if ($curStatus === 'closed'): ?>
                <button type="button"
                        class="btn-action btn-reopen btn-reopen-deadline"
                        data-period="<?php echo $period; ?>">
                  <i class="fa-solid fa-unlock"></i> Reopen
                </button>
              <?php else: ?>
                <button type="button"
                        class="btn-action btn-extend btn-extend-deadline"
                        data-period="<?php echo $period; ?>">
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

  <!-- ── Extend modal ───────────────────────────────────────────────────────── -->
  <div id="extend-modal-backdrop" class="modal-backdrop" style="display:none;">
    <div class="modal-card">
      <button type="button" class="modal-close" id="btn-close-extend-modal">
        <i class="fa-solid fa-xmark"></i>
      </button>
      <h3 style="margin-bottom:16px;">Extend Deadline</h3>

      <form method="post" id="extend-form">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action"         value="save_deadline">
        <input type="hidden" name="status"         value="extended">
        <input type="hidden" name="grading_period" id="extend-grading-period" value="">
        <input type="hidden" name="start_date"     id="extend-start-hidden"   value="">
        <input type="hidden" name="end_date"       id="extend-end-hidden"     value="">

        <div class="form-group">
          <label for="extend-deadline">Extended Until</label>
          <input type="datetime-local" id="extend-deadline" name="extended_until"
                 class="form-control" required>
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
  // Stored so the Extend modal can forward the existing start/end dates
  const deadlineData = <?php
    $js = [];
    foreach (['prelim','midterm','finals'] as $p) {
        $d = $deadlinesByPeriod[$p] ?? null;
        $js[$p] = [
            'start' => ($d && !empty($d['start_date']))
                         ? date('Y-m-d\TH:i', strtotime($d['start_date'])) : '',
            'end'   => ($d && !empty($d['end_date']))
                         ? date('Y-m-d\TH:i', strtotime($d['end_date'])) : '',
        ];
    }
    echo json_encode($js, JSON_HEX_TAG);
  ?>;

  function syncGlobalFilter() {
    const year = document.getElementById('global-filter-year').value;
    const sem  = document.getElementById('global-filter-sem').value;
    const url  = new URL(window.location);
    url.searchParams.set('global_year', year);
    url.searchParams.set('global_sem',  sem);
    ['school_year','semester','academic_year'].forEach(k => url.searchParams.delete(k));
    window.location.href = url.toString();
  }

  function toggleExtGroup(period, value) {
    const g = document.getElementById('extended-until-group-' + period);
    if (g) g.style.display = value === 'extended' ? 'block' : 'none';
  }

  // ── Reopen (dynamic form POST) ────────────────────────────────────────────
  function reopenDeadline(period) {
    const form = document.createElement('form');
    form.method = 'post';
    form.style.display = 'none';
    [
      ['csrf_token',     '<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES); ?>'],
      ['action',         'reopen_deadline'],
      ['grading_period', period],
    ].forEach(([n, v]) => {
      const i = document.createElement('input');
      i.type = 'hidden'; i.name = n; i.value = v;
      form.appendChild(i);
    });
    document.body.appendChild(form);
    form.submit();
  }

  // ── Extend modal ──────────────────────────────────────────────────────────
  const backdrop = document.getElementById('extend-modal-backdrop');

  function openExtendModal(period) {
    document.getElementById('extend-grading-period').value = period;
    document.getElementById('extend-start-hidden').value   = deadlineData[period]?.start ?? '';
    document.getElementById('extend-end-hidden').value     = deadlineData[period]?.end   ?? '';
    backdrop.style.display = 'flex';
  }

  function closeExtendModal() {
    backdrop.style.display = 'none';
    document.getElementById('extend-form').reset();
  }

  document.addEventListener('DOMContentLoaded', function () {
    const yearSel = document.getElementById('global-filter-year');
    const semSel  = document.getElementById('global-filter-sem');
    if (yearSel) yearSel.addEventListener('change', syncGlobalFilter);
    if (semSel)  semSel.addEventListener('change',  syncGlobalFilter);

    // Reopen buttons
    document.querySelectorAll('.btn-reopen-deadline').forEach(btn => {
      btn.addEventListener('click', function () {
        const period = this.dataset.period;
        Swal.fire({
          title: 'Confirm Reopen',
          text:  'Reopen the ' + period + ' deadline? It will be open for 7 days.',
          icon:  'question',
          showCancelButton:   true,
          confirmButtonColor: '#0e4429',
          cancelButtonColor:  '#6b7280',
          confirmButtonText:  'Yes, Reopen',
          cancelButtonText:   'Cancel',
        }).then(r => { if (r.isConfirmed) reopenDeadline(period); });
      });
    });

    // Extend buttons
    document.querySelectorAll('.btn-extend-deadline').forEach(btn => {
      btn.addEventListener('click', function () {
        openExtendModal(this.dataset.period);
      });
    });

    // Extend form — disable button on submit to prevent double-post
    document.getElementById('extend-form').addEventListener('submit', function () {
      const btn = this.querySelector('button[type="submit"]');
      if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
      }
    });

    document.getElementById('btn-close-extend-modal').addEventListener('click', closeExtendModal);
    document.getElementById('btn-cancel-extend').addEventListener('click', closeExtendModal);
    backdrop.addEventListener('click', e => { if (e.target === backdrop) closeExtendModal(); });
  });
  </script>
</div>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/inc/app_layout.php';
?>