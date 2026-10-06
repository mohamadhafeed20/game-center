<?php
// Ensure UTF-8 encoding is sent to browser
header('Content-Type: text/html; charset=utf-8');

// --- Database Connection ------------------------------------------------------
try {
    $pdo = new PDO('mysql:host=localhost;dbname=gaming_center;charset=utf8mb4', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// --- Ensure Products Table & Initial Catalog ---------------------------------
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

$products = $pdo->query("SELECT id, name, emoji, quantity, price FROM products ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$productsDataJson = json_encode($products);

// --- Header Summary Stats -----------------------------------------------------
$activeCount  = (int)$pdo->query("SELECT COUNT(*) FROM sessions WHERE status IN ('running','paused')")->fetchColumn();
$dailyRevenue = (float)$pdo->query("SELECT COALESCE(SUM(total_cost),0) FROM sessions WHERE DATE(end_time)=CURDATE()")->fetchColumn();
$utilization  = round(($activeCount / 16) * 100, 1);

// --- Stations -----------------------------------------------------------------
$stmt     = $pdo->query("SELECT * FROM stations ORDER BY FIELD(type,'pc','ps5','xbox'), id");
$stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
$pcs      = array_filter($stations, fn($s) => $s['type'] === 'pc');
$ps5s     = array_filter($stations, fn($s) => $s['type'] === 'ps5');
$xboxs    = array_filter($stations, fn($s) => $s['type'] === 'xbox');

// --- Today's Session History --------------------------------------------------
$history = $pdo->query("
    SELECT s.id, st.station_name, st.type, s.start_time, s.end_time,
           s.total_cost, s.session_mode,
           GREATEST(0,
               TIMESTAMPDIFF(SECOND, s.start_time, s.end_time) - COALESCE(s.total_paused_seconds,0)
           ) AS active_seconds
    FROM   sessions s
    JOIN   stations st ON s.station_id = st.id
    WHERE  s.status = 'finished' AND DATE(s.end_time) = CURDATE()
    ORDER  BY s.end_time DESC LIMIT 30
")->fetchAll(PDO::FETCH_ASSOC);

// --- Active session elapsed seconds (for timer resync after page refresh) -----
$activeSessions = $pdo->query("
    SELECT st.id AS station_id,
           s.session_mode,
           s.prepaid_seconds,
           s.status AS session_status,
           GREATEST(0,
               TIMESTAMPDIFF(SECOND, s.start_time, NOW())
               - COALESCE(s.total_paused_seconds,0)
               - IF(s.status='paused', TIMESTAMPDIFF(SECOND,s.pause_start_time,NOW()), 0)
           ) AS elapsed_seconds
    FROM   sessions s
    JOIN   stations st ON s.station_id = st.id
    WHERE  s.status IN ('running','paused')
")->fetchAll(PDO::FETCH_ASSOC);

$sessionDataJson = json_encode(array_column($activeSessions, null, 'station_id'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gaming Center — Admin Panel</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        darkBg:     '#080d16',
                        cardBg:     '#0f1623',
                        cardBorder: '#1a2234'
                    },
                    fontFamily: { mono: ['"JetBrains Mono"','ui-monospace','monospace'] }
                }
            }
        }
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
    <script>
        // Global state objects & core helpers available immediately
        const activeTimers  = {};
        const stationAddons = {};
        const lastSessionId = {};
        let selectedStationId = null;
        let selectedMode      = 'open';
        let addonStationId    = null;
        let transferSourceId  = null;

        function showModal(id) {
            const el = document.getElementById(id);
            if (el) el.classList.replace('hidden', 'flex');
        }
        function hideModal(id) {
            const el = document.getElementById(id);
            if (el) el.classList.replace('flex', 'hidden');
        }

        function showToast(msg, type = 'success') {
            const palette = {
                success:'bg-green-800 border-green-700',
                error:  'bg-red-800 border-red-700',
                info:   'bg-red-900 border-red-700',
                warning:'bg-yellow-700 border-yellow-600',
            };
            let container = document.getElementById('toast-container');
            if (!container) {
                container = document.createElement('div');
                container.id = 'toast-container';
                container.className = 'fixed bottom-5 right-5 z-[100] space-y-2 pointer-events-none';
                document.body.appendChild(container);
            }
            const t = document.createElement('div');
            t.className = `toast-enter pointer-events-auto ${palette[type]} border text-white text-xs px-4 py-2.5 rounded-xl shadow-2xl`;
            t.innerText = msg;
            container.appendChild(t);
            setTimeout(() => { t.style.transition='opacity .3s,transform .3s'; t.style.opacity='0'; t.style.transform='translateX(16px)'; setTimeout(()=>t.remove(),300); }, 2800);
        }

        function openStartModal(id) {
            selectedStationId = id;
            const nameEl = document.querySelector(`#card-${id} h3`);
            const modalNameEl = document.getElementById('modal-station-name');
            if (modalNameEl && nameEl) modalNameEl.innerText = nameEl.innerText;
            selectMode('open');
            showModal('start-modal');
        }
        function closeStartModal() { hideModal('start-modal'); }

        function controlSession(id, action, opts={}) {
            const fd = new FormData();
            fd.append('action',action); fd.append('station_id',id);
            if (opts.session_mode)    fd.append('session_mode',    opts.session_mode);
            if (opts.prepaid_seconds) fd.append('prepaid_seconds', opts.prepaid_seconds);
            fetch('backend.php',{method:'POST',body:fd}).then(r=>r.json()).then(data=>{
                if (data.success) updateCardUI(id,action,data,opts);
                else showToast('Error: '+data.message,'error');
            }).catch(err=>{console.error(err); updateCardUI(id,action,{},opts);});
        }
    </script>
    <style>
/* Light Mode Colors */
body.light-mode {
    --darkBg: #f8fafc;
    --cardBg: #ffffff;
    --cardBorder: #e2e8f0;
}

body.light-mode {
    background-color: var(--darkBg) !important;
    color: #1e293b !important;
}

body.light-mode .bg-darkBg { background-color: var(--darkBg) !important; }
body.light-mode .bg-cardBg { background-color: var(--cardBg) !important; }
body.light-mode .border-cardBorder { border-color: var(--cardBorder) !important; }
body.light-mode .text-white { color: #1e293b !important; }
body.light-mode .text-gray-100 { color: #334155 !important; }
body.light-mode .text-gray-200 { color: #475569 !important; }
body.light-mode .text-gray-300 { color: #64748b !important; }
body.light-mode .text-gray-400 { color: #94a3b8 !important; }
body.light-mode .text-gray-500 { color: #94a3b8 !important; }
body.light-mode .text-gray-600 { color: #64748b !important; }
body.light-mode .text-gray-700 { color: #475569 !important; }
body.light-mode .text-gray-800 { color: #334155 !important; }
body.light-mode .text-gray-900 { color: #1e293b !important; }
body.light-mode .bg-gray-800 { background-color: #e2e8f0 !important; }
body.light-mode .bg-gray-900 { background-color: #f1f5f9 !important; }
body.light-mode .bg-gray-950 { background-color: #f8fafc !important; }
body.light-mode .border-gray-700 { border-color: #cbd5e1 !important; }
body.light-mode .border-gray-800 { border-color: #e2e8f0 !important; }
body.light-mode .hover\:bg-gray-700:hover { background-color: #cbd5e1 !important; }
body.light-mode .hover\:bg-gray-800:hover { background-color: #e2e8f0 !important; }
body.light-mode .focus\:border-red-500:focus { border-color: #ef4444 !important; }
body.light-mode .shadow-black\/30 { box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1) !important; }
body.light-mode .shadow-red-900\/30 { box-shadow: 0 4px 6px -1px rgba(239, 68, 68, 0.3), 0 2px 4px -2px rgba(239, 68, 68, 0.2) !important; }
body.light-mode .shadow-red-900\/40 { box-shadow: 0 4px 6px -1px rgba(239, 68, 68, 0.4) !important; }
body.light-mode .shadow-purple-900\/30 { box-shadow: 0 4px 6px -1px rgba(168, 85, 247, 0.3), 0 2px 4px -2px rgba(168, 85, 247, 0.2) !important; }
body.light-mode .bg-red-950\/40 { background-color: #fef2f2 !important; }
body.light-mode .border-red-900\/50 { border-color: #fecaca !important; }
body.light-mode .text-red-300 { color: #ef4444 !important; }
body.light-mode .text-red-400 { color: #f87171 !important; }
body.light-mode .text-red-500 { color: #ef4444 !important; }
body.light-mode .text-red-600 { color: #dc2626 !important; }
body.light-mode .bg-red-500\/15 { background-color: #fee2e2 !important; }
body.light-mode .border-red-500\/25 { border-color: #fecaca !important; }
body.light-mode .text-yellow-400 { color: #fbbf24 !important; }
body.light-mode .bg-yellow-500\/15 { background-color: #fef9c3 !important; }
body.light-mode .border-yellow-500\/25 { border-color: #fde047 !important; }
body.light-mode .text-green-400 { color: #22c55e !important; }
body.light-mode .bg-green-500\/15 { background-color: #dcfce7 !important; }
body.light-mode .border-green-500\/25 { border-color: #86efac !important; }
body.light-mode .bg-purple-600 { background-color: #9333ea !important; }
body.light-mode .hover\:bg-purple-500:hover { background-color: #a855f7 !important; }
body.light-mode .shadow-purple-900\/30 { box-shadow: 0 4px 6px -1px rgba(147, 51, 234, 0.3) !important; }
body.light-mode .bg-zinc-800 { background-color: #e2e8f0 !important; }
body.light-mode .bg-zinc-900 { background-color: #f1f5f9 !important; }
body.light-mode .hover\:bg-zinc-700:hover { background-color: #cbd5e1 !important; }
body.light-mode .hover\:bg-zinc-800:hover { background-color: #e2e8f0 !important; }
body.light-mode .text-zinc-300 { color: #64748b !important; }
body.light-mode .border-zinc-700 { border-color: #cbd5e1 !important; }
body.light-mode .text-rose-400 { color: #fb7185 !important; }
body.light-mode .text-rose-300 { color: #fda4af !important; }

/* Modals in light mode */
body.light-mode .modal-inner {
    background-color: var(--cardBg) !important;
    border-color: var(--cardBorder) !important;
}

/* Inputs in light mode */
body.light-mode input,
body.light-mode select,
body.light-mode textarea {
    background-color: #f1f5f9 !important;
    border-color: #cbd5e1 !important;
    color: #1e293b !important;
}
body.light-mode input:focus,
body.light-mode select:focus,
body.light-mode textarea:focus {
    border-color: #ef4444 !important;
}
body.light-mode input::placeholder {
    color: #94a3b8 !important;
}

/* Scrollbar in light mode */
body.light-mode ::-webkit-scrollbar-thumb {
    background-color: #cbd5e1 !important;
}
body.light-mode ::-webkit-scrollbar-track {
    background-color: #f1f5f9 !important;
}

/* Toast in light mode */
body.light-mode .toast-enter {
    box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -4px rgba(0, 0, 0, 0.1) !important;
}
</style>
<link rel="stylesheet" href="index.css">
</head>
<body class="bg-darkBg text-white min-h-screen" style="font-family:'Inter',system-ui,sans-serif;">

<!-- =========================================================================== -->
<!-- HEADER                                                                     -->
<!-- =========================================================================== -->
<header class="bg-cardBg border-b border-cardBorder px-4 sm:px-6 py-3 shadow-xl sticky top-0 z-30">
    <div class="max-w-[1700px] mx-auto flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3">

        <!-- Brand -->
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl bg-red-600 flex items-center justify-center text-lg shadow-lg shadow-red-900/40">🎮</div>
            <div>
                <h1 class="text-base font-bold tracking-wide leading-none">GAMING CENTER</h1>
                <p class="text-[10px] text-gray-500 tracking-widest uppercase leading-none mt-0.5">Admin Control Panel</p>
            </div>
        </div>

        <!-- Stats + Sync -->
        <div class="flex items-center gap-2 flex-wrap">

            <!-- Active -->
            <div class="stat-pill flex items-center gap-2 bg-gray-900 border border-gray-800 rounded-xl px-3 py-2">
                <span class="w-1.5 h-1.5 rounded-full bg-red-500 animate-pulse"></span>
                <span class="text-gray-400 text-xs">Active</span>
                <span class="text-white font-bold text-sm font-mono" id="live-active"><?= $activeCount ?></span>
                <span class="text-gray-700 text-xs">/16</span>
            </div>

            <!-- Utilization -->
            <div class="stat-pill flex items-center gap-2 bg-gray-900 border border-gray-800 rounded-xl px-3 py-2">
                <span class="text-gray-400 text-xs">Capacity</span>
                <span class="text-yellow-400 font-bold text-sm font-mono" id="live-util"><?= $utilization ?>%</span>
            </div>

            <!-- Revenue -->
            <div class="stat-pill flex items-center gap-2 bg-gray-900 border border-gray-800 rounded-xl px-3 py-2">
                <span class="text-gray-400 text-xs">Today</span>
                <span class="text-green-400 font-bold text-sm font-mono" id="live-revenue"><?= number_format($dailyRevenue,0) ?></span>
                <span class="text-gray-600 text-xs">IQD</span>
            </div>

            <?php
                $dailyGoal = 120000;
                $goalPct = min(100, round(($dailyRevenue / $dailyGoal) * 100));
            ?>
            <!-- Daily Goal (120,000 IQD) -->
            <div class="stat-pill flex items-center gap-3 bg-gray-900 border border-gray-800 rounded-xl px-3.5 py-2">
                <div>
                    <div class="flex justify-between items-center text-[10px] text-gray-400 mb-1">
                        <span>🎯 Goal: 120k IQD</span>
                        <span id="goal-pct" class="font-mono text-red-400 font-bold"><?= $goalPct ?>%</span>
                    </div>
                    <div class="w-28 h-2 bg-gray-800 rounded-full overflow-hidden">
                        <div id="goal-bar" class="h-full bg-gradient-to-r from-red-600 to-red-400 transition-all duration-500 rounded-full" style="width: <?= $goalPct ?>%"></div>
                    </div>
                </div>
            </div>

            <!-- Report Button -->
            <button onclick="openReportModal()"
                    class="bg-red-600 hover:bg-red-500 text-white text-xs font-bold px-3 py-2.5 rounded-xl transition flex items-center gap-1.5 shadow-lg shadow-red-900/30">
                📊 Report
            </button>

            <!-- Cash Drawer / Expenses Button -->
            <button onclick="openExpenseModal()"
                    class="bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 text-white text-xs font-bold px-3 py-2.5 rounded-xl transition flex items-center gap-1.5 shadow-lg shadow-black/30">
                💲 Cash Drawer
            </button>

            <!-- Receive Products Button -->
            <button onclick="openInventoryModal()"
                    class="bg-purple-600 hover:bg-purple-500 text-white text-xs font-bold px-3 py-2.5 rounded-xl transition flex items-center gap-1.5 shadow-lg shadow-purple-900/30 cursor-pointer">
                📦 Receive Products
            </button>

            <!-- Analytics Button -->
            <button onclick="openAnalyticsModal()"
                    class="bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold px-3 py-2.5 rounded-xl transition flex items-center gap-1.5 shadow-lg shadow-indigo-900/30 cursor-pointer">
                📈 Analytics
            </button>

            <!-- Theme Toggle Button -->
            <button onclick="toggleTheme()" id="theme-toggle-btn"
                    class="bg-zinc-800 hover:bg-zinc-700 border border-zinc-700 text-white text-xs font-bold px-3 py-2.5 rounded-xl transition flex items-center gap-1.5 shadow-lg shadow-black/30">
                ☀️ Light Mode
            </button>

            <!-- Sync indicator -->
            <div class="flex items-center gap-1.5 text-xs text-gray-600 pl-1" title="Auto-syncs every 30s">
                <span id="sync-dot" class="w-1.5 h-1.5 rounded-full bg-gray-700"></span>
                <span id="sync-label">Synced</span>
            </div>
        </div>
    </div>
</header>

<!-- =========================================================================== -->
<!-- MAIN CONTENT                                                               -->
<!-- =========================================================================== -->
<main class="p-4 sm:p-6 space-y-8 max-w-[1700px] mx-auto">

    <!-- -- PCs --------------------------------------------------------------- -->
    <section>
        <div class="flex items-center gap-3 mb-4 pb-2 border-b border-gray-800/70">
            <h2 class="text-sm font-bold text-red-400 uppercase tracking-widest">💻 PCs</h2>
            <span class="text-[11px] bg-red-500/10 text-red-400 border border-red-500/20 px-2 py-0.5 rounded-full">
                10 Stations · 4,000 IQD/hr
            </span>
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-5 gap-3">
            <?php foreach ($pcs as $s): ?><?php renderCard($s, $activeSessions); ?><?php endforeach; ?>
        </div>
    </section>

    <!-- -- PS5s --------------------------------------------------------------- -->
    <section>
        <div class="flex items-center gap-3 mb-4 pb-2 border-b border-gray-800/70">
            <h2 class="text-sm font-bold text-red-400 uppercase tracking-widest">🎮 PS5s</h2>
            <span class="text-[11px] bg-red-500/10 text-red-400 border border-red-500/20 px-2 py-0.5 rounded-full">
                5 Stations · 4,500 IQD/hr
            </span>
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
            <?php foreach ($ps5s as $s): ?><?php renderCard($s, $activeSessions); ?><?php endforeach; ?>
        </div>
    </section>

    <!-- -- Xbox --------------------------------------------------------------- -->
    <section>
        <div class="flex items-center gap-3 mb-4 pb-2 border-b border-gray-800/70">
            <h2 class="text-sm font-bold text-red-400 uppercase tracking-widest">🟢 Xbox</h2>
            <span class="text-[11px] bg-red-500/10 text-red-400 border border-red-500/20 px-2 py-0.5 rounded-full">
                1 Station · 5,000 IQD/hr
            </span>
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
            <?php foreach ($xboxs as $s): ?><?php renderCard($s, $activeSessions); ?><?php endforeach; ?>
        </div>
    </section>

    <!-- ======================================================================= -->
    <!-- SESSION HISTORY LOG                                                    -->
    <!-- ======================================================================= -->
    <section>
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between mb-4 pb-2 border-b border-gray-800/70 gap-3">
            <div class="flex items-center gap-3">
                <h2 class="text-sm font-bold text-gray-300 uppercase tracking-widest">📍 Today's Sessions</h2>
                <span class="text-[11px] bg-gray-800 text-gray-400 border border-gray-700 px-2 py-0.5 rounded-full" id="history-count">
                    <?= count($history) ?> sessions
                </span>
            </div>
            <div class="flex items-center gap-3 w-full sm:w-auto">
                <input type="text" id="history-search" placeholder="🔍 Search station..." oninput="filterHistory()"
                       class="bg-gray-900 border border-gray-800 rounded-xl px-3 py-1.5 text-xs text-white focus:outline-none focus:border-red-500 w-full sm:w-48">
                <div class="text-xs text-gray-600 whitespace-nowrap">Auto-updates on stop</div>
            </div>
        </div>

        <?php if (empty($history)): ?>
        <div id="history-empty" class="text-center py-12 text-gray-700">
            <div class="text-3xl mb-2">📭</div>
            <div class="text-sm">No finished sessions today yet.</div>
        </div>
        <?php else: ?>
        <div id="history-empty" class="<?= !empty($history) ? 'hidden' : '' ?> text-center py-12 text-gray-700">
            <div class="text-3xl mb-2">📭</div>
            <div class="text-sm">No finished sessions today yet.</div>
        </div>
        <?php endif; ?>

        <div class="bg-cardBg border border-cardBorder rounded-2xl overflow-hidden <?= empty($history) ? 'hidden' : '' ?>" id="history-table-wrapper">
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead>
                        <tr class="border-b border-gray-800 text-gray-500 uppercase tracking-wider text-[10px]">
                            <th class="px-4 py-3 text-left font-semibold">#</th>
                            <th class="px-4 py-3 text-left font-semibold">Station</th>
                            <th class="px-4 py-3 text-left font-semibold">Start</th>
                            <th class="px-4 py-3 text-left font-semibold">End</th>
                            <th class="px-4 py-3 text-left font-semibold">Duration</th>
                            <th class="px-4 py-3 text-left font-semibold">Mode</th>
                            <th class="px-4 py-3 text-right font-semibold">Total (IQD)</th>
                            <th class="px-4 py-3 text-center font-semibold">Receipt</th>
                        </tr>
                    </thead>
                    <tbody id="history-table-body">
                        <?php foreach ($history as $i => $row):
                            $mins = floor((int)$row['active_seconds'] / 60);
                            $h    = floor($mins / 60);
                            $m    = $mins % 60;
                            $typeIcon = match($row['type']) { 'ps5'=>'🎮', 'xbox'=>'🟢', default=>'💻' };
                        ?>
                        <tr class="history-row border-b border-gray-800/50" style="animation-delay:<?= $i * 30 ?>ms">
                            <td class="px-4 py-3 text-gray-600 font-mono"><?= $row['id'] ?></td>
                            <td class="px-4 py-3">
                                <span class="mr-1"><?= $typeIcon ?></span>
                                <span class="font-semibold text-gray-200"><?= htmlspecialchars($row['station_name']) ?></span>
                            </td>
                            <td class="px-4 py-3 text-gray-400 font-mono"><?= date('H:i', strtotime($row['start_time'])) ?></td>
                            <td class="px-4 py-3 text-gray-400 font-mono"><?= date('H:i', strtotime($row['end_time'])) ?></td>
                            <td class="px-4 py-3 text-gray-300 font-mono"><?= $h ?>h <?= $m ?>m</td>
                            <td class="px-4 py-3">
                                <span class="px-1.5 py-0.5 rounded text-[10px] font-medium <?= $row['session_mode']==='prepaid' ? 'bg-yellow-500/15 text-yellow-400' : 'bg-gray-800 text-gray-400' ?>">
                                    <?= $row['session_mode'] === 'prepaid' ? '💳 Prepaid' : '⏱ Open' ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right font-bold text-green-400 font-mono">
                                <?= number_format($row['total_cost'], 0) ?>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <button onclick="printReceipt(<?= $row['id'] ?>)"
                                        class="inline-flex items-center gap-1 px-2.5 py-1 bg-gray-800 hover:bg-gray-700 border border-gray-700 hover:border-gray-500 rounded-lg transition text-gray-300 hover:text-white">
                                    🖨️ Print
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="bg-gray-900/80 border-t border-gray-800 font-semibold text-gray-300">
                        <tr>
                            <td colspan="6" class="px-4 py-3 text-right uppercase tracking-wider text-[10px] text-gray-500">Total Made Today:</td>
                            <td class="px-4 py-3 text-right font-bold text-green-400 font-mono text-sm" id="history-total-earnings">
                                <?= number_format(array_sum(array_column($history, 'total_cost')), 0) ?> IQD
                            </td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </section>

</main>

<!-- =========================================================================== -->
<!-- START SESSION MODAL                                                        -->
<!-- =========================================================================== -->
<div id="start-modal" class="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
    <div class="modal-inner bg-cardBg border border-gray-700/80 rounded-2xl p-6 w-full max-w-md shadow-2xl">

        <div class="flex items-center justify-between mb-1">
            <h2 class="text-base font-bold">🚀 Start Session</h2>
            <button onclick="closeStartModal()" class="w-8 h-8 flex items-center justify-center rounded-lg hover:bg-gray-800 text-gray-500 hover:text-white transition text-lg">&times;</button>
        </div>
        <p id="modal-station-name" class="text-sm text-red-400 font-semibold mb-5">—</p>

        <p class="text-[10px] text-gray-600 uppercase tracking-widest mb-2">Session Mode</p>
        <div class="grid grid-cols-2 gap-2.5 mb-5">
            <button id="mode-btn-open" onclick="selectMode('open')"
                    class="border-2 rounded-xl py-3 px-3 text-left transition-all duration-150">
                <div class="text-sm font-bold">⏱️ Open-Ended</div>
                <div class="text-xs text-gray-500 mt-0.5">Timer counts up — pay at checkout</div>
            </button>
            <button id="mode-btn-prepaid" onclick="selectMode('prepaid')"
                    class="border-2 rounded-xl py-3 px-3 text-left transition-all duration-150">
                <div class="text-sm font-bold">💳 Pre-Paid</div>
                <div class="text-xs text-gray-500 mt-0.5">Fixed block — countdown timer</div>
            </button>
        </div>

        <!-- Prepaid Options -->
        <div id="prepaid-options" class="hidden mb-5">
            <p class="text-[10px] text-gray-600 uppercase tracking-widest mb-2">Quick Select</p>
            <div class="grid grid-cols-5 gap-1.5 mb-3">
                <?php foreach ([[30,'30m'],[60,'1h'],[90,'1.5h'],[120,'2h'],[180,'3h']] as [$min,$lbl]): ?>
                <button onclick="setQuickDuration(<?= $min ?>, this)"
                        class="duration-btn bg-gray-800 hover:bg-red-700 border border-gray-700 text-xs py-2 rounded-lg transition font-medium">
                    <?= $lbl ?>
                </button>
                <?php endforeach; ?>
            </div>
            <p class="text-[10px] text-gray-600 uppercase tracking-widest mb-2">Custom</p>
            <div class="flex items-end gap-2">
                <div class="flex-1">
                    <label class="text-[10px] text-gray-600 block mb-1">Hours</label>
                    <input type="number" id="prepaid-hours" min="0" max="12" value="1" oninput="updateCostPreview()"
                           class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white text-center focus:outline-none focus:border-red-500 transition">
                </div>
                <span class="text-gray-600 pb-2 text-xl">:</span>
                <div class="flex-1">
                    <label class="text-[10px] text-gray-600 block mb-1">Minutes</label>
                    <input type="number" id="prepaid-minutes" min="0" max="59" value="0" oninput="updateCostPreview()"
                           class="w-full bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-white text-center focus:outline-none focus:border-red-500 transition">
                </div>
            </div>
            <p id="prepaid-cost-preview" class="text-xs text-center text-yellow-400 font-medium mt-2 min-h-[1rem]"></p>
        </div>

        <button onclick="confirmStart()"
                class="w-full bg-green-600 hover:bg-green-500 active:scale-[.98] text-white font-bold py-2.5 rounded-xl transition-all tracking-wide text-sm mt-1">
            ▶ START SESSION
        </button>
        <button onclick="closeStartModal()"
                class="w-full text-gray-600 hover:text-gray-400 text-xs py-2 mt-1 transition">
            Cancel
        </button>
    </div>
</div>

<!-- =========================================================================== -->
<!-- CAFE & ADD-ONS MODAL                                                       -->
<!-- =========================================================================== -->
<div id="addon-modal" class="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
    <div class="modal-inner bg-cardBg border border-gray-700/80 rounded-2xl p-5 w-full max-w-sm shadow-2xl">

        <div class="flex items-center justify-between mb-4">
            <div>
                <h2 class="text-base font-bold">🛍 Cafe & Snacks</h2>
                <p id="addon-modal-station" class="text-xs text-red-400 mt-0.5 font-medium">—</p>
            </div>
            <button onclick="closeAddonModal()" class="w-8 h-8 flex items-center justify-center rounded-lg hover:bg-gray-800 text-gray-500 hover:text-white transition text-lg">&times;</button>
        </div>

        <div id="menu-items-list" class="space-y-1.5 mb-3"></div>

        <!-- Custom Item Form -->
        <div class="border-t border-gray-800 pt-3 mb-3">
            <p class="text-[10px] text-gray-600 uppercase tracking-widest mb-1.5">Custom Item / Manual Entry</p>
            <div class="flex gap-2">
                <input type="text" id="custom-item-name" placeholder="Item name (e.g. Chips)"
                       class="flex-1 bg-gray-900 border border-gray-700 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-red-500">
                <input type="number" id="custom-item-price" placeholder="Price (IQD)"
                       class="w-24 bg-gray-900 border border-gray-700 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-red-500 font-mono">
                <button onclick="addCustomItem()"
                        class="bg-red-600 hover:bg-red-500 text-white px-3 py-2 rounded-xl text-xs font-bold transition">
                    + Add
                </button>
            </div>
        </div>

        <div id="addon-session-items" class="hidden border-t border-gray-800 pt-3 mb-3">
            <p class="text-[10px] text-gray-600 uppercase tracking-widest mb-1.5">Added This Session</p>
            <div id="addon-items-log" class="space-y-0.5 max-h-28 overflow-y-auto pr-1"></div>
        </div>

        <div class="border-t border-gray-800 pt-3 flex justify-between items-center">
            <span class="text-xs text-gray-500">Snacks Total</span>
            <span id="addon-running-total" class="text-green-400 font-bold font-mono">0 IQD</span>
        </div>

        <button onclick="closeAddonModal()"
                class="w-full bg-gray-800 hover:bg-gray-700 border border-gray-700 text-gray-300 text-sm font-medium py-2 rounded-xl transition mt-4">
            Done
        </button>
    </div>
</div>

<!-- =========================================================================== -->
<!-- CASH DRAWER / EXPENSES MODAL                                               -->
<!-- =========================================================================== -->
<div id="expense-modal" class="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
    <div class="modal-inner bg-cardBg border border-gray-700/80 rounded-2xl p-6 w-full max-w-lg shadow-2xl space-y-4">
        <div class="flex items-center justify-between pb-3 border-b border-gray-800">
            <div>
                <h2 class="text-base font-bold">💲 Cash Drawer & Expenses</h2>
                <p class="text-xs text-gray-500 mt-0.5">Track cash in/out and calculate drawer total</p>
            </div>
            <button onclick="closeExpenseModal()" class="w-8 h-8 flex items-center justify-center rounded-lg hover:bg-gray-800 text-gray-500 hover:text-white transition text-lg">&times;</button>
        </div>

        <!-- Drawer Summary Cards -->
        <div class="grid grid-cols-3 gap-2.5">
            <div class="bg-gray-900 border border-gray-800 rounded-xl p-3 text-center">
                <div class="text-[9px] text-gray-500 uppercase tracking-wider">Gross Income</div>
                <div id="drawer-gross" class="text-sm font-bold text-green-400 font-mono mt-1">0 IQD</div>
            </div>
            <div class="bg-gray-900 border border-gray-800 rounded-xl p-3 text-center">
                <div class="text-[9px] text-gray-500 uppercase tracking-wider">Expenses Out</div>
                <div id="drawer-expenses" class="text-sm font-bold text-red-400 font-mono mt-1">0 IQD</div>
            </div>
            <div class="bg-red-950/40 border border-red-900/50 rounded-xl p-3 text-center">
                <div class="text-[9px] text-red-400 uppercase tracking-wider font-semibold">Net Cash Drawer</div>
                <div id="drawer-net" class="text-sm font-extrabold text-red-300 font-mono mt-1">0 IQD</div>
            </div>
        </div>

        <!-- Add Expense Form -->
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-3.5 space-y-2.5">
            <p class="text-[10px] text-gray-400 uppercase tracking-wider font-semibold">+ Record Cash Out / Expense</p>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                <input type="text" id="exp-title" placeholder="Reason (e.g. Electricity, Soda Restock)"
                       class="bg-gray-800 border border-gray-700 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-red-500 sm:col-span-1">
                <input type="number" id="exp-amount" placeholder="Amount (IQD)"
                       class="bg-gray-800 border border-gray-700 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-red-500 font-mono">
                <button onclick="addExpense()"
                        class="bg-red-600 hover:bg-red-500 text-white text-xs font-bold py-2 rounded-xl transition">
                    Add Expense
                </button>
            </div>
        </div>

        <!-- Expenses List -->
        <div>
            <p class="text-[10px] text-gray-500 uppercase tracking-widest mb-2">Today's Expenses Log</p>
            <div id="expenses-list" class="space-y-1.5 max-h-40 overflow-y-auto pr-1">
                <div class="text-center py-4 text-gray-600 text-xs">No expenses recorded today.</div>
            </div>
        </div>

        <button onclick="closeExpenseModal()"
                class="w-full bg-gray-800 hover:bg-gray-700 border border-gray-700 text-gray-300 text-xs font-medium py-2 rounded-xl transition">
            Close
        </button>
    </div>
</div>

<!-- =========================================================================== -->
<!-- DAILY REPORT MODAL                                                         -->
<!-- =========================================================================== -->
<div id="report-modal" class="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
    <div class="modal-inner bg-cardBg border border-gray-700/80 rounded-2xl p-6 w-full max-w-md shadow-2xl space-y-4">
        <div class="flex items-center justify-between pb-3 border-b border-gray-800">
            <div>
                <h2 class="text-base font-bold">📊 Today's Earnings Report</h2>
                <p class="text-xs text-gray-500 mt-0.5">Comprehensive breakdown of today's revenue</p>
            </div>
            <button onclick="closeReportModal()" class="w-8 h-8 flex items-center justify-center rounded-lg hover:bg-gray-800 text-gray-500 hover:text-white transition text-lg">&times;</button>
        </div>

        <!-- Summary Cards -->
        <div class="grid grid-cols-2 gap-3">
            <div class="bg-gray-900 border border-gray-800 rounded-xl p-3">
                <div class="text-[10px] text-gray-500 uppercase tracking-wider">🎮 Gaming Revenue</div>
                <div id="rep-gaming-rev" class="text-lg font-bold text-red-400 font-mono mt-1">0 IQD</div>
            </div>
            <div class="bg-gray-900 border border-gray-800 rounded-xl p-3">
                <div class="text-[10px] text-gray-500 uppercase tracking-wider">🛍 Snacks & Drinks</div>
                <div id="rep-snacks-rev" class="text-lg font-bold text-rose-400 font-mono mt-1">0 IQD</div>
            </div>
        </div>

        <!-- Station Type Breakdown -->
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-3.5 space-y-2.5">
            <p class="text-[10px] text-gray-500 uppercase tracking-wider">Station Category Breakdown</p>
            <div class="flex justify-between items-center text-xs">
                <span class="text-gray-300">💻 PCs (10 stations)</span>
                <span id="rep-pc-rev" class="font-mono font-bold text-green-400">0 IQD</span>
            </div>
            <div class="flex justify-between items-center text-xs">
                <span class="text-gray-300">🎮 PS5s (5 stations)</span>
                <span id="rep-ps5-rev" class="font-mono font-bold text-green-400">0 IQD</span>
            </div>
            <div class="flex justify-between items-center text-xs">
                <span class="text-gray-300">🟢 Xbox (1 station)</span>
                <span id="rep-xbox-rev" class="font-mono font-bold text-green-400">0 IQD</span>
            </div>
        </div>

        <!-- Grand Total -->
        <div class="bg-red-950/40 border border-red-900/50 rounded-xl p-3 flex justify-between items-center">
            <span class="text-xs font-bold text-red-300 uppercase tracking-wider">Total Revenue Today</span>
            <span id="rep-grand-total" class="text-xl font-extrabold text-green-400 font-mono">0 IQD</span>
        </div>

        <div class="grid grid-cols-2 gap-2">
            <button onclick="window.open('print_z_report.php','_blank','width=400,height=600')"
                    class="bg-red-600 hover:bg-red-500 text-white text-xs font-bold py-2 rounded-xl transition flex items-center justify-center gap-1.5 shadow-lg shadow-red-900/30">
                🖨️ Print Z-Report
            </button>
            <button onclick="closeReportModal()"
                    class="bg-gray-800 hover:bg-gray-700 border border-gray-700 text-gray-300 text-xs font-medium py-2 rounded-xl transition">
                Close
            </button>
        </div>
    </div>
</div>

<!-- =========================================================================== -->
<!-- TRANSFER STATION MODAL                                                     -->
<!-- =========================================================================== -->
<div id="transfer-modal" class="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
    <div class="modal-inner bg-cardBg border border-gray-700/80 rounded-2xl p-5 w-full max-w-sm shadow-2xl">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h2 class="text-base font-bold">⇄ Move Station</h2>
                <p id="transfer-modal-source" class="text-xs text-red-400 mt-0.5 font-medium">—</p>
            </div>
            <button onclick="closeTransferModal()" class="w-8 h-8 flex items-center justify-center rounded-lg hover:bg-gray-800 text-gray-500 hover:text-white transition text-lg">&times;</button>
        </div>

        <p class="text-[10px] text-gray-500 uppercase tracking-widest mb-2">Select Available Target Station</p>
        <div id="available-stations-list" class="space-y-1.5 max-h-60 overflow-y-auto mb-4 pr-1"></div>

        <button onclick="closeTransferModal()"
                class="w-full bg-gray-800 hover:bg-gray-700 border border-gray-700 text-gray-300 text-xs font-medium py-2 rounded-xl transition">
            Cancel
        </button>
    </div>
</div>

<!-- =========================================================================== -->
<!-- INVENTORY / RECEIVE PRODUCTS MODAL -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div id="inventory-modal" class="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
    <div class="modal-inner bg-cardBg border border-gray-700/80 rounded-2xl p-5 w-full max-w-lg shadow-2xl max-h-[85vh] flex flex-col">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h2 class="text-base font-bold">📦 Inventory & Receive Products</h2>
                <p class="text-xs text-gray-500 mt-0.5">Manage stock levels and receive new inventory</p>
            </div>
            <div class="flex items-center gap-2">
                <button onclick="clearAllInventory()"
                        class="bg-red-600 hover:bg-red-500 text-white text-xs font-bold px-2 py-1.5 rounded-lg transition"
                        title="Delete ALL products">
                    🗑️ Clear All
                </button>
                <button onclick="closeInventoryModal()" class="w-8 h-8 flex items-center justify-center rounded-lg hover:bg-gray-800 text-gray-500 hover:text-white transition text-lg">&times;</button>
            </div>
        </div>

        <!-- Add New Product Form -->
        <div class="bg-gray-900/50 border border-gray-800 rounded-xl p-4 mb-4">
            <p class="text-[10px] text-gray-400 uppercase tracking-widest mb-2">+ Add New Product</p>
            <div class="grid grid-cols-1 sm:grid-cols-4 gap-2 mb-2">
                <input type="text" id="inv-name" placeholder="Name (e.g. Energy Drink)"
                       class="sm:col-span-2 bg-gray-800 border border-gray-700 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-purple-500">
                <input type="number" id="inv-price" placeholder="Price (IQD)" step="100" min="0"
                       class="bg-gray-800 border border-gray-700 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-purple-500 font-mono">
                <select id="inv-emoji"
                        class="bg-gray-800 border border-gray-700 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-purple-500 text-center appearance-none">
                    <option value="📦">📦 Box</option>
                    <option value="🥤">🥤 Cola</option>
                    <option value="⚡">⚡ Energy</option>
                    <option value="💧">💧 Water</option>
                    <option value="🍟">🍟 Chips</option>
                    <option value="🥪">🥪 Sandwich</option>
                    <option value="🍫">🍫 Chocolate</option>
                    <option value="🍕">🍕 Pizza</option>
                    <option value="🍔">🍔 Burger</option>
                    <option value="🌭">🌭 Hot Dog</option>
                    <option value="🍿">🍿 Popcorn</option>
                    <option value="🍩">🍩 Donut</option>
                    <option value="🍪">🍪 Cookie</option>
                    <option value="🍬">🍬 Candy</option>
                    <option value="🍭">🍭 Lollipop</option>
                    <option value="🧃">🧃 Juice Box</option>
                    <option value="🧋">🧋 Bubble Tea</option>
                    <option value="☕">☕ Coffee</option>
                    <option value="🍵">🍵 Tea</option>
                    <option value="🍺">🍺 Beer</option>
                    <option value="🍻">🍻 Drinks</option>
                    <option value="🍰">🍰 Cake</option>
                    <option value="🍦">🍦 Ice Cream</option>
                    <option value="🥤">🥤 Soft Drink</option>
                    <option value="🧊">🧊 Ice</option>
                    <option value="🎮">🎮 Gaming</option>
                    <option value="🕹️">🕹️ Arcade</option>
                    <option value="🎯">🎯 Target</option>
                    <option value="🏆">🏆 Trophy</option>
                    <option value="💰">💰 Money</option>
                    <option value="💎">💎 Gem</option>
                    <option value="🔋">🔋 Battery</option>
                    <option value="🔌">🔌 Cable</option>
                    <option value="🎧">🎧 Headset</option>
                    <option value="🖱️">🖱️ Mouse</option>
                    <option value="⌨️">⌨️ Keyboard</option>
                    <option value="🖥️">🖥️ Monitor</option>
                    <option value="🎁">🎁 Gift</option>
                    <option value="🏷️">🏷️ Tag</option>
                    <option value="💊">💊 Medicine</option>
                    <option value="🩹">🩹 Bandage</option>
                    <option value="😷">😷 Mask</option>
                    <option value="🧴">🧴 Lotion</option>
                    <option value="🧼">🧼 Soap</option>
                    <option value="🧻">🧻 Tissue</option>
                    <option value="🚬">🚬 Cigarette</option>
                    <option value="🔥">🔥 Fire</option>
                    <option value="⭐">⭐ Star</option>
                    <option value="❤️">❤️ Heart</option>
                    <option value="🌟">🌟 Sparkle</option>
                    <option value="✨">✨ Sparkles</option>
                    <option value="🎉">🎉 Party</option>
                    <option value="🎊">🎊 Confetti</option>
                    <option value="🎈">🎈 Balloon</option>
                    <option value="🎂">🎂 Birthday</option>
                </select>
            </div>
            <div class="flex gap-2">
                <input type="number" id="inv-quantity" placeholder="Initial Quantity" min="0" value="0"
                       class="w-36 bg-gray-800 border border-gray-700 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-purple-500 font-mono">
                <button onclick="addProduct()"
                        class="flex-1 bg-purple-600 hover:bg-purple-500 text-white text-xs font-bold py-2 rounded-xl transition">
                    + Add Product
                </button>
            </div>
        </div>

        <!-- Products List -->
        <div class="flex-1 overflow-y-auto">
            <p class="text-[10px] text-gray-400 uppercase tracking-widest mb-2">Current Stock</p>
            <div id="inventory-list" class="space-y-1.5">
                <div class="text-center py-6 text-gray-600 text-xs">Loading...</div>
            </div>
        </div>

        <button onclick="closeInventoryModal()"
                class="w-full bg-gray-800 hover:bg-gray-700 border border-gray-700 text-gray-300 text-xs font-medium py-2 rounded-xl transition mt-4">
            Close
        </button>
    </div>
</div>
<!-- =========================================================================== -->
<!-- ANALYTICS MODAL                                                            -->
<!-- =========================================================================== -->
<div id="analytics-modal" class="fixed inset-0 bg-black/80 backdrop-blur-sm z-50 hidden items-center justify-center p-4">
    <div class="modal-inner bg-cardBg border border-gray-700/80 rounded-2xl p-5 w-full max-w-4xl shadow-2xl max-h-[85vh] flex flex-col">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h2 class="text-base font-bold">📈 Analytics & Reports</h2>
                <p class="text-xs text-gray-500 mt-0.5">Peak hours, exports, and stock alerts</p>
            </div>
            <button onclick="closeAnalyticsModal()" class="w-8 h-8 flex items-center justify-center rounded-lg hover:bg-gray-800 text-gray-500 hover:text-white transition text-lg">&times;</button>
        </div>

        <!-- Tab Navigation -->
        <div class="flex gap-1 mb-4 border-b border-gray-800 pb-2" id="analytics-tabs">
            <button onclick="showAnalyticsTab('heatmap')" 
                    id="tab-heatmap"
                    class="analytics-tab px-4 py-2 text-xs font-bold rounded-lg transition text-white bg-indigo-600">
                🔥 Peak Hours Heatmap
            </button>
            <button onclick="showAnalyticsTab('reports')" 
                    id="tab-reports"
                    class="analytics-tab px-4 py-2 text-xs font-bold rounded-lg transition text-gray-400 hover:text-white bg-gray-800">
                📊 Reports Export
            </button>
            <button onclick="showAnalyticsTab('lowstock')" 
                    id="tab-lowstock"
                    class="analytics-tab px-4 py-2 text-xs font-bold rounded-lg transition text-gray-400 hover:text-white bg-gray-800">
                ⚠️ Low Stock Alerts
            </button>
        </div>

        <!-- Heatmap Tab -->
        <div id="analytics-heatmap" class="analytics-tab-content flex-1 overflow-y-auto">
            <div class="mb-4 flex flex-wrap gap-2 items-center">
                <label class="text-[10px] text-gray-500 uppercase tracking-widest">Period:</label>
                <select id="heatmap-days" onchange="loadHeatmap()"
                        class="bg-gray-800 border border-gray-700 rounded-lg px-3 py-1.5 text-xs text-white focus:outline-none focus:border-indigo-500">
                    <option value="7">Last 7 Days</option>
                    <option value="30" selected>Last 30 Days</option>
                    <option value="60">Last 60 Days</option>
                    <option value="90">Last 90 Days</option>
                </select>
                <button onclick="loadHeatmap()" 
                        class="ml-auto bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold px-3 py-1.5 rounded-lg transition">
                    🔄 Refresh
                </button>
            </div>
            
            <div class="bg-gray-900/50 border border-gray-800 rounded-xl p-4 mb-4">
                <p class="text-[10px] text-gray-400 uppercase tracking-widest mb-3">Weekly Heatmap (Sessions Count)</p>
                <div class="overflow-x-auto">
                    <table class="w-full text-xs" id="heatmap-table">
                        <thead>
                            <tr class="text-gray-500">
                                <th class="px-2 py-1 text-left sticky left-0 bg-gray-900">Hour</th>
                                <th class="px-2 py-1">Mon</th>
                                <th class="px-2 py-1">Tue</th>
                                <th class="px-2 py-1">Wed</th>
                                <th class="px-2 py-1">Thu</th>
                                <th class="px-2 py-1">Fri</th>
                                <th class="px-2 py-1">Sat</th>
                                <th class="px-2 py-1">Sun</th>
                                <th class="px-2 py-1">Total</th>
                            </tr>
                        </thead>
                        <tbody id="heatmap-body"></tbody>
                    </table>
                </div>
            </div>

            <div class="bg-gray-900/50 border border-gray-800 rounded-xl p-4">
                <p class="text-[10px] text-gray-400 uppercase tracking-widest mb-3">Daily Sessions & Revenue</p>
                <div class="overflow-x-auto">
                    <table class="w-full text-xs" id="daily-stats-table">
                        <thead>
                            <tr class="text-gray-500">
                                <th class="px-2 py-1 text-left">Date</th>
                                <th class="px-2 py-1">Sessions</th>
                                <th class="px-2 py-1">Total Hours</th>
                                <th class="px-2 py-1">Revenue (IQD)</th>
                            </tr>
                        </thead>
                        <tbody id="daily-stats-body"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Reports Export Tab -->
        <div id="analytics-reports" class="analytics-tab-content hidden flex-1 overflow-y-auto">
            <div class="space-y-4">
                <div class="bg-gray-900/50 border border-gray-800 rounded-xl p-4">
                    <h3 class="text-sm font-bold text-white mb-3">📅 Daily Report</h3>
                    <div class="flex flex-wrap gap-2 items-center mb-3">
                        <label class="text-[10px] text-gray-500 uppercase tracking-widest">Date:</label>
                        <input type="date" id="daily-report-date" value="<?= date('Y-m-d') ?>"
                               class="bg-gray-800 border border-gray-700 rounded-lg px-3 py-1.5 text-xs text-white focus:outline-none focus:border-indigo-500">
                        <button onclick="exportReport('daily', 'csv')" 
                                class="bg-green-600 hover:bg-green-500 text-white text-xs font-bold px-3 py-1.5 rounded-lg transition">
                            📥 Download CSV
                        </button>
                        <button onclick="exportReport('daily', 'excel')" 
                                class="bg-blue-600 hover:bg-blue-500 text-white text-xs font-bold px-3 py-1.5 rounded-lg transition">
                            📊 Download Excel
                        </button>
                    </div>
                </div>

                <div class="bg-gray-900/50 border border-gray-800 rounded-xl p-4">
                    <h3 class="text-sm font-bold text-white mb-3">📆 Monthly Report</h3>
                    <div class="flex flex-wrap gap-2 items-center mb-3">
                        <label class="text-[10px] text-gray-500 uppercase tracking-widest">Month:</label>
                        <input type="month" id="monthly-report-date" value="<?= date('Y-m') ?>"
                               class="bg-gray-800 border border-gray-700 rounded-lg px-3 py-1.5 text-xs text-white focus:outline-none focus:border-indigo-500">
                        <button onclick="exportReport('monthly', 'csv')" 
                                class="bg-green-600 hover:bg-green-500 text-white text-xs font-bold px-3 py-1.5 rounded-lg transition">
                            📥 Download CSV
                        </button>
                        <button onclick="exportReport('monthly', 'excel')" 
                                class="bg-blue-600 hover:bg-blue-500 text-white text-xs font-bold px-3 py-1.5 rounded-lg transition">
                            📊 Download Excel
                        </button>
                    </div>
                </div>

                <div class="bg-gray-900/50 border border-gray-800 rounded-xl p-4">
                    <h3 class="text-sm font-bold text-white mb-3">📋 All Sessions Export</h3>
                    <div class="flex flex-wrap gap-2 items-center">
                        <button onclick="exportReport('sessions', 'csv')" 
                                class="bg-green-600 hover:bg-green-500 text-white text-xs font-bold px-3 py-1.5 rounded-lg transition">
                            📥 Download CSV
                        </button>
                        <button onclick="exportReport('sessions', 'excel')" 
                                class="bg-blue-600 hover:bg-blue-500 text-white text-xs font-bold px-3 py-1.5 rounded-lg transition">
                            📊 Download Excel
                        </button>
                        <span class="text-[10px] text-gray-500 self-center">Exports last 1000 sessions</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Low Stock Tab -->
        <div id="analytics-lowstock" class="analytics-tab-content hidden flex-1 overflow-y-auto">
            <div class="mb-4 flex flex-wrap gap-2 items-center">
                <label class="text-[10px] text-gray-500 uppercase tracking-widest">Alert Threshold:</label>
                <input type="number" id="lowstock-threshold" value="5" min="1" max="100"
                       class="w-20 bg-gray-800 border border-gray-700 rounded-lg px-3 py-1.5 text-xs text-white focus:outline-none focus:border-red-500 font-mono text-center">
                <button onclick="loadLowStock()" 
                        class="bg-red-600 hover:bg-red-500 text-white text-xs font-bold px-3 py-1.5 rounded-lg transition">
                    🔄 Check Now
                </button>
                <span id="lowstock-count" class="text-[10px] text-gray-500 ml-auto"></span>
            </div>
            
            <div id="lowstock-list" class="space-y-2">
                <div class="text-center py-8 text-gray-600 text-xs">Click "Check Now" to scan for low stock items</div>
            </div>
        </div>

        <button onclick="closeAnalyticsModal()"
                class="w-full bg-gray-800 hover:bg-gray-700 border border-gray-700 text-gray-300 text-xs font-medium py-2 rounded-xl transition mt-4">
            Close
        </button>
    </div>
</div>
<!-- PHP: STATION CARD RENDERER                                                 -->
<!-- =========================================================================== -->
<?php
function renderCard($s, $activeSessions) {
    $id     = $s['id'];
    $name   = htmlspecialchars($s['station_name']);
    $status = $s['status'];
    $rate   = number_format($s['hourly_rate'], 0);

    // Active session info for this station (used to init timer on page load)
    $sess = null;
    foreach ($activeSessions as $as) {
        if ((int)$as['station_id'] === (int)$id) { $sess = $as; break; }
    }

    // Card glow & badge
    [$cardGlow, $badgeCls, $badgeTxt] = match($status) {
        'occupied' => ['card-occupied', 'bg-red-500/15 text-red-400 border-red-500/25',    'Occupied'],
        'paused'   => ['card-paused',   'bg-yellow-500/15 text-yellow-400 border-yellow-500/25', 'Paused'],
        default    => ['card-available','bg-green-500/15 text-green-400 border-green-500/25',  'Available'],
    };

    $timerColor  = 'text-gray-300';
    $startHidden = $status !== 'available' ? 'hidden' : '';
    $activeHidden= $status === 'available' ? 'hidden' : '';
    $pauseLbl    = $status === 'paused'    ? 'RESUME'  : 'PAUSE';
    $pauseAction = $status === 'paused'    ? 'resume'  : 'pause';
?>
<div class="bg-cardBg border border-cardBorder <?= $cardGlow ?> rounded-2xl p-4 flex flex-col gap-3 transition-all duration-300"
     id="card-<?= $id ?>"
     data-hourly-rate="<?= $s['hourly_rate'] ?>">

    <!-- -- Top Row ----------------------------------------------- -->
    <div class="flex justify-between items-start">
        <div>
            <h3 class="font-bold text-sm text-gray-100 leading-tight"><?= $name ?></h3>
            <span class="text-[10px] text-gray-600"><?= $rate ?> IQD/hr</span>
        </div>
        <span id="badge-<?= $id ?>"
              class="px-2 py-0.5 text-[10px] font-semibold rounded-full border <?= $badgeCls ?>">
            <?= $badgeTxt ?>
        </span>
    </div>

    <!-- -- Timer ------------------------------------------------- -->
    <div class="text-center py-1">
        <div class="text-[28px] font-mono font-bold tracking-widest <?= $timerColor ?> leading-none"
             id="timer-<?= $id ?>">
            00:00:00
        </div>
        <div id="timer-label-<?= $id ?>" class="text-[10px] text-gray-700 mt-1 h-3">
            <?= $status === 'available' ? '' : ($status === 'paused' ? '⏸ Paused' : '▶ Running') ?>
        </div>
    </div>

    <!-- -- Buttons ----------------------------------------------- -->
    <div class="space-y-1.5">
        <!-- START -->
        <button onclick="openStartModal(<?= $id ?>)"
                id="btn-start-<?= $id ?>"
                class="w-full bg-red-600 hover:bg-red-500 shadow-lg shadow-red-900/30 active:scale-[.98] text-white text-[11px] font-bold py-2 rounded-xl transition-all tracking-widest <?= $startHidden ?>">
            ▶ START
        </button>

        <!-- PAUSE/RESUME + STOP -->
        <div id="active-btns-<?= $id ?>" class="space-y-1.5 <?= $activeHidden ?>">
            <div class="grid grid-cols-2 gap-1.5">
                <button onclick="controlSession(<?= $id ?>, '<?= $pauseAction ?>')"
                        id="btn-pause-<?= $id ?>"
                        class="bg-yellow-600/90 hover:bg-yellow-500 active:scale-[.98] text-white text-[11px] font-bold py-2 rounded-xl transition-all tracking-widest">
                    <?= $pauseLbl ?>
                </button>
                <button onclick="controlSession(<?= $id ?>, 'stop')"
                        class="bg-red-600/90 hover:bg-red-500 active:scale-[.98] text-white text-[11px] font-bold py-2 rounded-xl transition-all tracking-widest">
                    ■  STOP
                </button>
            </div>

            <!-- Add Snack -->
            <button onclick="openAddonModal(<?= $id ?>)"
                    class="w-full bg-red-950/60 hover:bg-red-900/80 border border-red-800/40 text-red-300 text-[11px] font-semibold py-1.5 rounded-xl transition-all tracking-wide flex items-center justify-center gap-1.5">
                🛍 Add Snack / Drink
            </button>

            <!-- Move / Transfer Station -->
            <button onclick="openTransferModal(<?= $id ?>)"
                    class="w-full bg-zinc-900/80 hover:bg-zinc-800 border border-zinc-700 text-zinc-300 text-[11px] font-semibold py-1.5 rounded-xl transition-all tracking-wide flex items-center justify-center gap-1.5">
                ⇄ Move Station
            </button>

            <!-- Addon tally pill -->
            <div id="addons-tally-<?= $id ?>" class="hidden text-center">
                <span class="inline-flex items-center gap-1 text-[10px] text-red-400 bg-red-500/10 border border-red-500/20 px-2 py-0.5 rounded-full">
                    🛍 <span id="addons-total-<?= $id ?>">0</span> IQD
                </span>
            </div>
        </div>
    </div>

    <!-- -- Receipt ----------------------------------------------- -->
    <div id="receipt-<?= $id ?>" class="hidden bg-gray-950/60 border border-gray-800 rounded-xl p-3 text-xs space-y-1.5">
        <div class="text-gray-400 font-semibold pb-1.5 border-b border-gray-800 text-[11px] flex justify-between items-center">
            <span>📍 Session Receipt</span>
            <button onclick="printReceiptFromCard(<?= $id ?>)"
                    id="print-btn-<?= $id ?>"
                    class="text-[10px] text-gray-600 hover:text-gray-300 transition flex items-center gap-1">
                🖨️ Print
            </button>
        </div>
        <div class="flex justify-between text-gray-400">
            <span>🎮 Gaming (<span id="res-time-<?= $id ?>">—</span>)</span>
            <span id="res-gaming-cost-<?= $id ?>" class="text-gray-200 font-mono">—</span>
        </div>
        <div id="res-addons-section-<?= $id ?>" class="hidden space-y-1">
            <div id="res-addons-list-<?= $id ?>"></div>
            <div class="flex justify-between text-gray-400">
                <span>🛍 Snacks</span>
                <span id="res-addons-total-<?= $id ?>" class="text-red-400 font-mono">—</span>
            </div>
        </div>
        <div class="flex justify-between font-bold text-green-400 border-t border-gray-800 pt-1.5">
            <span>TOTAL</span>
            <span id="res-grand-total-<?= $id ?>" class="font-mono">—</span>
        </div>
    </div>

</div>
<?php } ?>

<!-- =========================================================================== -->
<!-- JAVASCRIPT ENGINE                                                          -->
<!-- =========================================================================== -->
<script>
// --- State -------------------------------------------------------------------
const TOTAL_STATIONS = 16;
const INITIAL_ACTIVE_SESSIONS = <?= $sessionDataJson ?>;
const MENU_ITEMS = [
    { emoji:'⚡', name:'Energy Drink', price:null },
    { emoji:'🥤', name:'Cola',         price:null },
    { emoji:'💦', name:'Water',        price:null },
    { emoji:'', name:'Chips',        price:null },
    { emoji:'🥪', name:'Sandwich',     price:null },
    { emoji:'', name:'Chocolate',    price:null },
];

// Header numeric state (updated optimistically)
let hActive  = parseInt(document.getElementById('live-active')?.innerText)  || 0;
let hRevenue = parseFloat(document.getElementById('live-revenue')?.innerText.replace(/[^0-9]/g,'')) || 0;

// --- Header Stats -------------------------------------------------------------
function updateHeaderStats(activeDelta = 0, revenueAdd = 0) {
    hActive  = Math.max(0, hActive + activeDelta);
    hRevenue += revenueAdd;
    const util = Math.round((hActive / TOTAL_STATIONS) * 10) / 10;
    document.getElementById('live-active').innerText  = hActive;
    document.getElementById('live-util').innerText    = util + '%';
    document.getElementById('live-revenue').innerText = Math.round(hRevenue).toLocaleString();

    // Update Daily Goal Progress (120,000 IQD target)
    const goal = 120000;
    const pct  = Math.min(100, Math.round((hRevenue / goal) * 100));
    document.getElementById('goal-pct').innerText = pct + '%';
    document.getElementById('goal-bar').style.width = pct + '%';
}

// --- Toast -------------------------------------------------------------------
function showToast(msg, type = 'success') {
    const palette = {
        success:'bg-green-800 border-green-700',
        error:  'bg-red-800 border-red-700',
        info:   'bg-red-900 border-red-700',
        warning:'bg-yellow-700 border-yellow-600',
    };
    let container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        container.className = 'fixed bottom-5 right-5 z-[100] space-y-2 pointer-events-none';
        document.body.appendChild(container);
    }
    const t = document.createElement('div');
    t.className = `toast-enter pointer-events-auto ${palette[type]} border text-white text-xs px-4 py-2.5 rounded-xl shadow-2xl`;
    t.innerText = msg;
    container.appendChild(t);
    setTimeout(() => { t.style.transition='opacity .3s,transform .3s'; t.style.opacity='0'; t.style.transform='translateX(16px)'; setTimeout(()=>t.remove(),300); }, 2800);
}

// --- Timer -------------------------------------------------------------------
function formatTime(sec) {
    const h = Math.floor(sec/3600), m = Math.floor((sec%3600)/60), s = sec%60;
    return `${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
}

function updateTimerDisplay(id) {
    const t       = activeTimers[id];
    const timerEl = document.getElementById(`timer-${id}`);
    if (!t || !timerEl) return;

    if (t.prepaid !== null) {
        const remaining = Math.max(0, t.prepaid - t.seconds);
        timerEl.innerText = formatTime(remaining);
        timerEl.classList.toggle('timer-warning', remaining <= 300 && remaining > 0);
        timerEl.classList.toggle('timer-expired',  remaining === 0);
    } else {
        timerEl.innerText = formatTime(t.seconds);
        timerEl.classList.remove('timer-warning','timer-expired');
    }
}

function startTimerClock(id) {
    const t      = activeTimers[id];
    const cardEl = document.getElementById(`card-${id}`);
    t.interval   = setInterval(() => {
        t.seconds++;
        updateTimerDisplay(id);

        // Prepaid expiry
        if (t.prepaid !== null && t.seconds >= t.prepaid) {
            clearInterval(t.interval); t.interval = null;
            cardEl.classList.add('card-expired');
            const name = document.querySelector(`#card-${id} h3`).innerText;
            showToast(`⏰ TIME EXPIRED — ${name}`, 'warning');
            setTimeout(() => alert(`⏰ PREPAID TIME EXPIRED!\n\n${name}'s session has ended.\nPlease process the checkout.`), 80);
        }
    }, 1000);
}

function pauseTimerClock(id) {
    if (activeTimers[id]?.interval) { clearInterval(activeTimers[id].interval); activeTimers[id].interval = null; }
}

function destroyTimer(id) {
    if (activeTimers[id]) { clearInterval(activeTimers[id].interval); delete activeTimers[id]; }
    const el = document.getElementById(`timer-${id}`);
    if (el) { el.innerText = '00:00:00'; el.className = el.className.replace('timer-warning','').replace('timer-expired',''); }
    document.getElementById(`card-${id}`)?.classList.remove('card-expired');
}

// --- START MODAL -------------------------------------------------------------
function closeStartModal() { hideModal('start-modal'); }

function selectMode(mode) {
    selectedMode = mode;
    const B  = 'border-2 rounded-xl py-3 px-3 text-left transition-all duration-150 w-full';
    const ON = `${B} border-red-500 bg-red-500/10 text-white`;
    const OFF= `${B} border-gray-700 bg-gray-800/40 text-gray-400`;
    document.getElementById('mode-btn-open').className    = mode==='open'    ? ON : OFF;
    document.getElementById('mode-btn-prepaid').className = mode==='prepaid' ? ON : OFF;
    document.getElementById('prepaid-options').classList.toggle('hidden', mode !== 'prepaid');
    if (mode === 'prepaid') updateCostPreview();
}

function setQuickDuration(mins, btn) {
    document.getElementById('prepaid-hours').value   = Math.floor(mins/60);
    document.getElementById('prepaid-minutes').value = mins%60;
    document.querySelectorAll('.duration-btn').forEach(b => b.classList.replace('bg-red-700','bg-gray-800') || b.classList.replace('border-red-500','border-gray-700'));
    btn.classList.replace('bg-gray-800','bg-red-700');
    btn.classList.replace('border-gray-700','border-red-500');
    updateCostPreview();
}

function updateCostPreview() {
    if (!selectedStationId) return;
    const h = parseInt(document.getElementById('prepaid-hours').value)||0;
    const m = parseInt(document.getElementById('prepaid-minutes').value)||0;
    const totalH = h + m/60;
    const rate   = parseFloat(document.getElementById(`card-${selectedStationId}`).dataset.hourlyRate)||0;
    const el     = document.getElementById('prepaid-cost-preview');
    el.innerText  = (rate && totalH > 0) ? `Estimated cost: ${(Math.round(totalH*rate/100)*100).toLocaleString()} IQD` : '';
}

function confirmStart() {
    const opts = { session_mode: selectedMode };
    if (selectedMode === 'prepaid') {
        const secs = (parseInt(document.getElementById('prepaid-hours').value)||0)*3600
                   + (parseInt(document.getElementById('prepaid-minutes').value)||0)*60;
        if (secs <= 0) { showToast('Enter a valid duration.','error'); return; }
        opts.prepaid_seconds = secs;
    }
    closeStartModal();
    controlSession(selectedStationId, 'start', opts);
}

// --- ADDON MODAL -------------------------------------------------------------
function openAddonModal(id) {
    addonStationId = id;
    document.getElementById('addon-modal-station').innerText = document.querySelector(`#card-${id} h3`).innerText;
    document.getElementById('menu-items-list').innerHTML = MENU_ITEMS.map(item => {
        const hasPrice = item.price !== null;
        const priceTxt = hasPrice ? `+${item.price.toLocaleString()} IQD` : 'Manual Price ✍️';
        const onclickAction = hasPrice ? `addItem(${id},'${item.name}',${item.price})` : `promptAddItem(${id},'${item.name}','${item.emoji}')`;
        return `
        <button onclick="${onclickAction}"
                class="w-full flex justify-between items-center bg-gray-800/80 hover:bg-gray-700
                       border border-gray-700/60 hover:border-red-500/50 rounded-xl px-3 py-2.5
                       transition-all group text-left">
            <span class="text-sm">${item.emoji} ${item.name}</span>
            <span class="text-[11px] text-red-400 font-bold font-mono group-hover:text-red-300">${priceTxt}</span>
        </button>`;
    }).join('');
    refreshAddonModal(id);
    showModal('addon-modal');
}
function closeAddonModal() { hideModal('addon-modal'); }

// ─── INVENTORY MODAL ──────────────────────────────────────────────────────────
function openInventoryModal() {
    showModal('inventory-modal');
    loadInventory();
}

function closeInventoryModal() { hideModal('inventory-modal'); }

// ─── ANALYTICS MODAL ─────────────────────────────────────────────────────────────
function openAnalyticsModal() {
    showModal('analytics-modal');
    showAnalyticsTab('heatmap');
    loadHeatmap();
}

function closeAnalyticsModal() { hideModal('analytics-modal'); }

function showAnalyticsTab(tab) {
    // Hide all tab contents
    document.querySelectorAll('.analytics-tab-content').forEach(el => el.classList.add('hidden'));
    // Remove active state from all tabs
    document.querySelectorAll('.analytics-tab').forEach(el => {
        el.classList.remove('bg-indigo-600', 'text-white');
        el.classList.add('bg-gray-800', 'text-gray-400');
    });
    // Show selected tab content
    document.getElementById('analytics-' + tab).classList.remove('hidden');
    // Activate selected tab
    const activeTab = document.getElementById('tab-' + tab);
    activeTab.classList.remove('bg-gray-800', 'text-gray-400');
    activeTab.classList.add('bg-indigo-600', 'text-white');
    
    // Load data for the tab
    if (tab === 'heatmap') loadHeatmap();
    else if (tab === 'lowstock') loadLowStock();
}

function loadHeatmap() {
    const days = document.getElementById('heatmap-days').value;
    const bodyEl = document.getElementById('heatmap-body');
    const dailyBodyEl = document.getElementById('daily-stats-body');
    
    bodyEl.innerHTML = '<tr><td colspan="9" class="text-center py-6 text-gray-600 text-xs">Loading...</td></tr>';
    dailyBodyEl.innerHTML = '<tr><td colspan="4" class="text-center py-6 text-gray-600 text-xs">Loading...</td></tr>';
    
    fetch('backend.php?action=peak_hours_heatmap&days=' + days)
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            
            renderHeatmap(data.heatmap);
            renderDailyStats(data.daily);
        })
        .catch(() => {
            bodyEl.innerHTML = '<tr><td colspan="9" class="text-center py-6 text-red-400 text-xs">Failed to load</td></tr>';
        });
}

function renderHeatmap(heatmapData) {
    const bodyEl = document.getElementById('heatmap-body');
    const days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    
    // Build matrix: hour (0-23) x day (1-7)
    const matrix = {};
    heatmapData.forEach(row => {
        const h = parseInt(row.hour);
        const d = parseInt(row.day_of_week); // 1=Sun, 2=Mon, ..., 7=Sat
        const dayIndex = d === 1 ? 6 : d - 2; // Convert to 0=Mon, 6=Sun
        if (!matrix[h]) matrix[h] = {};
        matrix[h][dayIndex] = parseInt(row.session_count);
    });
    
    // Find max for color scaling
    let maxCount = 0;
    heatmapData.forEach(row => {
        maxCount = Math.max(maxCount, parseInt(row.session_count));
    });
    
    let html = '';
    for (let h = 0; h < 24; h++) {
        const hourLabel = h.toString().padStart(2, '0') + ':00';
        let rowHtml = '<tr><td class="px-2 py-1 font-mono text-gray-400 sticky left-0 bg-gray-900">' + hourLabel + '</td>';
        let rowTotal = 0;
        
        for (let d = 0; d < 7; d++) {
            const count = matrix[h]?.[d] || 0;
            rowTotal += count;
            const intensity = maxCount > 0 ? count / maxCount : 0;
            const bgColor = intensity > 0 
                ? 'background: linear-gradient(135deg, rgba(99, 102, 241, ' + (0.2 + intensity * 0.6) + '), rgba(139, 92, 246, ' + (0.1 + intensity * 0.4) + '))'
                : 'background: transparent';
            rowHtml += '<td class="px-2 py-1 text-center font-bold ' + (count > 0 ? 'text-indigo-300' : 'text-gray-600') + '" style="' + bgColor + ';">' + (count > 0 ? count : '—') + '</td>';
        }
        rowHtml += '<td class="px-2 py-1 font-bold text-indigo-400 text-right pr-2">' + (rowTotal > 0 ? rowTotal : '—') + '</td></tr>';
        html += rowHtml;
    }
    
    bodyEl.innerHTML = html;
}

function renderDailyStats(dailyData) {
    const bodyEl = document.getElementById('daily-stats-body');
    
    if (!dailyData || dailyData.length === 0) {
        bodyEl.innerHTML = '<tr><td colspan="4" class="text-center py-6 text-gray-600 text-xs">No data for this period</td></tr>';
        return;
    }
    
    let html = '';
    dailyData.forEach(row => {
        const date = new Date(row.date);
        const dateStr = date.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
        const hours = Math.floor(parseInt(row.total_seconds || 0) / 3600);
        const mins = Math.floor((parseInt(row.total_seconds || 0) % 3600) / 60);
        const timeStr = hours + 'h ' + mins + 'm';
        const revenue = parseFloat(row.revenue || 0).toLocaleString();
        
        html += '<tr class="border-t border-gray-800">' +
            '<td class="px-2 py-1 font-mono text-gray-300">' + dateStr + '</td>' +
            '<td class="px-2 py-1 text-center text-white">' + row.sessions + '</td>' +
            '<td class="px-2 py-1 text-center text-gray-400 font-mono">' + timeStr + '</td>' +
            '<td class="px-2 py-1 text-right text-green-400 font-mono">' + revenue + '</td>' +
        '</tr>';
    });
    
    bodyEl.innerHTML = html;
}

function exportReport(type, format) {
    let url = 'backend.php?action=export_report&type=' + type + '&format=' + format;
    
    if (type === 'daily') {
        const date = document.getElementById('daily-report-date').value;
        url += '&date=' + encodeURIComponent(date);
    } else if (type === 'monthly') {
        const date = document.getElementById('monthly-report-date').value;
        url += '&date=' + encodeURIComponent(date);
    }
    
    // Use a temporary link to trigger download
    const a = document.createElement('a');
    a.href = url;
    a.download = '';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    
    showToast('Export started: ' + type.toUpperCase() + ' (' + format.toUpperCase() + ')', 'success');
}

function loadLowStock() {
    const threshold = document.getElementById('lowstock-threshold').value;
    const listEl = document.getElementById('lowstock-list');
    const countEl = document.getElementById('lowstock-count');
    
    listEl.innerHTML = '<div class="text-center py-6 text-gray-600 text-xs">Checking stock...</div>';
    
    fetch('backend.php?action=low_stock_alert&threshold=' + threshold)
        .then(r => r.json())
        .then(data => {
            if (!data.success) return;
            
            countEl.innerText = data.count + ' item(s) low';
            
            if (data.count === 0) {
                listEl.innerHTML = '<div class="text-center py-8 text-green-400 text-xs">✅ All products above threshold (' + threshold + ')</div>';
                return;
            }
            
            listEl.innerHTML = data.products.map(p => `
                <div class="bg-red-900/30 border border-red-800/50 rounded-xl p-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                    <div class="flex items-center gap-3">
                        <span class="text-xl">${p.emoji || '📦'}</span>
                        <div>
                            <p class="font-semibold text-red-300">${p.name}</p>
                            <p class="text-[10px] text-gray-500">${Number(p.price).toLocaleString()} IQD each</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="bg-red-800 border border-red-700 rounded-lg px-3 py-1.5 text-sm font-mono text-red-300">
                            Only ${p.quantity} left!
                        </span>
                        <input type="number" id="restock-qty-${p.id}" placeholder="Qty" min="1" value="${threshold * 2}"
                               class="w-20 bg-gray-800 border border-gray-700 rounded-lg px-2 py-1.5 text-xs text-white focus:outline-none focus:border-red-500 font-mono text-center">
                        <button onclick="receiveProduct(${p.id}, '${p.name.replace(/'/g, "\\'")}')"
                                class="bg-red-600 hover:bg-red-500 text-white text-xs font-bold px-3 py-1.5 rounded-lg transition">
                            + Restock
                        </button>
                    </div>
                </div>
            `).join('');
        })
        .catch(() => {
            listEl.innerHTML = '<div class="text-center py-6 text-red-400 text-xs">Failed to load</div>';
        });
}


function loadInventory() {
    const listEl = document.getElementById('inventory-list');
    listEl.innerHTML = '<div class="text-center py-6 text-gray-600 text-xs">Loading...</div>';
    
    fetch('backend.php?action=get_products')
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                renderInventory(data.products);
            } else {
                listEl.innerHTML = '<div class="text-center py-6 text-red-400 text-xs">Failed to load inventory</div>';
            }
        })
        .catch(() => {
            listEl.innerHTML = '<div class="text-center py-6 text-red-400 text-xs">Network error</div>';
        });
}

function renderInventory(products) {
    const listEl = document.getElementById('inventory-list');
    if (!products || products.length === 0) {
        listEl.innerHTML = '<div class="text-center py-6 text-gray-600 text-xs">No products yet. Add one above!</div>';
        return;
    }
    
    listEl.innerHTML = products.map(p => `
        <div class="bg-gray-900/50 border border-gray-800 rounded-xl p-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <div class="flex items-center gap-3 flex-1 min-w-0">
                <span class="text-xl">${p.emoji || '📦'}</span>
                <div class="min-w-0">
                    <p class="font-semibold text-white truncate">${p.name}</p>
                    <p class="text-[10px] text-gray-500">${Number(p.price).toLocaleString()} IQD each</p>
                </div>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <span class="bg-gray-800 border border-gray-700 rounded-lg px-3 py-1.5 text-sm font-mono text-green-400">
                    Stock: ${p.quantity}
                </span>
                <input type="number" id="receive-qty-${p.id}" placeholder="Qty" min="1" value="1"
                       class="w-20 bg-gray-800 border border-gray-700 rounded-lg px-2 py-1.5 text-xs text-white focus:outline-none focus:border-purple-500 font-mono text-center">
                <button onclick="receiveProduct(${p.id}, '${p.name.replace(/'/g, "\\'")}')"
                        class="bg-purple-600 hover:bg-purple-500 text-white text-xs font-bold px-3 py-1.5 rounded-lg transition">
                    + Receive
                </button>
            </div>
        </div>
    `).join('');
}

function addProduct() {
    const name = document.getElementById('inv-name').value.trim();
    const price = parseFloat(document.getElementById('inv-price').value) || 0;
    const emoji = document.getElementById('inv-emoji').value.trim() || '📦';
    const quantity = parseInt(document.getElementById('inv-quantity').value) || 0;
    
    if (!name) { showToast('Product name is required', 'error'); return; }
    if (price < 0) { showToast('Invalid price', 'error'); return; }
    
    const fd = new FormData();
    fd.append('action', 'receive_product');
    fd.append('item_name', name);
    fd.append('item_price', price);
    fd.append('quantity', quantity);
    fd.append('emoji', emoji);
    
    fetch('backend.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');
                document.getElementById('inv-name').value = '';
                document.getElementById('inv-price').value = '';
                document.getElementById('inv-emoji').value = '';
                document.getElementById('inv-quantity').value = '0';
                loadInventory();
            } else {
                showToast('Error: ' + data.message, 'error');
            }
        })
        .catch(() => showToast('Network error', 'error'));
}

function receiveProduct(productId, productName) {
    const qtyInput = document.getElementById('receive-qty-' + productId);
    const quantity = parseInt(qtyInput?.value) || 1;
    
    if (quantity < 1) { showToast('Enter valid quantity', 'error'); return; }
    
    const fd = new FormData();
    fd.append('action', 'receive_product');
    fd.append('item_name', productName);
    fd.append('quantity', quantity);
    fd.append('item_price', 0);
    fd.append('emoji', '');
    
    fetch('backend.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');
                loadInventory();
            } else {
                showToast('Error: ' + data.message, 'error');
            }
        })
        .catch(() => showToast('Network error', 'error'));
}


function clearAllInventory() {
    if (!confirm('Are you sure you want to delete ALL products from inventory? This cannot be undone.')) return;
    
    const fd = new FormData();
    fd.append('action', 'clear_inventory');
    
    fetch('backend.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast('All inventory cleared', 'success');
                loadInventory();
            } else {
                showToast('Error: ' + data.message, 'error');
            }
        })
        .catch(() => showToast('Network error', 'error'));
}


function promptAddItem(id, name, emoji) {
    const input = prompt(`Enter price for ${name} (IQD):`, '1000');
    if (input === null) return; // cancelled
    const price = parseFloat(input);
    if (isNaN(price) || price <= 0) {
        showToast('Invalid price entered.', 'error');
        return;
    }
    const fd = new FormData();
    fd.append('action','add_addon'); fd.append('station_id',id);
    fd.append('item_name',name);     fd.append('item_price',price);
    fetch('backend.php',{method:'POST',body:fd}).then(r=>r.json()).then(data=>{
        if (data.success) {
            if (!stationAddons[id]) stationAddons[id]={items:[],total:0};
            stationAddons[id].items.push({name,price,emoji:emoji||''});
            stationAddons[id].total += price;
            refreshAddonModal(id);
            updateCardAddonTally(id);
            showToast(`${emoji} ${name} added (+${price.toLocaleString()} IQD)`,'info');
        } else showToast('Error: '+data.message,'error');
    }).catch(()=>showToast('Network error.','error'));
}

function refreshAddonModal(id) {
    const data  = stationAddons[id];
    const total = data?.total || 0;
    document.getElementById('addon-running-total').innerText = total.toLocaleString() + ' IQD';
    const logEl = document.getElementById('addon-items-log');
    const secEl = document.getElementById('addon-session-items');
    if (data?.items?.length > 0) {
        logEl.innerHTML = data.items.map(i =>
            `<div class="flex justify-between text-gray-500 text-[11px] py-0.5">
                <span>${i.emoji} ${i.name}</span>
                <span class="font-mono">${i.price.toLocaleString()} IQD</span>
             </div>`).join('');
        secEl.classList.remove('hidden');
    } else {
        secEl.classList.add('hidden');
    }
}

function addItem(id, name, price) {
    const fd = new FormData();
    fd.append('action','add_addon'); fd.append('station_id',id);
    fd.append('item_name',name);     fd.append('item_price',price);
    fetch('backend.php',{method:'POST',body:fd}).then(r=>r.json()).then(data=>{
        if (data.success) {
            if (!stationAddons[id]) stationAddons[id]={items:[],total:0};
            const item = MENU_ITEMS.find(m=>m.name===name);
            stationAddons[id].items.push({name,price,emoji:item?.emoji||'•'});
            stationAddons[id].total += price;
            refreshAddonModal(id);
            updateCardAddonTally(id);
            showToast(`${item?.emoji||''} ${name} added (+${price.toLocaleString()} IQD)`,'info');
        } else showToast('Error: '+data.message,'error');
    }).catch(()=>showToast('Network error.','error'));
}

// --- TRANSFER STATION --------------------------------------------------------

function openTransferModal(sourceId) {
    console.log("Opening transfer modal for source station ID:", sourceId);
    transferSourceId = sourceId;
    const sourceCard = document.getElementById(`card-${sourceId}`);
    const sourceName = sourceCard ? sourceCard.querySelector('h3').innerText : `Station #${sourceId}`;
    const sourceEl = document.getElementById('transfer-modal-source');
    if (sourceEl) sourceEl.innerText = `Moving from: ${sourceName}`;

    const listEl = document.getElementById('available-stations-list');
    const cards = document.querySelectorAll('[id^="card-"]');
    let availableHtml = '';

    cards.forEach(card => {
        const id = card.id.replace('card-', '');
        if (id == sourceId) return;
        const startBtn = document.getElementById(`btn-start-${id}`);
        const isAvailable = startBtn && !startBtn.classList.contains('hidden');

        if (isAvailable) {
            const name = card.querySelector('h3').innerText;
            availableHtml += `
                <button onclick="console.log('Transfer button clicked: source=${sourceId}, target=${id}'); transferStation(${sourceId}, ${id});"
                        class="w-full flex justify-between items-center bg-gray-800/80 hover:bg-gray-700
                               border border-gray-700/60 hover:border-red-500/50 rounded-xl px-3 py-2.5
                               transition-all group text-left">
                    <span class="text-sm font-semibold text-gray-200">📂 ${name}</span>
                    <span class="text-[11px] text-red-400 font-mono">Select →</span>
                </button>`;
        }
    });

    if (listEl) {
        listEl.innerHTML = availableHtml || '<div class="text-center py-6 text-gray-500 text-xs">No available stations to move to.</div>';
    }
    showModal('transfer-modal');
}

function closeTransferModal() { hideModal('transfer-modal'); }

function transferStation(sourceId, targetId) {
    console.log("Transferring from station", sourceId, "to station", targetId);
    const fd = new FormData();
    fd.append('action', 'transfer');
    fd.append('station_id', sourceId);
    fd.append('new_station_id', targetId);

    fetch('backend.php', { method: 'POST', body: fd }).then(r => r.json()).then(data => {
        console.log("Transfer response:", data);
        if (data.success) {
            showToast('Session transferred successfully!', 'success');
            closeTransferModal();
            location.reload();
        } else {
            showToast('Error: ' + data.message, 'error');
        }
    }).catch(err => {
        console.error("Transfer network error:", err);
        showToast('Network error during transfer.', 'error');
    });
}

// --- REPORT MODAL ------------------------------------------------------------
function openReportModal() {
    fetch('api_report.php')
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.getElementById('rep-gaming-rev').innerText = Math.round(data.gaming_revenue).toLocaleString() + ' IQD';
                document.getElementById('rep-snacks-rev').innerText = Math.round(data.snacks_revenue).toLocaleString() + ' IQD';
                document.getElementById('rep-pc-rev').innerText    = Math.round(data.type_breakdown.pc.revenue).toLocaleString() + ' IQD';
                document.getElementById('rep-ps5-rev').innerText   = Math.round(data.type_breakdown.ps5.revenue).toLocaleString() + ' IQD';
                document.getElementById('rep-xbox-rev').innerText  = Math.round(data.type_breakdown.xbox.revenue).toLocaleString() + ' IQD';
                document.getElementById('rep-grand-total').innerText = Math.round(data.grand_total).toLocaleString() + ' IQD';
            } else {
                showToast('Failed to load report data', 'error');
            }
        })
        .catch(() => showToast('Network error loading report', 'error'));
    showModal('report-modal');
}

function closeReportModal() {
    hideModal('report-modal');
}

// --- CASH DRAWER & EXPENSES MODAL --------------------------------------------
function openExpenseModal() {
    loadExpensesData();
    showModal('expense-modal');
}

function closeExpenseModal() { hideModal('expense-modal'); }

function loadExpensesData() {
    fetch('api_expenses.php').then(r => r.json()).then(data => {
        if (data.success) {
            document.getElementById('drawer-gross').innerText    = Math.round(data.gross_revenue).toLocaleString() + ' IQD';
            document.getElementById('drawer-expenses').innerText = Math.round(data.total_expenses).toLocaleString() + ' IQD';
            document.getElementById('drawer-net').innerText      = Math.round(data.net_cash).toLocaleString() + ' IQD';

            const listEl = document.getElementById('expenses-list');
            if (data.expenses.length > 0) {
                listEl.innerHTML = data.expenses.map(e => `
                    <div class="flex justify-between items-center bg-gray-900 border border-gray-800/80 rounded-xl px-3 py-2 text-xs">
                        <div>
                            <span class="font-semibold text-gray-200">${e.title}</span>
                            <span class="text-[10px] text-gray-500 block">${e.created_at}</span>
                        </div>
                        <span class="font-mono font-bold text-red-400">-${parseFloat(e.amount).toLocaleString()} IQD</span>
                    </div>`).join('');
            } else {
                listEl.innerHTML = '<div class="text-center py-4 text-gray-600 text-xs">No expenses recorded today.</div>';
            }
        }
    }).catch(() => showToast('Failed to load expenses', 'error'));
}

function addExpense() {
    const titleIn  = document.getElementById('exp-title');
    const amountIn = document.getElementById('exp-amount');
    const title  = titleIn.value.trim();
    const amount = parseFloat(amountIn.value);

    if (!title) { showToast('Enter expense title.', 'error'); return; }
    if (!amount || amount <= 0) { showToast('Enter valid amount.', 'error'); return; }

    const fd = new FormData();
    fd.append('action', 'add');
    fd.append('title', title);
    fd.append('amount', amount);

    fetch('api_expenses.php', { method: 'POST', body: fd }).then(r => r.json()).then(data => {
        if (data.success) {
            titleIn.value = '';
            amountIn.value = '';
            showToast('Expense recorded successfully!', 'success');
            loadExpensesData();
        } else {
            showToast('Error: ' + data.message, 'error');
        }
    }).catch(() => showToast('Network error recording expense.', 'error'));
}

function updateCardAddonTally(id) {
    const total = stationAddons[id]?.total||0;
    const tel   = document.getElementById(`addons-tally-${id}`);
    const iel   = document.getElementById(`addons-total-${id}`);
    if (!tel||!iel) return;
    iel.innerText = total.toLocaleString();
    tel.classList.toggle('hidden', total===0);
}

// --- SESSION CONTROL ---------------------------------------------------------
function controlSession(id, action, opts={}) {
    const fd = new FormData();
    fd.append('action',action); fd.append('station_id',id);
    if (opts.session_mode)    fd.append('session_mode',    opts.session_mode);
    if (opts.prepaid_seconds) fd.append('prepaid_seconds', opts.prepaid_seconds);
    fetch('backend.php',{method:'POST',body:fd}).then(r=>r.json()).then(data=>{
        if (data.success) updateCardUI(id,action,data,opts);
        else showToast('Error: '+data.message,'error');
    }).catch(err=>{console.error(err); updateCardUI(id,action,{},opts);});
}

// --- CARD UI UPDATER ---------------------------------------------------------
function updateCardUI(id, action, data={}, opts={}) {
    const badge       = document.getElementById(`badge-${id}`);
    const startBtn    = document.getElementById(`btn-start-${id}`);
    const activeBtns  = document.getElementById(`active-btns-${id}`);
    const pauseBtn    = document.getElementById(`btn-pause-${id}`);
    const receipt     = document.getElementById(`receipt-${id}`);
    const timerLabel  = document.getElementById(`timer-label-${id}`);
    const card        = document.getElementById(`card-${id}`);

    const B_OCC  = 'px-2 py-0.5 text-[10px] font-semibold rounded-full border bg-red-500/15 text-red-400 border-red-500/25';
    const B_PAU  = 'px-2 py-0.5 text-[10px] font-semibold rounded-full border bg-yellow-500/15 text-yellow-400 border-yellow-500/25';
    const B_AVA  = 'px-2 py-0.5 text-[10px] font-semibold rounded-full border bg-green-500/15 text-green-400 border-green-500/25';

    function setCardGlow(cls) {
        card.classList.remove('card-occupied','card-paused','card-available');
        card.classList.add(cls);
    }

    if (action==='start'||action==='resume') {
        badge.className=B_OCC; badge.innerText='Occupied';
        startBtn.classList.add('hidden');
        activeBtns.classList.remove('hidden');
        receipt.classList.add('hidden');
        pauseBtn.innerText='PAUSE';
        pauseBtn.setAttribute('onclick',`controlSession(${id},'pause')`);
        timerLabel.innerText='▶ Running';
        setCardGlow('card-occupied');

        if (!activeTimers[id]) activeTimers[id]={seconds:0,prepaid:null,interval:null};
        if (action==='start') {
            activeTimers[id].seconds=0;
            activeTimers[id].prepaid=(opts.prepaid_seconds||data.prepaid_seconds)??null;
        }
        updateTimerDisplay(id);
        if (!activeTimers[id].interval) startTimerClock(id);
        if (action==='start') updateHeaderStats(+1);

    } else if (action==='pause') {
        badge.className=B_PAU; badge.innerText='Paused';
        pauseBtn.innerText='RESUME';
        pauseBtn.setAttribute('onclick',`controlSession(${id},'resume')`);
        timerLabel.innerText='⏸ Paused';
        setCardGlow('card-paused');
        pauseTimerClock(id);

    } else if (action==='stop') {
        badge.className=B_AVA; badge.innerText='Available';
        activeBtns.classList.add('hidden');
        startBtn.classList.remove('hidden');
        timerLabel.innerText='';
        setCardGlow('card-available');
        destroyTimer(id);

        // Receipt
        document.getElementById(`res-time-${id}`).innerText        = data.active_time  ?? '—';
        document.getElementById(`res-gaming-cost-${id}`).innerText  = data.gaming_cost  ?? '—';
        document.getElementById(`res-grand-total-${id}`).innerText  = data.grand_total  ?? '—';

        const addSec = document.getElementById(`res-addons-section-${id}`);
        if (data.has_addons && data.addons_list?.length>0) {
            document.getElementById(`res-addons-list-${id}`).innerHTML =
                data.addons_list.map(a=>`
                    <div class="flex justify-between text-gray-600 py-0.5">
                        <span>• ${a.item_name}</span>
                        <span class="font-mono">${parseFloat(a.item_price).toLocaleString()} IQD</span>
                    </div>`).join('');
            document.getElementById(`res-addons-total-${id}`).innerText = data.addons_total ?? '—';
            addSec.classList.remove('hidden');
        } else {
            addSec.classList.add('hidden');
        }
        receipt.classList.remove('hidden');

        // Store session_id for print button
        if (data.session_id) lastSessionId[id] = data.session_id;

        // Clean addons
        delete stationAddons[id];
        const tallyEl=document.getElementById(`addons-tally-${id}`);
        if(tallyEl) tallyEl.classList.add('hidden');

        // Revenue update
        const rev = parseFloat((data.grand_total??'0').replace(/[^0-9.]/g,''))||0;
        updateHeaderStats(-1, rev);

        // Append to history table live
        if (data.session_id) appendHistory(data, id);
    }
}

// --- HISTORY TABLE -----------------------------------------------------------
function appendHistory(data, stationId) {
    const tbody   = document.getElementById('history-table-body');
    const wrapper = document.getElementById('history-table-wrapper');
    const empty   = document.getElementById('history-empty');
    const counter = document.getElementById('history-count');
    const totalEl = document.getElementById('history-total-earnings');

    const stationName = document.querySelector(`#card-${stationId} h3`).innerText;
    const now  = new Date();
    const time = now.toTimeString().slice(0,5);
    const row  = document.createElement('tr');
    row.className='history-row border-b border-gray-800/50';
    const costNum = parseFloat((data.grand_total??'0').replace(/[^0-9.]/g,''))||0;
    row.innerHTML=`
        <td class="px-4 py-3 text-gray-600 font-mono">${data.session_id}</td>
        <td class="px-4 py-3 font-semibold text-gray-200">${stationName}</td>
        <td class="px-4 py-3 text-gray-400 font-mono">—</td>
        <td class="px-4 py-3 text-gray-400 font-mono">${time}</td>
        <td class="px-4 py-3 text-gray-300 font-mono">${data.active_time??'—'}</td>
        <td class="px-4 py-3"><span class="px-1.5 py-0.5 rounded text-[10px] font-medium bg-gray-800 text-gray-400">⏱ Open</span></td>
        <td class="px-4 py-3 text-right font-bold text-green-400 font-mono">${costNum.toLocaleString()}</td>
        <td class="px-4 py-3 text-center">
            <button onclick="printReceipt(${data.session_id})"
                    class="inline-flex items-center gap-1 px-2.5 py-1 bg-gray-800 hover:bg-gray-700 border border-gray-700 rounded-lg transition text-gray-300 hover:text-white text-xs">
                🖨️ Print
            </button>
        </td>`;
    tbody.insertBefore(row, tbody.firstChild);
    wrapper.classList.remove('hidden');
    empty.classList.add('hidden');
    const cnt = parseInt(counter.innerText)||0;
    counter.innerText = (cnt+1)+' sessions';

    // Update table footer total earnings
    let currentTotal = parseFloat((totalEl.innerText||'0').replace(/[^0-9.]/g,''))||0;
    currentTotal += costNum;
    totalEl.innerText = currentTotal.toLocaleString() + ' IQD';
}

// --- PRINT -------------------------------------------------------------------
function printReceipt(sessionId) {
    window.open(`print_receipt.php?session_id=${sessionId}`,'_blank','width=400,height=600');
}
function printReceiptFromCard(stationId) {
    const sid = lastSessionId[stationId];
    if (sid) printReceipt(sid);
    else showToast('Receipt not available yet.','error');
}

// --- AUTO-REFRESH (30s) ------------------------------------------------------
function syncWithServer() {
    const dot   = document.getElementById('sync-dot');
    const label = document.getElementById('sync-label');
    dot.className   = 'w-1.5 h-1.5 rounded-full bg-yellow-500 spinner';
    label.innerText = 'Syncing…';

    fetch('api_refresh.php').then(r=>r.json()).then(data=>{
        if (!data.success) return;

        // Update header from server truth
        hActive  = data.active_count;
        hRevenue = data.daily_revenue;
        updateHeaderStats(0,0);

        // Resync timers that drifted (don't overwrite paused timers)
        Object.entries(data.stations).forEach(([sid,info])=>{
            const id = parseInt(sid);
            if (info.status==='available') return;
            const t = activeTimers[id];
            if (t && !t.interval) return; // paused — skip
            if (t) {
                // Correct drift: if our count differs from server by >5s
                const diff = Math.abs(t.seconds - info.active_seconds);
                if (diff > 5) { t.seconds = info.active_seconds; updateTimerDisplay(id); }
            }
        });

        dot.className   = 'w-1.5 h-1.5 rounded-full bg-green-500';
        label.innerText = 'Synced';
        setTimeout(()=>{ dot.className='w-1.5 h-1.5 rounded-full bg-gray-700'; label.innerText='Synced'; }, 2000);
    }).catch(()=>{
        dot.className   = 'w-1.5 h-1.5 rounded-full bg-red-500';
        label.innerText = 'Offline';
    });
}
setInterval(syncWithServer, 30000);

// --- MODAL HELPERS -----------------------------------------------------------
function showModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.replace('hidden', 'flex');
}
function hideModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.replace('flex', 'hidden');
}

['start-modal', 'addon-modal', 'transfer-modal', 'report-modal', 'expense-modal', 'inventory-modal', 'analytics-modal'].forEach(id => {
    const el = document.getElementById(id);
    if (el) {
        el.addEventListener('click', e => { if (e.target === e.currentTarget) { hideModal(id); } });
    }
});

// ESC to close modals
document.addEventListener('keydown', e=>{
    if(e.key==='Escape'){
        ['start-modal', 'addon-modal', 'transfer-modal', 'report-modal', 'expense-modal', 'inventory-modal', 'analytics-modal'].forEach(id => hideModal(id));
    }
});

// Initialize active session timers on page load
Object.values(INITIAL_ACTIVE_SESSIONS).forEach(sess => {
    const elapsed  = parseInt(sess.elapsed_seconds) || 0;
    const prepaid  = sess.prepaid_seconds ? parseInt(sess.prepaid_seconds) : null;
    const isPaused = sess.session_status === 'paused';
    const id       = parseInt(sess.station_id);

    const badge      = document.getElementById(`badge-${id}`);
    const startBtn   = document.getElementById(`btn-start-${id}`);
    const activeBtns = document.getElementById(`active-btns-${id}`);
    const pauseBtn   = document.getElementById(`btn-pause-${id}`);
    const timerLabel = document.getElementById(`timer-label-${id}`);
    const card       = document.getElementById(`card-${id}`);

    if (startBtn) startBtn.classList.add('hidden');
    if (activeBtns) activeBtns.classList.remove('hidden');

    if (badge && pauseBtn && timerLabel && card) {
        if (isPaused) {
            badge.className = 'px-2 py-0.5 text-[10px] font-semibold rounded-full border bg-yellow-500/15 text-yellow-400 border-yellow-500/25';
            badge.innerText = 'Paused';
            pauseBtn.innerText = 'RESUME';
            pauseBtn.setAttribute('onclick', `controlSession(${id},'resume')`);
            timerLabel.innerText = '⏸ Paused';
            card.className = card.className.replace('card-available','card-paused');
        } else {
            badge.className = 'px-2 py-0.5 text-[10px] font-semibold rounded-full border bg-red-500/15 text-red-400 border-red-500/25';
            badge.innerText = 'Occupied';
            timerLabel.innerText = '▶ Running';
            card.className = card.className.replace('card-available','card-occupied');
        }
    }

    activeTimers[id] = { seconds: elapsed, prepaid: prepaid, interval: null };
    updateTimerDisplay(id);
    if (!isPaused) startTimerClock(id);
});

// --- THEME TOGGLE ------------------------------------------------------------
function toggleTheme() {
    const body = document.body;
    body.classList.toggle('light-mode');
    const isLight = body.classList.contains('light-mode');
    localStorage.setItem('theme', isLight ? 'light' : 'dark');
    const btn = document.getElementById('theme-toggle-btn');
    if (btn) btn.innerText = isLight ? '🌍 Dark Mode' : '☀️ Light Mode';
}

// Restore saved theme on load
if (localStorage.getItem('theme') === 'light') {
    document.body.classList.add('light-mode');
    const btn = document.getElementById('theme-toggle-btn');
    if (btn) btn.innerText = '🌍 Dark Mode';
}
</script>

<!-- Toast container -->
<div id="toast-container" class="fixed bottom-5 right-5 z-[100] space-y-2 pointer-events-none"></div>

</body>
</html>
