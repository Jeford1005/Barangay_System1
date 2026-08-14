<?php
/**
 * dashboard.php
 * Barangay Bidduang - Admin Dashboard
 * Role: admin, staff
 */

require_once __DIR__ . '/config.php';
require_role(['admin', 'staff', 'official']);

$user = current_user();
$csrf = generate_csrf_token();

// Pre-initialize metrics and trends arrays
$metrics = [
    'residents' => 0,
    'documents' => 0,
    'blotter'   => 0,
    'welfare'   => 0,
    'health'    => 0,
];

$trends = [
    'residents' => 0,
    'documents' => 0,
    'blotter'   => 0,
    'welfare'   => 0,
];

$totalActiveRecords = 0;

// Fetch metrics from database
try {
    $metrics['residents'] = (int)$pdo->query("SELECT COUNT(*) FROM residents")->fetchColumn();
} catch (PDOException $e) { /* table may not exist yet */ }

try {
    $metrics['documents'] = (int)$pdo->query("SELECT COUNT(*) FROM document_requests WHERE status = 'pending'")->fetchColumn();
} catch (PDOException $e) { /* table may not exist yet */ }

try {
    $metrics['blotter'] = (int)$pdo->query("SELECT COUNT(*) FROM blotter_cases WHERE status = 'Open'")->fetchColumn();
} catch (PDOException $e) { /* table may not exist yet */ }

try {
    $metrics['welfare'] = (int)$pdo->query("SELECT COUNT(*) FROM welfare_requests WHERE status = 'pending'")->fetchColumn();
} catch (PDOException $e) { /* table may not exist yet */ }

try {
    $metrics['health'] = (int)$pdo->query("SELECT COUNT(*) FROM health_records WHERE DATE(created_at) = CURDATE()")->fetchColumn();
} catch (PDOException $e) { /* table may not exist yet */ }

$totalActiveRecords = $metrics['residents'] + $metrics['documents'] + $metrics['blotter'] + $metrics['welfare'];

// Calculate bar chart percentages
$maxMetric = max($metrics['residents'], $metrics['documents'], $metrics['blotter'], $metrics['welfare']);
$barWidths = [
    'residents' => $maxMetric > 0 ? round(($metrics['residents'] / $maxMetric) * 100, 2) : 0,
    'documents' => $maxMetric > 0 ? round(($metrics['documents'] / $maxMetric) * 100, 2) : 0,
    'blotter'   => $maxMetric > 0 ? round(($metrics['blotter'] / $maxMetric) * 100, 2) : 0,
    'welfare'   => $maxMetric > 0 ? round(($metrics['welfare'] / $maxMetric) * 100, 2) : 0,
];

// Trend calculations (last 30 days vs previous 30 days)
$trendQueries = [
    'residents' => "SELECT COUNT(*) FROM residents WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
    'documents' => "SELECT COUNT(*) FROM document_requests WHERE status = 'pending' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
    'blotter'   => "SELECT COUNT(*) FROM blotter_cases WHERE status = 'Open' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
    'welfare'   => "SELECT COUNT(*) FROM welfare_requests WHERE status = 'pending' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
];
$prevTrendQueries = [
    'residents' => "SELECT COUNT(*) FROM residents WHERE created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)",
    'documents' => "SELECT COUNT(*) FROM document_requests WHERE status = 'pending' AND created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)",
    'blotter'   => "SELECT COUNT(*) FROM blotter_cases WHERE status = 'Open' AND created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)",
    'welfare'   => "SELECT COUNT(*) FROM welfare_requests WHERE status = 'pending' AND created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)",
];

foreach (['residents', 'documents', 'blotter', 'welfare'] as $key) {
    try {
        $current = (int)$pdo->query($trendQueries[$key])->fetchColumn();
        $previous = (int)$pdo->query($prevTrendQueries[$key])->fetchColumn();
        if ($previous > 0) {
            $trends[$key] = round((($current - $previous) / $previous) * 100, 2);
        } elseif ($current > 0) {
            $trends[$key] = 100.0;
        } else {
            $trends[$key] = 0;
        }
    } catch (PDOException $e) {
        $trends[$key] = 0;
    }
}

