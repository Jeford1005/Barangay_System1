<?php
/**
 * dashboard.php
 * Barangay Bidduang - Admin Dashboard
 * Role: admin, staff
 */

require_once __DIR__ . '/config.php';
require_role(['admin', 'staff']);

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
    $metrics['blotter'] = (int)$pdo->query("SELECT COUNT(*) FROM blotter_reports WHERE status = 'open'")->fetchColumn();
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
    'blotter'   => "SELECT COUNT(*) FROM blotter_reports WHERE status = 'open' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
    'welfare'   => "SELECT COUNT(*) FROM welfare_requests WHERE status = 'pending' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
];
$prevTrendQueries = [
    'residents' => "SELECT COUNT(*) FROM residents WHERE created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)",
    'documents' => "SELECT COUNT(*) FROM document_requests WHERE status = 'pending' AND created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)",
    'blotter'   => "SELECT COUNT(*) FROM blotter_reports WHERE status = 'open' AND created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)",
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
        FROM blotter_reports
        WHERE YEAR(created_at) = YEAR(CURDATE())
        GROUP BY MONTH(created_at)
    ");
    foreach ($stmt->fetchAll() as $row) {
        $blotterMonthly[(int)$row['m'] - 1] = (int)$row['c'];
    }
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
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= ASSET_VERSION ?>">
    <link rel="stylesheet" href="assets/css/fontawesome.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <div class="app">
    <!-- Sidebar -->
    <?php include __DIR__ . '/views/sidebar.php'; ?>

    <!-- Main Content -->
    <main class="main-content">
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

        <!-- Charts Grid -->
        <section class="charts-grid">
            <div class="card">
                <h2 class="card-title"><i class="fas fa-chart-bar"></i> Document Requests (This Year)</h2>
                <div class="chart-container">
                    <canvas id="documentsChart"></canvas>
                </div>
            </div>
            <div class="card">
                <h2 class="card-title"><i class="fas fa-chart-line"></i> Monthly Blotter Reports</h2>
                <div class="chart-container">
                    <canvas id="blotterChart"></canvas>
                </div>
            </div>
        </section>

        <!-- Quick Links -->
        <section class="card">
            <h2 class="card-title"><i class="fas fa-bolt"></i> Quick Links</h2>
            <div class="quick-links">
                <a href="residents.php" class="quick-link-btn">
                    <i class="fas fa-users"></i> Residents
                </a>
                <a href="documents.php" class="quick-link-btn">
                    <i class="fas fa-file-text"></i> Documents
                </a>
                <a href="blotter.php" class="quick-link-btn">
                    <i class="fas fa-clipboard-list"></i> Blotter
                </a>
                <a href="welfare.php" class="quick-link-btn">
                    <i class="fas fa-hand-holding-heart"></i> Welfare
                </a>
                <a href="health.php" class="quick-link-btn">
                    <i class="fas fa-heartbeat"></i> Health
                </a>
            </div>
        </section>

        
    </main>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const documentsCtx = document.getElementById('documentsChart');
            if (documentsCtx) {
                new window.Chart(documentsCtx, {
                    type: 'bar',
                    data: {
                        labels: <?= json_encode($months, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                        datasets: [{
                            label: 'Document Requests',
                            data: <?= json_encode($chartValues, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
                            backgroundColor: 'rgba(26, 92, 56, 0.75)',
                            borderColor: '#1a5c38',
                            borderWidth: 1,
                            borderRadius: 6
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: {
                            y: { 
                                beginAtZero: true, 
                                ticks: { 
                                    stepSize: 1
                                }
                            },
                            x: {
                                ticks: {
                                    font: { size: 13 }
                                }
                            }
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
                            borderColor: '#dc2626',
                            backgroundColor: 'rgba(220, 38, 38, 0.1)',
                            tension: 0.3,
                            fill: true,
                            pointRadius: 4,
                            pointBackgroundColor: '#dc2626'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: {
                            y: { 
                                beginAtZero: true, 
                                ticks: { 
                                    stepSize: 1
                                }
                            },
                            x: {
                                ticks: {
                                    font: { size: 13 }
                                }
                            }
                        }
                    }
                });
            }
        });
    </script>
<script src="assets/js/main.js"></script>
</body>
</html>