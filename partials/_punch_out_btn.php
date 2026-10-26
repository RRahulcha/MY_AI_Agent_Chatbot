<?php
$_po_userId = mysqli_real_escape_string($con, $userId);

// ── Expected punch-out ──
$_po_row = mysqli_fetch_assoc(mysqli_query(
    $con,
    "SELECT expectedPunchOut FROM signup WHERE userId='$_po_userId' LIMIT 1"
));
$_po_expectedOut = $_po_row['expectedPunchOut'] ?? '18:30:00';
$_po_expectedOutTs = strtotime(date('Y-m-d') . ' ' . $_po_expectedOut);

// ── HR early-go for today ──
$_po_egRow = mysqli_fetch_assoc(mysqli_query(
    $con,
    "SELECT early_go_time FROM early_go
     WHERE userId='$_po_userId' AND date='$today' AND hr_granted=1
     ORDER BY id DESC LIMIT 1"
));
$_po_earlyGoTs = null;
$_po_hasHrEarlyGo = false;
if ($_po_egRow && !empty($_po_egRow['early_go_time'])) {
    $_po_earlyGoTs = strtotime(date('Y-m-d') . ' ' . $_po_egRow['early_go_time']);
    $_po_hasHrEarlyGo = true;
}

// ── Effective unlock time ──
$_po_unlockTs = $_po_expectedOutTs;
if ($_po_hasHrEarlyGo && $_po_earlyGoTs < $_po_expectedOutTs) {
    $_po_unlockTs = $_po_earlyGoTs;
}

$_po_nowTs = time();
$_po_unlocked = ($_po_nowTs >= $_po_unlockTs);
$_po_secondsLeft = max(0, $_po_unlockTs - $_po_nowTs);
$_po_unlockDisp = date('h:i A', $_po_unlockTs);
?>

<?php if ($isPunchedIn): ?>
    <?php if ($hasRunning): ?>
        <div class="alert alert-warning mt-2 blink-alert">
            You have a running task. Please pause or finish it before Punch Out.
        </div>

    <?php elseif ($_po_unlocked): ?>
        <!-- ✅ Punch out is allowed now -->
        <?php if ($_po_hasHrEarlyGo && $_po_earlyGoTs < $_po_expectedOutTs): ?>
            <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:6px 12px;
                    font-size:11px;color:#92400e;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
                <i class="fas fa-user-check"></i>
                HR approved early go at <strong><?= date('h:i A', $_po_earlyGoTs) ?></strong>
            </div>
        <?php endif; ?>
        <a href="<?= $punchOutUrl ?>" class="btn-punch" id="punchOutBtn">Punch Out</a>

    <?php else: ?>
        <!-- 🔒 Locked — show countdown -->
        <?php if ($_po_hasHrEarlyGo && $_po_earlyGoTs < $_po_expectedOutTs): ?>
            <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:6px 12px;
                    font-size:11px;color:#92400e;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
                <i class="fas fa-user-check"></i>
                HR approved early go — unlocks at <strong><?= date('h:i A', $_po_earlyGoTs) ?></strong>
            </div>
        <?php endif; ?>
        <div id="punchOutLockWrap">
            <button class="btn-punch" id="punchOutLockedBtn" style="opacity:.5;cursor:not-allowed;pointer-events:none;"
                disabled>
                <i class="fas fa-lock mr-1" style="font-size:11px;"></i>
                Punch Out
            </button>
            <div id="punchOutCountdown" style="font-size:11px;color:#6b7280;margin-top:5px;text-align:center;">
                Unlocks at <strong><?= $_po_unlockDisp ?></strong>
                — <span id="countdownTimer"></span>
            </div>
        </div>

        <script>
            (function () {
                var unlockTs = <?= $_po_unlockTs ?> * 1000; // ms
                var punchUrl = <?= json_encode($punchOutUrl) ?>;
                var lockWrap = document.getElementById('punchOutLockWrap');
                var timerSpan = document.getElementById('countdownTimer');

                function tick() {
                    var diff = Math.max(0, Math.floor((unlockTs - Date.now()) / 1000));
                    if (diff <= 0) {
                        // Replace locked button with live Punch Out link
                        if (lockWrap) {
                            lockWrap.innerHTML =
                                '<a href="' + punchUrl + '" class="btn-punch" id="punchOutBtn">Punch Out</a>';
                        }
                        return;
                    }
                    var h = Math.floor(diff / 3600);
                    var m = Math.floor((diff % 3600) / 60);
                    var s = diff % 60;
                    var parts = [];
                    if (h > 0) parts.push(h + 'h');
                    if (m > 0 || h > 0) parts.push(String(m).padStart(2, '0') + 'm');
                    parts.push(String(s).padStart(2, '0') + 's');
                    if (timerSpan) timerSpan.textContent = parts.join(' ') + ' remaining';
                    setTimeout(tick, 1000);
                }
                tick();
            })();
        </script>
    <?php endif; ?>

<?php elseif ($hasPunchIn && $hasPunchOut): ?>
    <!-- Already punched out — allow re-punch-in -->
    <a href="<?= $punchInUrl ?? '#' ?>" class="btn-punch">Punch In</a>
<?php endif; ?>