// Monthly document stats for chart
$chartData = [];
try {
    $stmt = $pdo->query("
        SELECT MONTH(created_at) as m, COUNT(*) as c
        FROM document_requests
        WHERE YEAR(created_at) = YEAR(CURDATE())
        GROUP BY MONTH(created_at)
    ");
    $chartData = $stmt->fetchAll();
} catch (PDOException $e) { /* table may not exist yet */ }

// Fill missing months
$months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
$chartValues = array_fill(0, 12, 0);
foreach ($chartData as $row) {
    $chartValues[(int)$row['m'] - 1] = (int)$row['c'];
}
$maxChart = max($chartValues) ?: 1;
$hasDocData = array_sum($chartValues) > 0;

// Document status distribution for doughnut chart
$statusData = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
try {
    $stmt = $pdo->query("SELECT status, COUNT(*) as c FROM document_requests GROUP BY status");
    while ($row = $stmt->fetch()) {
        $status = strtolower($row['status'] ?? 'pending');
        if (isset($statusData[$status])) {
            $statusData[$status] = (int)$row['c'];
        }
    }
} catch (PDOException $e) { /* table may not exist yet */ }

// Monthly blotter reports for trend line chart
$blotterMonthly = array_fill(0, 12, 0);
try {
    $stmt = $pdo->query("
        SELECT MONTH(created_at) as m, COUNT(*) as c
        FROM blotter_cases
        WHERE YEAR(created_at) = YEAR(CURDATE())
        GROUP BY MONTH(created_at)
    ");
    foreach ($stmt->fetchAll() as $row) {
        $blotterMonthly[(int)$row['m'] - 1] = (int)$row['c'];
    }
} catch (PDOException $e) { /* table may not exist yet */ }
$hasBlotterData = array_sum($blotterMonthly) > 0;

// Monthly resident registrations for trend bar chart
$residentMonthly = array_fill(0, 12, 0);
try {
    $stmt = $pdo->query("
        SELECT MONTH(created_at) as m, COUNT(*) as c
        FROM residents
        WHERE YEAR(created_at) = YEAR(CURDATE())
        GROUP BY MONTH(created_at)
    ");
    foreach ($stmt->fetchAll() as $row) {
        $residentMonthly[(int)$row['m'] - 1] = (int)$row['c'];
    }
} catch (PDOException $e) { /* table may not exist yet */ }
$hasResidentData = array_sum($residentMonthly) > 0;


// "Needs attention" — oldest pending document request + oldest open blotter case
$oldestPendingDoc = null;
try {
    $stmt = $pdo->query("
        SELECT dr.id, dr.document_type, dr.created_at,
               CONCAT(r.first_name, ' ', r.last_name) AS resident_name
        FROM document_requests dr
        LEFT JOIN residents r ON r.id = dr.resident_id
        WHERE dr.status = 'Pending'
        ORDER BY dr.created_at ASC
        LIMIT 1
    ");
    $oldestPendingDoc = $stmt->fetch();
} catch (PDOException $e) { /* table may not exist yet */ }

$oldestOpenBlotter = null;
try {
    $stmt = $pdo->query("
        SELECT id, case_number, case_type, created_at
            FROM blotter_cases
            WHERE status = 'Open'
            ORDER BY created_at ASC
            LIMIT 1
    ");
    $oldestOpenBlotter = $stmt->fetch();
} catch (PDOException $e) { /* table may not exist yet */ }

// Officials active/inactive split
$officialActive = 0; $officialInactive = 0;
try {
    $stmt = $pdo->query("SELECT is_active, COUNT(*) AS c FROM officials GROUP BY is_active");
    foreach ($stmt->fetchAll() as $row) {
        if ($row['is_active']) $officialActive = (int)$row['c']; else $officialInactive = (int)$row['c'];
    }
} catch (PDOException $e) { /* table may not exist yet */ }

// Last broadcast sent
$lastBroadcast = null;
try {
    $stmt = $pdo->query("
        SELECT id, title, sent_at
        FROM broadcast_messages
        ORDER BY sent_at DESC
        LIMIT 1
    ");
    $lastBroadcast = $stmt->fetch();
} catch (PDOException $e) { /* table may not exist yet */ }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="assets/img/Brgy_Bidduang.png">
    <link rel="shortcut icon" type="image/png" href="assets/img/Brgy_Bidduang.png">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Barangay Bidduang</title>
    <link rel="stylesheet" href="assets/css/design-system.css?v=<?= ASSET_VERSION ?>">
    <link rel="stylesheet" href="assets/css/fontawesome.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <div class="app">
    <!-- Sidebar -->
    <?php include __DIR__ . '/views/sidebar.php'; ?>

    <!-- Main Content -->
    <main class="main-content">
        <?php $variant = 'admin'; include __DIR__ . '/views/mobile-topbar.php'; ?>
        <!-- Header with Greeting & Stats -->
        <div class="dashboard-header">
            <div class="header-left">
                <h1 class="page-title">Hi, <span class="role-badge role-admin"><?= ucfirst(esc($user['role'] ?? 'Admin')); ?></span> Welcome Back!</h1>
                <p class="page-subtitle">Barangay Bidduang Management Dashboard</p>
            </div>
        </div>

        <!-- Metric Cards -->
        <section class="metrics-grid">
            <div class="metric-card">
                <div class="metric-content">
                    <p class="metric-title">Total Residents</p>
                    <h3 class="metric-value"><?= number_format($metrics['residents']); ?></h3>
                    <p class="metric-trend <?= ($trends['residents'] ?? 0) >= 0 ? 'positive' : 'negative'; ?>">
                        <?php if (!isset($trends['residents']) || $trends['residents'] === null): ?>
                            New <span>(30 days)</span>
                        <?php else: ?>
                            <?= ($trends['residents'] >= 0 ? '+' : '') . number_format($trends['residents'], 2); ?>%
                            <span>(30 days)</span>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="metric-icon blue"><i class="fas fa-users"></i></div>
            </div>
            <div class="metric-card">
                <div class="metric-content">
                    <p class="metric-title">Pending Documents</p>
                    <h3 class="metric-value"><?= number_format($metrics['documents']); ?></h3>
                    <p class="metric-trend <?= ($trends['documents'] ?? 0) >= 0 ? 'positive' : 'negative'; ?>">
                        <?php if (!isset($trends['documents']) || $trends['documents'] === null): ?>
                            New <span>(30 days)</span>
                        <?php else: ?>
                            <?= ($trends['documents'] >= 0 ? '+' : '') . number_format($trends['documents'], 2); ?>%
                            <span>(30 days)</span>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="metric-icon orange"><i class="fas fa-file-text"></i></div>
            </div>
            <div class="metric-card">
                <div class="metric-content">
                    <p class="metric-title">Open Blotter Cases</p>
                    <h3 class="metric-value"><?= number_format($metrics['blotter']); ?></h3>
                    <p class="metric-trend <?= ($trends['blotter'] ?? 0) >= 0 ? 'positive' : 'negative'; ?>">
                        <?php if (!isset($trends['blotter']) || $trends['blotter'] === null): ?>
                            New <span>(30 days)</span>
                        <?php else: ?>
                            <?= ($trends['blotter'] >= 0 ? '+' : '') . number_format($trends['blotter'], 2); ?>%
                            <span>(30 days)</span>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="metric-icon red"><i class="fas fa-clipboard-list"></i></div>
            </div>
            <div class="metric-card">
                <div class="metric-content">
                    <p class="metric-title">Welfare Assistance</p>
                    <h3 class="metric-value"><?= number_format($metrics['welfare']); ?></h3>
                    <p class="metric-trend <?= ($trends['welfare'] ?? 0) >= 0 ? 'positive' : 'negative'; ?>">
                        <?php if (!isset($trends['welfare']) || $trends['welfare'] === null): ?>
                            New <span>(30 days)</span>
                        <?php else: ?>
                            <?= ($trends['welfare'] >= 0 ? '+' : '') . number_format($trends['welfare'], 2); ?>%
                            <span>(30 days)</span>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="metric-icon purple"><i class="fas fa-hand-holding-heart"></i></div>
            </div>
        </section>

        <!-- Needs attention + Officials + Broadcast -->
        <section class="metrics-grid" style="grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));">
            <div class="card">
                <h2 class="card-title"><i class="fas fa-bell"></i> Needs Attention</h2>
                <?php if ($oldestPendingDoc): ?>
                    <div class="attn-row">
                        <span class="badge badge-info">Doc</span>
                        <div>
                            <strong><?= esc($oldestPendingDoc['document_type']); ?></strong>
                            <span class="mono"> — <?= esc($oldestPendingDoc['resident_name'] ?? 'Unknown'); ?></span>
                            <div class="muted-sm">Pending since <?= date('M j, Y', strtotime($oldestPendingDoc['created_at'])); ?></div>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($oldestOpenBlotter): ?>
                    <div class="attn-row">
                        <span class="badge badge-warning">Blotter</span>
                        <div>
                            <strong><?= esc($oldestOpenBlotter['case_number']); ?></strong>
                            <span class="mono"> — <?= esc($oldestOpenBlotter['incident_type'] ?? ''); ?></span>
                            <div class="muted-sm">Open since <?= date('M j, Y', strtotime($oldestOpenBlotter['created_at'])); ?></div>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if (!$oldestPendingDoc && !$oldestOpenBlotter): ?>
                    <p class="muted-sm">Nothing pending. All caught up.</p>
                <?php endif; ?>
            </div>

            <div class="card">
                <h2 class="card-title"><i class="fas fa-user-tie"></i> Officials</h2>
                <div class="split-row">
                    <div class="split-item">
                        <span class="split-num success"><?= $officialActive; ?></span>
                        <span class="muted-sm">Active</span>
                    </div>
                    <div class="split-item">
                        <span class="split-num neutral"><?= $officialInactive; ?></span>
                        <span class="muted-sm">Inactive</span>
                    </div>
                </div>
            </div>

            <div class="card">
                <h2 class="card-title"><i class="fas fa-tower-broadcast"></i> Last Broadcast</h2>
                <?php if ($lastBroadcast): ?>
                    <strong><?= esc($lastBroadcast['title']); ?></strong>
                    <div class="muted-sm">Sent <?= date('M j, Y g:i A', strtotime($lastBroadcast['sent_at'])); ?></div>
                <?php else: ?>
                    <p class="muted-sm">No broadcasts sent yet.</p>
                <?php endif; ?>
            </div>
        </section>

        <!-- Detailed Reports -->
        <section class="reports-section">
            <div class="reports-header">
                <h2 class="reports-title">Detailed Reports</h2>
            </div>
            <div class="reports-body">
                <div class="reports-left">
                    <div class="reports-summary">
                        <h3 class="reports-big-number"><?= number_format($totalActiveRecords); ?></h3>
                        <p class="reports-label">Total Active Records</p>
                        <p class="reports-desc">Combined count of registered residents and pending document requests currently in the system.</p>
                    </div>
                    <div class="bar-chart-list">
                        <div class="bar-chart-item">
                            <span class="bar-chart-label">Residents</span>
                            <div class="bar-chart-track">
                                <div class="bar-chart-fill blue" style="width: <?= $barWidths['residents']; ?>%;"></div>
                            </div>
                            <span class="bar-chart-value"><?= number_format($metrics['residents']); ?></span>
                        </div>
                        <div class="bar-chart-item">
                            <span class="bar-chart-label">Documents</span>
                            <div class="bar-chart-track">
                                <div class="bar-chart-fill orange" style="width: <?= $barWidths['documents']; ?>%;"></div>
                            </div>
                            <span class="bar-chart-value"><?= number_format($metrics['documents']); ?></span>
                        </div>
                        <div class="bar-chart-item">
                            <span class="bar-chart-label">Blotter</span>
                            <div class="bar-chart-track">
                                <div class="bar-chart-fill red" style="width: <?= $barWidths['blotter']; ?>%;"></div>
                            </div>
                            <span class="bar-chart-value"><?= number_format($metrics['blotter']); ?></span>
                        </div>
                        <div class="bar-chart-item">
                            <span class="bar-chart-label">Welfare</span>
                            <div class="bar-chart-track">
                                <div class="bar-chart-fill purple" style="width: <?= $barWidths['welfare']; ?>%;"></div>
                            </div>
                            <span class="bar-chart-value"><?= number_format($metrics['welfare']); ?></span>
                        </div>
                    </div>
                </div>
                <div class="reports-right">
                    <div class="reports-legend">
                        <div class="legend-header">
                            <span class="legend-total"><?= number_format($totalActiveRecords); ?></span>
                            <span class="legend-label">Total</span>
                        </div>
                        <div class="legend-items">
                            <div class="legend-item">
                                <span class="legend-swatch pending"></span>
                                <span class="legend-text">Pending</span>
                                <span class="legend-count"><?= (int)$statusData['pending']; ?></span>
                            </div>
                            <div class="legend-item">
                                <span class="legend-swatch approved"></span>
                                <span class="legend-text">Approved</span>
                                <span class="legend-count"><?= (int)$statusData['approved']; ?></span>
                            </div>
                            <div class="legend-item">
                                <span class="legend-swatch rejected"></span>
                                <span class="legend-text">Rejected</span>
                                <span class="legend-count"><?= (int)$statusData['rejected']; ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Analytics Grid -->
        <section class="charts-grid">
            <div class="card chart-card">
                <div class="chart-head">
                    <h2 class="card-title"><i class="fas fa-chart-pie"></i> Document Status</h2>
                    <span class="chart-total"><?= array_sum($statusData) ?> total</span>
                </div>
                <div class="chart-container donut-wrap">
                    <?php if (array_sum($statusData) > 0): ?>
                        <div class="donut-box">
                            <canvas id="statusChart"></canvas>
                            <div class="donut-center">
                                <strong><?= array_sum($statusData) ?></strong>
                                <span>requests</span>
                            </div>
                        </div>
                        <ul class="donut-legend">
                            <li><span class="dot dot-warning"></span> Pending <b><?= $statusData['pending'] ?></b></li>
                            <li><span class="dot dot-success"></span> Approved <b><?= $statusData['approved'] ?></b></li>
                            <li><span class="dot dot-danger"></span> Rejected <b><?= $statusData['rejected'] ?></b></li>
                        </ul>
                    <?php else: ?>
                        <div class="empty-state"><i class="fas fa-chart-pie"></i><h3>No requests yet</h3><p>Document status will appear here.</p></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card chart-card">
                <div class="chart-head">
                    <h2 class="card-title"><i class="fas fa-users"></i> Resident Registrations</h2>
                    <span class="chart-sub">This Year</span>
                </div>
                <div class="chart-container">
                    <?php if ($hasResidentData): ?>
                        <canvas id="residentsChart"></canvas>
                    <?php else: ?>
                        <div class="empty-state"><i class="fas fa-users"></i><h3>No registrations yet</h3><p>No residents registered this year.</p></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card chart-card">
                <div class="chart-head">
                    <h2 class="card-title"><i class="fas fa-chart-line"></i> Blotter Reports</h2>
                    <span class="chart-sub">Monthly</span>
                </div>
                <div class="chart-container">
                    <?php if ($hasBlotterData): ?>
                        <canvas id="blotterChart"></canvas>
                    <?php else: ?>
                        <div class="empty-state"><i class="fas fa-chart-line"></i><h3>No blotter reports filed yet</h3><p>Monthly reports will appear here once cases are logged.</p></div>
                    <?php endif; ?>
                </div>
            </div>
        </section>

    </main>
    </div>

    <script>
        const AXIS = '#6B7076';
        const GRID = 'rgba(21,23,27,0.07)';
        const FONT = "'Public Sans', system-ui, sans-serif";
        document.addEventListener('DOMContentLoaded', function() {
            const documentsCtx = document.getElementById('residentsChart');
            if (documentsCtx) {
                new window.Chart(documentsCtx, {
                    type: 'bar',
                    data: {
                        labels: <?= json_encode($months, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                        datasets: [{
                            label: 'Resident Registrations',
                            data: <?= json_encode($residentMonthly, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                            backgroundColor: '#E8A33D',
                            hoverBackgroundColor: '#15171B',
                            borderRadius: 6,
                            maxBarThickness: 34
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false }, tooltip: { backgroundColor: '#15171B', padding: 10 } },
                        scales: {
                            y: { beginAtZero: true, ticks: { stepSize: 1, color: AXIS, font: { family: FONT } }, grid: { color: GRID } },
                            x: { ticks: { color: AXIS, font: { family: FONT, size: 12 } }, grid: { display: false } }
                        }
                    }
                });
            }

            const blotterCtx = document.getElementById('blotterChart');
            if (blotterCtx) {
                new window.Chart(blotterCtx, {
                    type: 'line',
                    data: {
                        labels: <?= json_encode($months, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                        datasets: [{
                            label: 'Blotter Reports',
                            data: <?= json_encode($blotterMonthly, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                            borderColor: '#15171B',
                            backgroundColor: 'rgba(232,163,61,0.14)',
                            tension: 0.35,
                            fill: true,
                            pointRadius: 4,
                            pointBackgroundColor: '#E8A33D',
                            pointBorderColor: '#fff',
                            pointBorderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false }, tooltip: { backgroundColor: '#15171B', padding: 10 } },
                        scales: {
                            y: { beginAtZero: true, ticks: { stepSize: 1, color: AXIS, font: { family: FONT } }, grid: { color: GRID } },
                            x: { ticks: { color: AXIS, font: { family: FONT, size: 12 } }, grid: { display: false } }
                        }
                    }
                });
            }

            const statusCtx = document.getElementById('statusChart');
            if (statusCtx) {
                new window.Chart(statusCtx, {
                    type: 'doughnut',
                    data: {
                        labels: ['Pending', 'Approved', 'Rejected'],
                        datasets: [{
                            data: [<?= (int)$statusData['pending'] ?>, <?= (int)$statusData['approved'] ?>, <?= (int)$statusData['rejected'] ?>],
                            backgroundColor: ['#9A6A0B', '#1E7A4C', '#B03A2E'],
                            borderColor: '#fff',
                            borderWidth: 3,
                            hoverOffset: 6
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '68%',
                        plugins: { legend: { display: false }, tooltip: { backgroundColor: '#15171B', padding: 10 } }
                    }
                });
            }
        });
    </script>
<script src="assets/js/main.js"></script>
</body>
</html>