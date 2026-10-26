<?php
session_start();
if (empty($_SESSION['userId'])) {
    echo "<script>window.location.href='login.php';</script>";
    exit;
}
require_once('../include/db_config.php');
require_once('../include/short_leave_helper.php');

$userId    = $_SESSION['userId'];
$yearMonth = date('Y-m');
$usage     = getMonthlyShortLeaveUsage($con, $userId, $yearMonth);
$remaining = getRemainingFreeShortLeaves($con, $userId, $yearMonth);

// Fetch user name
$nameQ = mysqli_query($con, "SELECT fullName FROM signup WHERE userId='$userId' LIMIT 1");
$nameR = $nameQ ? mysqli_fetch_assoc($nameQ) : null;
$fullName = $nameR['fullName'] ?? 'Employee';

// Flash messages from short_leave_db.php redirect
$slSuccess = $_SESSION['sl_success'] ?? '';
$slError   = $_SESSION['sl_error']   ?? '';
unset($_SESSION['sl_success'], $_SESSION['sl_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php include('../partials/_links.php'); ?>
    <meta charset="UTF-8">
    <title>Apply Short Leave</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f4f6fb; }
        .sl-container {
            max-width: 520px; margin: 40px auto; background: #fff;
            border-radius: 18px; box-shadow: 0 8px 32px rgba(25,119,225,.12);
            overflow: hidden;
        }
        .sl-header {
            background: linear-gradient(135deg,#1977e1,#1254b0);
            padding: 22px 28px; color: #fff;
        }
        .sl-header h2 { margin:0; font-size:18px; font-weight:700; }
        .sl-header p  { margin:4px 0 0; font-size:13px; opacity:.8; }
        .sl-body { padding: 28px; }
        .quota-bar {
            background: #f0f7ff; border: 1.5px solid #bfdbfe;
            border-radius: 10px; padding: 14px 18px; margin-bottom: 24px;
            display: flex; align-items: center; gap: 14px;
        }
        .quota-num { font-size: 28px; font-weight: 800; color: #1977e1; line-height:1; }
        .quota-label { font-size: 13px; color: #374151; font-weight: 600; }
        .quota-sub   { font-size: 12px; color: #6b7280; margin-top: 2px; }
        .form-group  { margin-bottom: 18px; }
        .form-group label { display:block; font-size:13px; font-weight:600; color:#374151; margin-bottom:6px; }
        .form-control {
            width: 100%; padding: 10px 13px; border: 1.5px solid #e2e8f0;
            border-radius: 8px; font-size: 14px; background: #f8fafc;
            box-sizing: border-box; transition: border-color .15s;
        }
        .form-control:focus { border-color: #1977e1; outline: none; background: #fff; }
        .row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .policy-note {
            background: #fffbeb; border: 1.5px solid #fcd34d;
            border-radius: 8px; padding: 12px 14px; font-size: 12.5px;
            color: #92400e; margin-bottom: 20px; line-height: 1.6;
        }
        .btn-submit {
            width: 100%; padding: 13px; background: linear-gradient(135deg,#1977e1,#1254b0);
            color: #fff; border: none; border-radius: 10px; font-size: 15px;
            font-weight: 700; cursor: pointer; transition: opacity .2s;
        }
        .btn-submit:hover { opacity: .9; }
        #durationDisplay { font-size:13px; color:#1977e1; font-weight:600; margin-top:5px; min-height:18px; }
        .warn-text { color: #dc2626; }
    </style>
</head>
<body>
<div class="container-scroller">
    <?php include('../partials/_navbar.php'); ?>
    <div class="container-fluid page-body-wrapper">
        <?php include('../partials/_sidebar.php'); ?>
        <div class="main-content" style="display:flex;justify-content:center;align-items:flex-start;padding:40px 16px;">
            <div class="sl-container">
                <div class="sl-header">
                    <h2>⏱ Apply Short Leave</h2>
                    <p>For bank, hospital, government office visits (max 2 hrs)</p>
                </div>
                <div class="sl-body">

                    <!-- Quota status -->
                    <div class="quota-bar">
                        <div class="quota-num"><?= $remaining ?></div>
                        <div>
                            <div class="quota-label">Free Short Leaves Remaining</div>
                            <div class="quota-sub">
                                Used <?= $usage['used'] ?> of <?= SHORT_LEAVE_FREE_PER_MONTH ?> this month
                                (<?= number_format($usage['totalHours'], 1) ?> hrs total used)
                            </div>
                        </div>
                    </div>

                    <?php if ($remaining == 0): ?>
                    <div class="policy-note">
                        ⚠️ Your free short leave quota for this month is exhausted.
                        Additional short leaves will be <strong>adjusted against your leave balance</strong> (0.5 day per extra short leave).
                    </div>
                    <?php else: ?>
                    <div class="policy-note">
                        ℹ️ You may apply up to <strong>2 short leaves per month</strong>, max <strong>2 hours each</strong>.
                        Unused short leaves do not carry forward to the next month.
                    </div>
                    <?php endif; ?>

                    <form id="shortLeaveForm" method="POST" action="short_leave_db.php">
                        <div class="form-group">
                            <label for="sl_date">Date <span style="color:#e53935">*</span></label>
                            <input type="date" id="sl_date" name="sl_date" class="form-control"
                                   min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <div class="row-2">
                            <div class="form-group">
                                <label for="from_time">From Time <span style="color:#e53935">*</span></label>
                                <input type="time" id="from_time" name="from_time" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label for="to_time">To Time <span style="color:#e53935">*</span></label>
                                <input type="time" id="to_time" name="to_time" class="form-control" required>
                            </div>
                        </div>

                        <div id="durationDisplay"></div>

                        <div class="form-group" style="margin-top:14px;">
                            <label for="sl_reason">Reason <span style="color:#e53935">*</span></label>
                            <textarea id="sl_reason" name="sl_reason" class="form-control"
                                      rows="3" maxlength="500" required
                                      placeholder="e.g. Bank work, hospital visit, government office..."></textarea>
                        </div>

                        <button type="submit" class="btn-submit">Submit Short Leave Request</button>
                    </form>

                </div>
            </div>
        </div>
    </div>
</div>

<script>
    const fromEl = document.getElementById('from_time');
    const toEl   = document.getElementById('to_time');
    const display = document.getElementById('durationDisplay');
    const maxHrs  = <?= SHORT_LEAVE_MAX_HOURS ?>;

    function updateDuration() {
        const from = fromEl.value, to = toEl.value;
        if (!from || !to) { display.innerHTML = ''; return; }
        const [fh, fm] = from.split(':').map(Number);
        const [th, tm] = to.split(':').map(Number);
        const mins = (th * 60 + tm) - (fh * 60 + fm);
        if (mins <= 0) {
            display.innerHTML = '<span class="warn-text">⚠ To time must be after From time.</span>';
            return;
        }
        const hrs = mins / 60;
        if (hrs > maxHrs) {
            display.innerHTML = `<span class="warn-text">⚠ Duration ${hrs.toFixed(1)} hrs exceeds the ${maxHrs}-hour limit.</span>`;
        } else {
            display.innerHTML = `Duration: <strong>${Math.floor(mins/60)}h ${mins%60}m</strong>`;
        }
    }

    fromEl.addEventListener('change', updateDuration);
    toEl.addEventListener('change', updateDuration);

    document.getElementById('shortLeaveForm').addEventListener('submit', function(e) {
        const from = fromEl.value, to = toEl.value;
        if (!from || !to) { e.preventDefault(); Swal.fire('Error', 'Please enter from and to time.', 'warning'); return; }
        const [fh,fm] = from.split(':').map(Number), [th,tm] = to.split(':').map(Number);
        const mins = (th*60+tm)-(fh*60+fm);
        if (mins <= 0) { e.preventDefault(); Swal.fire('Error', 'To time must be after From time.', 'warning'); return; }
        if (mins/60 > maxHrs) { e.preventDefault(); Swal.fire('Error', `Duration cannot exceed ${maxHrs} hours.`, 'warning'); return; }
    });

    <?php if (!empty($slSuccess)): ?>
    Swal.fire({ icon: 'success', title: 'Submitted!', text: <?= json_encode($slSuccess) ?>, confirmButtonColor: '#1977e1' });
    <?php elseif (!empty($slError)): ?>
    Swal.fire({ icon: 'error', title: 'Error', text: <?= json_encode($slError) ?>, confirmButtonColor: '#1977e1' });
    <?php endif; ?>
</script>
<?php include('../partials/_footer.php'); ?>
</body>
</html>
