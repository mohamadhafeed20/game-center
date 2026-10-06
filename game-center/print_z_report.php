<?php
// print_z_report.php — Printable Shift Z-Report / End-of-Day Summary
try {
    $pdo = new PDO('mysql:host=localhost;dbname=gaming_center', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die('Database connection failed.');
}

// Fetch report data
$sessionsStmt = $pdo->query("
    SELECT s.*, st.station_name, st.type, st.hourly_rate
    FROM sessions s
    JOIN stations st ON s.station_id = st.id
    WHERE s.status = 'finished' AND DATE(s.end_time) = CURDATE()
");
$finishedSessions = $sessionsStmt->fetchAll(PDO::FETCH_ASSOC);

$totalGaming = 0;
$totalSnacks = 0;
$categoryStats = [
    'pc'   => ['count' => 0, 'revenue' => 0],
    'ps5'  => ['count' => 0, 'revenue' => 0],
    'xbox' => ['count' => 0, 'revenue' => 0],
];

foreach ($finishedSessions as $sess) {
    $start   = strtotime($sess['start_time']);
    $end     = strtotime($sess['end_time']);
    $elapsed = max(0, ($end - $start) - (int)$sess['total_paused_seconds']);
    $gamingCost = round($elapsed * ($sess['hourly_rate'] / 3600), -2);

    $addonStmt = $pdo->prepare("SELECT SUM(item_price) FROM add_ons WHERE session_id = ?");
    $addonStmt->execute([$sess['id']]);
    $addonsTotal = (float)$addonStmt->fetchColumn();

    $totalGaming += $gamingCost;
    $totalSnacks += $addonsTotal;

    $type = $sess['type'];
    if (isset($categoryStats[$type])) {
        $categoryStats[$type]['count']++;
        $categoryStats[$type]['revenue'] += ($gamingCost + $addonsTotal);
    }
}

$grossRevenue = $totalGaming + $totalSnacks;

$expStmt = $pdo->query("SELECT * FROM expenses WHERE DATE(created_at) = CURDATE() ORDER BY created_at DESC");
$expenses = $expStmt->fetchAll(PDO::FETCH_ASSOC);
$totalExpenses = array_sum(array_column($expenses, 'amount'));
$netCash = $grossRevenue - $totalExpenses;
$dailyGoal = 120000;
$goalPct = min(100, round(($grossRevenue / $dailyGoal) * 100, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Shift Z-Report — <?= date('d/m/Y') ?></title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family: 'Courier New', monospace; font-size:12px; color:#000; background:#fff; padding:20px; max-width:320px; margin:auto; }
        .center { text-align:center; }
        .bold   { font-weight:bold; }
        .line   { border-top:1px dashed #000; margin:8px 0; }
        .row    { display:flex; justify-content:space-between; margin:3px 0; }
        .total  { font-size:15px; font-weight:bold; margin-top:6px; }
        .footer { text-align:center; margin-top:12px; font-size:11px; color:#555; }
        @media print {
            body { padding:0; }
            .no-print { display:none; }
        }
    </style>
</head>
<body>
    <div class="center bold" style="font-size:15px; margin-bottom:2px;">🎮 GAMING CENTER</div>
    <div class="center bold" style="font-size:13px; margin-bottom:4px;">SHIFT Z-REPORT (END OF DAY)</div>
    <div class="center" style="font-size:11px; color:#555; margin-bottom:10px;">Date: <?= date('d/m/Y H:i') ?></div>

    <div class="line"></div>

    <div class="row"><span>Total Sessions:</span> <span class="bold"><?= count($finishedSessions) ?></span></div>
    <div class="row"><span>Daily Goal:</span>   <span><?= number_format($dailyGoal, 0) ?> IQD</span></div>
    <div class="row"><span>Goal Progress:</span><span class="bold"><?= $goalPct ?>%</span></div>

    <div class="line"></div>
    <div class="bold" style="margin-bottom:3px;">REVENUE BREAKDOWN:</div>
    <div class="row"><span>• Gaming Revenue</span><span><?= number_format($totalGaming, 0) ?> IQD</span></div>
    <div class="row"><span>• Cafe & Snacks</span><span><?= number_format($totalSnacks, 0) ?> IQD</span></div>
    <div class="row bold"><span>GROSS REVENUE</span><span><?= number_format($grossRevenue, 0) ?> IQD</span></div>

    <div class="line"></div>
    <div class="bold" style="margin-bottom:3px;">CATEGORY STATS:</div>
    <div class="row"><span>• PCs (<?= $categoryStats['pc']['count'] ?> sess)</span><span><?= number_format($categoryStats['pc']['revenue'], 0) ?> IQD</span></div>
    <div class="row"><span>• PS5s (<?= $categoryStats['ps5']['count'] ?> sess)</span><span><?= number_format($categoryStats['ps5']['revenue'], 0) ?> IQD</span></div>
    <div class="row"><span>• Xbox (<?= $categoryStats['xbox']['count'] ?> sess)</span><span><?= number_format($categoryStats['xbox']['revenue'], 0) ?> IQD</span></div>

    <div class="line"></div>
    <div class="bold" style="margin-bottom:3px;">EXPENSES / CASH OUT:</div>
    <?php if (empty($expenses)): ?>
    <div style="color:#555; font-size:11px; text-align:center;">No expenses recorded.</div>
    <?php else: ?>
        <?php foreach ($expenses as $e): ?>
        <div class="row" style="font-size:11px;">
            <span>• <?= htmlspecialchars($e['title']) ?></span>
            <span>-<?= number_format($e['amount'], 0) ?> IQD</span>
        </div>
        <?php endforeach; ?>
        <div class="row"><span>Total Expenses:</span><span>-<?= number_format($totalExpenses, 0) ?> IQD</span></div>
    <?php endif; ?>

    <div class="line"></div>
    <div class="row total"><span>NET CASH DRAWER</span><span><?= number_format($netCash, 0) ?> IQD</span></div>

    <div class="footer">
        End of Shift Report<br>
        Gaming Center Management System 🎮
    </div>

    <div class="no-print" style="margin-top:20px; text-align:center;">
        <button onclick="window.print()"
                style="padding:8px 20px; background:#000; color:#fff; border:none; border-radius:6px; cursor:pointer; font-size:13px;">
            🖨️ Print Z-Report
        </button>
        <button onclick="window.close()"
                style="margin-left:8px; padding:8px 20px; background:#eee; border:none; border-radius:6px; cursor:pointer; font-size:13px;">
            ✕ Close
        </button>
    </div>
</body>
</html>
