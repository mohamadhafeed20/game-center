<?php
// api_refresh.php — Called every 30s by the frontend to sync live state from DB
header('Content-Type: application/json');

try {
    $pdo = new PDO('mysql:host=localhost;dbname=gaming_center;charset=utf8mb4', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['success' => false]);
    exit;
}

// Summary stats
$activeCount  = (int)$pdo->query("SELECT COUNT(*) FROM sessions WHERE status IN ('running','paused')")->fetchColumn();
$dailyRevenue = (float)$pdo->query("SELECT COALESCE(SUM(total_cost),0) FROM sessions WHERE DATE(end_time) = CURDATE()")->fetchColumn();

// Per-station live state: status + elapsed active seconds so timer can resync
$stmt = $pdo->query("
    SELECT st.id,
           st.status,
           s.id              AS session_id,
           s.session_mode,
           s.prepaid_seconds,
           s.status          AS session_status,
           TIMESTAMPDIFF(SECOND, s.start_time, NOW()) - COALESCE(s.total_paused_seconds,0)
               - IF(s.status='paused', TIMESTAMPDIFF(SECOND, s.pause_start_time, NOW()), 0)
               AS active_seconds
    FROM   stations st
    LEFT JOIN sessions s ON s.station_id = st.id AND s.status IN ('running','paused')
");

$stations = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $stations[$row['id']] = [
        'status'          => $row['status'],
        'session_mode'    => $row['session_mode'],
        'prepaid_seconds' => $row['prepaid_seconds'] ? (int)$row['prepaid_seconds'] : null,
        'active_seconds'  => max(0, (int)$row['active_seconds']),
    ];
}

// Today's finished sessions for the history log
$history = $pdo->query("
    SELECT s.id, st.station_name, st.type, s.start_time, s.end_time,
           s.total_cost,
           TIMESTAMPDIFF(MINUTE, s.start_time, s.end_time)
               - FLOOR(s.total_paused_seconds / 60) AS active_minutes
    FROM   sessions s
    JOIN   stations st ON s.station_id = st.id
    WHERE  s.status = 'finished'
      AND  DATE(s.end_time) = CURDATE()
    ORDER  BY s.end_time DESC
    LIMIT  30
")->fetchAll(PDO::FETCH_ASSOC);

// Products / Inventory catalog
$products = [];
try {
    $products = $pdo->query("SELECT id, name, emoji, quantity, price FROM products ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

echo json_encode([
    'success'       => true,
    'active_count'  => $activeCount,
    'daily_revenue' => $dailyRevenue,
    'stations'      => $stations,
    'history'       => $history,
    'products'      => $products,
]);
?>
