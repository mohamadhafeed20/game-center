<?php
// api_shift_report.php — Generates end-of-day / shift summary Z-Report data
header('Content-Type: application/json');

try {
    $pdo = new PDO('mysql:host=localhost;dbname=gaming_center', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'DB connection failed']);
    exit;
}

// 1. Finished sessions today
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

// 2. Expenses today
$expStmt = $pdo->query("SELECT * FROM expenses WHERE DATE(created_at) = CURDATE() ORDER BY created_at DESC");
$expenses = $expStmt->fetchAll(PDO::FETCH_ASSOC);
$totalExpenses = array_sum(array_column($expenses, 'amount'));

// 3. Net Cash Drawer
$netCash = $grossRevenue - $totalExpenses;

// 4. Daily Goal Progress
$dailyGoal = 120000;
$goalPct = min(100, round(($grossRevenue / $dailyGoal) * 100, 1));

echo json_encode([
    'success'         => true,
    'date'            => date('d/m/Y'),
    'total_sessions'  => count($finishedSessions),
    'gaming_revenue'  => $totalGaming,
    'snacks_revenue'  => $totalSnacks,
    'gross_revenue'   => $grossRevenue,
    'total_expenses'  => $totalExpenses,
    'net_cash'        => $netCash,
    'category_stats'  => $categoryStats,
    'expenses_list'   => $expenses,
    'goal_percentage' => $goalPct,
    'goal_target'     => $dailyGoal,
]);
?>
