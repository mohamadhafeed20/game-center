<?php
// print_receipt.php — Opens a clean printable receipt for a finished session
// Usage: print_receipt.php?session_id=42

$session_id = (int)($_GET['session_id'] ?? 0);
if (!$session_id) die('Invalid session ID.');

try {
    $pdo = new PDO('mysql:host=localhost;dbname=gaming_center', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('Database connection failed.');
}

$stmt = $pdo->prepare("
    SELECT s.*, st.station_name, st.type, st.hourly_rate
    FROM   sessions s
    JOIN   stations st ON s.station_id = st.id
    WHERE  s.id = ? AND s.status = 'finished'
");
$stmt->execute([$session_id]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$session) die('Session not found or not yet finished.');

// Add-ons
$addons = $pdo->prepare("SELECT item_name, item_price FROM add_ons WHERE session_id = ?");
$addons->execute([$session_id]);
$addons = $addons->fetchAll(PDO::FETCH_ASSOC);
$addonsTotal = array_sum(array_column($addons, 'item_price'));

// Active seconds
$start   = strtotime($session['start_time']);
$end     = strtotime($session['end_time']);
$elapsed = $end - $start - (int)$session['total_paused_seconds'];
$elapsed = max(0, $elapsed);
$hours   = floor($elapsed / 3600);
$minutes = floor(($elapsed % 3600) / 60);
$gamingCost = $session['total_cost'] - $addonsTotal;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Receipt — <?= htmlspecialchars($session['station_name']) ?></title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family: 'Courier New', monospace; font-size:13px; color:#000; background:#fff; padding:20px; max-width:320px; margin:auto; }
        .center { text-align:center; }
        .bold   { font-weight:bold; }
        .line   { border-top:1px dashed #000; margin:8px 0; }
        .row    { display:flex; justify-content:space-between; margin:3px 0; }
        .total  { font-size:16px; font-weight:bold; margin-top:6px; }
        .footer { text-align:center; margin-top:12px; font-size:11px; color:#555; }
        @media print {
            body { padding:0; }
            .no-print { display:none; }
        }
    </style>
</head>
<body>
    <div class="center bold" style="font-size:16px; margin-bottom:4px;">🎮 GAMING CENTER</div>
    <div class="center" style="font-size:11px; color:#555; margin-bottom:12px;">SESSION RECEIPT</div>

    <div class="line"></div>

    <div class="row"><span>Station:</span> <span class="bold"><?= htmlspecialchars($session['station_name']) ?></span></div>
    <div class="row"><span>Date:</span>    <span><?= date('d/m/Y', $end) ?></span></div>
    <div class="row"><span>Start:</span>   <span><?= date('H:i:s', $start) ?></span></div>
    <div class="row"><span>End:</span>     <span><?= date('H:i:s', $end) ?></span></div>
    <div class="row"><span>Duration:</span><span><?= $hours ?>h <?= $minutes ?>m</span></div>
    <div class="row"><span>Rate:</span>    <span><?= number_format($session['hourly_rate'], 0) ?> IQD/hr</span></div>

    <div class="line"></div>

    <div class="row"><span>🎮 Gaming Cost</span><span><?= number_format($gamingCost, 0) ?> IQD</span></div>

    <?php if (!empty($addons)): ?>
        <?php foreach ($addons as $a): ?>
        <div class="row" style="color:#555;">
            <span>&nbsp;&nbsp;• <?= htmlspecialchars($a['item_name']) ?></span>
            <span><?= number_format($a['item_price'], 0) ?> IQD</span>
        </div>
        <?php endforeach; ?>
        <div class="row"><span>🛒 Snacks Total</span><span><?= number_format($addonsTotal, 0) ?> IQD</span></div>
    <?php endif; ?>

    <div class="line"></div>
    <div class="row total"><span>GRAND TOTAL</span><span><?= number_format($session['total_cost'], 0) ?> IQD</span></div>

    <div class="footer">
        Session #<?= $session_id ?><br>
        Thank you for visiting! 🎮
    </div>

    <div class="no-print" style="margin-top:20px; text-align:center;">
        <button onclick="window.print()"
                style="padding:8px 20px; background:#000; color:#fff; border:none; border-radius:6px; cursor:pointer; font-size:13px;">
            🖨️ Print Receipt
        </button>
        <button onclick="window.close()"
                style="margin-left:8px; padding:8px 20px; background:#eee; border:none; border-radius:6px; cursor:pointer; font-size:13px;">
            ✕ Close
        </button>
    </div>
</body>
</html>
