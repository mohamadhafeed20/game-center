<?php
header('Content-Type: application/json');

// ─── Database Connection ──────────────────────────────────────────────────────
try {
    $pdo = new PDO('mysql:host=localhost;dbname=gaming_center;charset=utf8mb4', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}

// Ensure Products / Inventory table exists
function ensureProductsTable($pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS products (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            name       VARCHAR(100)  NOT NULL UNIQUE,
            emoji      VARCHAR(10)   DEFAULT '📦',
            quantity   INT           NOT NULL DEFAULT 0,
            price      DECIMAL(10,2) NOT NULL,
            updated_at DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        );
    ");
}

// ─── Input Validation ─────────────────────────────────────────────────────────
$action     = $_POST['action']     ?? $_GET['action'] ?? '';
$station_id = (int)($_POST['station_id'] ?? 0);

// ── GLOBAL ACTIONS (No station_id required) ──────────────────────────────────
if ($action === 'get_products') {
    ensureProductsTable($pdo);
    $products = $pdo->query("SELECT id, name, emoji, quantity, price FROM products ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'products' => $products]);
    exit;
}

if ($action === 'receive_product') {
    ensureProductsTable($pdo);

    $name     = trim($_POST['item_name'] ?? '');
    $quantity = max(1, (int)($_POST['quantity'] ?? 1));
    $price    = max(0, (float)($_POST['item_price'] ?? 0));
    $emoji    = trim($_POST['emoji'] ?? '📦');

    if (!$name) {
        echo json_encode(['success' => false, 'message' => 'Product name is required']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, quantity FROM products WHERE LOWER(name) = LOWER(?)");
    $stmt->execute([$name]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $pdo->prepare("
            UPDATE products
            SET quantity = quantity + ?, price = ?, emoji = COALESCE(NULLIF(?, ''), emoji)
            WHERE id = ?
        ")->execute([$quantity, $price, $emoji, $existing['id']]);
    } else {
        $pdo->prepare("
            INSERT INTO products (name, emoji, quantity, price)
            VALUES (?, ?, ?, ?)
        ")->execute([$name, $emoji ?: '📦', $quantity, $price]);
    }

    $products = $pdo->query("SELECT id, name, emoji, quantity, price FROM products ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'  => true,
        'message'  => "Received +{$quantity} {$name}",
        'products' => $products
    ]);
    exit;
}

if ($action === 'clear_inventory') {
    ensureProductsTable($pdo);
    $pdo->exec("DELETE FROM products");
    echo json_encode(['success' => true, 'message' => 'All inventory cleared']);
    exit;
}

if ($action === 'clear_inventory') {
    ensureProductsTable($pdo);
    $pdo->exec("DELETE FROM products");
    echo json_encode(['success' => true, 'message' => 'All inventory cleared']);
    exit;
}

// ── REPORTING & ANALYTICS ───────────────────────────────────────────────────────

if ($action === 'peak_hours_heatmap') {
    // Get session data for heatmap (last 30 days by default)
    $days = (int)($_GET['days'] ?? 30);
    
    $stmt = $pdo->prepare("
        SELECT 
            HOUR(start_time) as hour,
            DAYOFWEEK(start_time) as day_of_week,
            COUNT(*) as session_count,
            SUM(
                TIMESTAMPDIFF(SECOND, start_time, COALESCE(end_time, NOW())) 
                - COALESCE(total_paused_seconds, 0)
            ) as total_active_seconds
        FROM sessions 
        WHERE start_time >= DATE_SUB(NOW(), INTERVAL ? DAY)
          AND status IN ('finished', 'running', 'paused')
        GROUP BY hour, day_of_week
        ORDER BY hour, day_of_week
    ");
    $stmt->execute([$days]);
    $heatmapData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Also get daily totals for the period
    $dailyStmt = $pdo->prepare("
        SELECT 
            DATE(start_time) as date,
            COUNT(*) as sessions,
            SUM(
                TIMESTAMPDIFF(SECOND, start_time, COALESCE(end_time, NOW())) 
                - COALESCE(total_paused_seconds, 0)
            ) as total_seconds,
            SUM(total_cost) as revenue
        FROM sessions 
        WHERE start_time >= DATE_SUB(NOW(), INTERVAL ? DAY)
          AND status = 'finished'
        GROUP BY DATE(start_time)
        ORDER BY date
    ");
    $dailyStmt->execute([$days]);
    $dailyData = $dailyStmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'heatmap' => $heatmapData,
        'daily' => $dailyData
    ]);
    exit;
}

if ($action === 'low_stock_alert') {
    ensureProductsTable($pdo);
    $threshold = (int)($_GET['threshold'] ?? 5);
    
    $stmt = $pdo->prepare("
        SELECT id, name, emoji, quantity, price 
        FROM products 
        WHERE quantity <= ? 
        ORDER BY quantity ASC, name ASC
    ");
    $stmt->execute([$threshold]);
    $lowStock = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'threshold' => $threshold,
        'count' => count($lowStock),
        'products' => $lowStock
    ]);
    exit;
}

if ($action === 'export_report') {
    $format = $_GET['format'] ?? 'csv'; // csv or excel
    $type = $_GET['type'] ?? 'daily'; // daily, monthly, sessions
    $date = $_GET['date'] ?? date('Y-m-d');
    
    if ($type === 'daily') {
        $stmt = $pdo->prepare("
            SELECT 
                s.id,
                st.station_name,
                st.type,
                s.start_time,
                s.end_time,
                s.total_cost,
                s.session_mode,
                TIMESTAMPDIFF(SECOND, s.start_time, s.end_time) - COALESCE(s.total_paused_seconds, 0) as active_seconds,
                GROUP_CONCAT(CONCAT(a.item_name, ':', a.item_price) SEPARATOR '|') as addons
            FROM sessions s
            JOIN stations st ON s.station_id = st.id
            LEFT JOIN add_ons a ON a.session_id = s.id
            WHERE DATE(s.end_time) = ? AND s.status = 'finished'
            GROUP BY s.id
            ORDER BY s.end_time
        ");
        $stmt->execute([$date]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $filename = "daily_report_" . $date;
    } elseif ($type === 'monthly') {
        $stmt = $pdo->prepare("
            SELECT 
                DATE(s.end_time) as date,
                COUNT(*) as sessions,
                SUM(TIMESTAMPDIFF(SECOND, s.start_time, s.end_time) - COALESCE(s.total_paused_seconds, 0)) as total_seconds,
                SUM(s.total_cost) as revenue,
                SUM(CASE WHEN st.type = 'pc' THEN s.total_cost ELSE 0 END) as pc_revenue,
                SUM(CASE WHEN st.type = 'ps5' THEN s.total_cost ELSE 0 END) as ps5_revenue,
                SUM(CASE WHEN st.type = 'xbox' THEN s.total_cost ELSE 0 END) as xbox_revenue
            FROM sessions s
            JOIN stations st ON s.station_id = st.id
            WHERE DATE(s.end_time) BETWEEN DATE_SUB(LAST_DAY(?), INTERVAL DAY(LAST_DAY(?))-1 DAY) AND LAST_DAY(?)
              AND s.status = 'finished'
            GROUP BY DATE(s.end_time)
            ORDER BY date
        ");
        $stmt->execute([$date, $date, $date]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $filename = "monthly_report_" . date('Y-m', strtotime($date));
    } else { // sessions
        $stmt = $pdo->prepare("
            SELECT 
                s.id,
                st.station_name,
                st.type,
                s.start_time,
                s.end_time,
                s.total_cost,
                s.session_mode,
                TIMESTAMPDIFF(SECOND, s.start_time, s.end_time) - COALESCE(s.total_paused_seconds, 0) as active_seconds
            FROM sessions s
            JOIN stations st ON s.station_id = st.id
            WHERE s.status = 'finished'
            ORDER BY s.end_time DESC
            LIMIT 1000
        ");
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $filename = "all_sessions_" . date('Y-m-d');
    }
    
    if ($format === 'excel') {
        // Generate HTML table for Excel
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
        
        echo "<table border='1'>";
        if (!empty($data)) {
            echo "<tr>";
            foreach (array_keys($data[0]) as $col) {
                echo "<th>" . htmlspecialchars($col) . "</th>";
            }
            echo "</tr>";
            foreach ($data as $row) {
                echo "<tr>";
                foreach ($row as $val) {
                    echo "<td>" . htmlspecialchars($val ?? '') . "</td>";
                }
                echo "</tr>";
            }
        }
        echo "</table>";
    } else {
        // CSV
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        
        $output = fopen('php://output', 'w');
        if (!empty($data)) {
            fputcsv($output, array_keys($data[0]));
            foreach ($data as $row) {
                fputcsv($output, $row);
            }
        }
        fclose($output);
    }
    exit;
}

// ── STATION ACTIONS (Require station_id) ──────────────────────────────────────
if (!$station_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid station ID']);
    exit;
}

// ── 1. START ──────────────────────────────────────────────────────────────────
if ($action === 'start') {

    $session_mode    = in_array($_POST['session_mode'] ?? '', ['open', 'prepaid'])
                       ? $_POST['session_mode'] : 'open';
    $prepaid_seconds = ($session_mode === 'prepaid')
                       ? max(0, (int)($_POST['prepaid_seconds'] ?? 0)) : null;

    $pdo->prepare("
        INSERT INTO sessions (station_id, status, session_mode, prepaid_seconds, start_time)
        VALUES (?, 'running', ?, ?, NOW())
    ")->execute([$station_id, $session_mode, $prepaid_seconds]);

    $pdo->prepare("UPDATE stations SET status = 'occupied' WHERE id = ?")
        ->execute([$station_id]);

    echo json_encode([
        'success'         => true,
        'message'         => 'Session started',
        'session_mode'    => $session_mode,
        'prepaid_seconds' => $prepaid_seconds,
    ]);

// ── 2. PAUSE ──────────────────────────────────────────────────────────────────
} elseif ($action === 'pause') {

    $pdo->prepare("
        UPDATE sessions
        SET    status = 'paused', pause_start_time = NOW()
        WHERE  station_id = ? AND status = 'running'
    ")->execute([$station_id]);

    $pdo->prepare("UPDATE stations SET status = 'paused' WHERE id = ?")
        ->execute([$station_id]);

    echo json_encode(['success' => true, 'message' => 'Session paused']);

// ── 3. RESUME ─────────────────────────────────────────────────────────────────
} elseif ($action === 'resume') {

    $stmt = $pdo->prepare("
        SELECT id, total_paused_seconds,
               TIMESTAMPDIFF(SECOND, pause_start_time, NOW()) AS pause_duration
        FROM   sessions
        WHERE  station_id = ? AND status = 'paused'
    ");
    $stmt->execute([$station_id]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($session) {
        $pauseDuration  = (int)$session['pause_duration'];
        $newTotalPaused = (int)$session['total_paused_seconds'] + $pauseDuration;

        $pdo->prepare("
            UPDATE sessions
            SET    status = 'running',
                   total_paused_seconds = ?,
                   pause_start_time = NULL
            WHERE  id = ?
        ")->execute([$newTotalPaused, $session['id']]);

        $pdo->prepare("UPDATE stations SET status = 'occupied' WHERE id = ?")
            ->execute([$station_id]);

        echo json_encode(['success' => true, 'message' => 'Session resumed']);
    } else {
        echo json_encode(['success' => false, 'message' => 'No paused session found']);
    }

// ── 4. STOP ───────────────────────────────────────────────────────────────────
} elseif ($action === 'stop') {

    $stmt = $pdo->prepare("
        SELECT s.id               AS session_id,
               s.status           AS session_status,
               st.hourly_rate,
               GREATEST(0,
                   TIMESTAMPDIFF(SECOND, s.start_time, NOW())
                   - COALESCE(s.total_paused_seconds, 0)
                   - IF(s.status = 'paused', TIMESTAMPDIFF(SECOND, s.pause_start_time, NOW()), 0)
               ) AS active_seconds
        FROM   sessions  s
        JOIN   stations  st ON s.station_id = st.id
        WHERE  s.station_id = ?
          AND  s.status IN ('running', 'paused')
    ");
    $stmt->execute([$station_id]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($session) {
        $activeSeconds = (int)$session['active_seconds'];

        // Gaming cost — rounded to nearest 100 IQD
        $gamingCost = round($activeSeconds * ($session['hourly_rate'] / 3600), -2);

        // Fetch all add-ons for this session
        $addonStmt = $pdo->prepare("SELECT item_name, item_price FROM add_ons WHERE session_id = ?");
        $addonStmt->execute([$session['session_id']]);
        $addons      = $addonStmt->fetchAll(PDO::FETCH_ASSOC);
        $addonsTotal = (float)array_sum(array_column($addons, 'item_price'));

        $grandTotal = $gamingCost + $addonsTotal;

        $pdo->prepare("
            UPDATE sessions
            SET    status = 'finished', end_time = NOW(), total_cost = ?
            WHERE  id = ?
        ")->execute([$grandTotal, $session['session_id']]);

        $pdo->prepare("UPDATE stations SET status = 'available' WHERE id = ?")
            ->execute([$station_id]);

        $hours   = floor($activeSeconds / 3600);
        $minutes = floor(($activeSeconds % 3600) / 60);

        echo json_encode([
            'success'      => true,
            'session_id'   => (int)$session['session_id'],
            'active_time'  => "{$hours}h {$minutes}m",
            'gaming_cost'  => number_format($gamingCost,  0) . ' IQD',
            'addons_total' => number_format($addonsTotal, 0) . ' IQD',
            'grand_total'  => number_format($grandTotal,  0) . ' IQD',
            'addons_list'  => $addons,
            'has_addons'   => count($addons) > 0,
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No active session found']);
    }

// ── 5. ADD ADDON ──────────────────────────────────────────────────────────────
} elseif ($action === 'add_addon') {

    $item_name  = trim($_POST['item_name']  ?? '');
    $item_price = (float)($_POST['item_price'] ?? 0);

    if (!$item_name || $item_price <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid item data']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT id FROM sessions
        WHERE  station_id = ? AND status IN ('running', 'paused')
    ");
    $stmt->execute([$station_id]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($session) {
        $pdo->prepare("INSERT INTO add_ons (session_id, item_name, item_price) VALUES (?, ?, ?)")
            ->execute([$session['id'], $item_name, $item_price]);

        // Decrement inventory stock
        ensureProductsTable($pdo);
        $pdo->prepare("UPDATE products SET quantity = GREATEST(0, quantity - 1) WHERE LOWER(name) = LOWER(?)")
            ->execute([$item_name]);

        $products = $pdo->query("SELECT id, name, emoji, quantity, price FROM products ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success'  => true,
            'message'  => 'Item added',
            'products' => $products
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No active session found for this station']);
    }

// ── 6. TRANSFER STATION ───────────────────────────────────────────────────────
} elseif ($action === 'transfer') {
    $new_station_id = (int)($_POST['new_station_id'] ?? 0);
    if (!$new_station_id || $new_station_id === $station_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid target station']);
        exit;
    }

    $targetStmt = $pdo->prepare("SELECT status FROM stations WHERE id = ?");
    $targetStmt->execute([$new_station_id]);
    $target = $targetStmt->fetch(PDO::FETCH_ASSOC);

    if (!$target || $target['status'] !== 'available') {
        echo json_encode(['success' => false, 'message' => 'Target station is not available']);
        exit;
    }

    $sessStmt = $pdo->prepare("SELECT id, status FROM sessions WHERE station_id = ? AND status IN ('running','paused')");
    $sessStmt->execute([$station_id]);
    $session = $sessStmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        echo json_encode(['success' => false, 'message' => 'No active session found on source station']);
        exit;
    }

    $sessionStatus = $session['status'];

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE stations SET status = 'available' WHERE id = ?")->execute([$station_id]);
        $pdo->prepare("UPDATE stations SET status = ? WHERE id = ?")->execute([$sessionStatus, $new_station_id]);
        $pdo->prepare("UPDATE sessions SET station_id = ? WHERE id = ?")->execute([$new_station_id, $session['id']]);

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Session transferred successfully', 'new_station_id' => $new_station_id]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Transfer failed: ' . $e->getMessage()]);
    }

} else {
    echo json_encode(['success' => false, 'message' => 'Unknown action']);
}
?>
