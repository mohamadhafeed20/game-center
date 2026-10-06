<?php
// api_report.php — Returns today's financial breakdown (Gaming vs Snacks, PC vs PS5 vs Xbox)
header('Content-Type: application/json');

try {
    $pdo = new PDO('mysql:host=localhost;dbname=gaming_center', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'DB connection failed']);
    exit;
}

// 1. Total finished sessions today
$sessionsStmt = $pdo->query("
    SELECT s.*, st.type, st.hourly_rate
    FROM sessions s
    JOIN stations st ON s.station_id = st.id
    WHERE s.status = 'finished' AND DATE(s.end_time) = CURDATE()
");
$finishedSessions = $sessionsStmt->fetchAll(PDO::FETCH_ASSOC);

$totalGamingRevenue = 0;
$totalSnacksRevenue = 0;
$typeBreakdown = [
    'pc'   => ['sessions' => 0, 'revenue' => 0],
    'ps5'  => ['sessions' => 0, 'revenue' => 0],
    'xbox' => ['sessions' => 0, 'revenue' => 0],
];

foreach ($finishedSessions as $sess) {
    $sessionId = $sess['id'];
    
    // Calculate gaming cost for this session
    $start   = strtotime($sess['start_time']);
    $end     = strtotime($sess['end_time']);
    $elapsed = max(0, ($end - $start) - (int)$sess['total_paused_seconds']);
    $gamingCost = round($elapsed * ($sess['hourly_rate'] / 3600), -2);

    // Fetch add-ons for this session
    $addonStmt = $pdo->prepare("SELECT SUM(item_price) FROM add_ons WHERE session_id = ?");
    $addonStmt->execute([$sessionId]);
    $addonsTotal = (float)$addonStmt->fetchColumn();

    $totalGamingRevenue += $gamingCost;
    $totalSnacksRevenue += $addonsTotal;

    $type = $sess['type'];
    if (isset($typeBreakdown[$type])) {
        $typeBreakdown[$type]['sessions']++;
        $typeBreakdown[$type]['revenue'] += ($gamingCost + $addonsTotal);
    }
}

$grandTotal = $totalGamingRevenue + $totalSnacksRevenue;

echo json_encode([
    'success'             => true,
    'total_sessions'      => count($finishedSessions),
    'gaming_revenue'      => $totalGamingRevenue,
    'snacks_revenue'      => $totalSnacksRevenue,
    'grand_total'         => $grandTotal,
    'type_breakdown'      => $typeBreakdown,
]);
?>
