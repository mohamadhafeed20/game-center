<?php
// api_expenses.php — Manage cash drawer expenses and cash calculations
header('Content-Type: application/json');

try {
    $pdo = new PDO('mysql:host=localhost;dbname=gaming_center', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'DB connection failed']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($action === 'add') {
    $title    = trim($_POST['title'] ?? '');
    $amount   = (float)($_POST['amount'] ?? 0);
    $category = trim($_POST['category'] ?? 'General');

    if (!$title || $amount <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid expense details']);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO expenses (title, amount, category, created_at) VALUES (?, ?, ?, NOW())");
    $stmt->execute([$title, $amount, $category]);

    echo json_encode(['success' => true, 'message' => 'Expense recorded successfully']);
    exit;
}

// Default: fetch today's expenses and calculate net cash
$expensesStmt = $pdo->query("SELECT * FROM expenses WHERE DATE(created_at) = CURDATE() ORDER BY created_at DESC");
$expenses = $expensesStmt->fetchAll(PDO::FETCH_ASSOC);
$totalExpenses = array_sum(array_column($expenses, 'amount'));

// Today's gross revenue
$revStmt = $pdo->query("SELECT COALESCE(SUM(total_cost),0) FROM sessions WHERE status = 'finished' AND DATE(end_time) = CURDATE()");
$grossRevenue = (float)$revStmt->fetchColumn();

$netCash = $grossRevenue - $totalExpenses;

echo json_encode([
    'success'         => true,
    'expenses'        => $expenses,
    'total_expenses'  => $totalExpenses,
    'gross_revenue'   => $grossRevenue,
    'net_cash'        => $netCash,
]);
?>
