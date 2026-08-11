<?php
/**
 * admin-audit.php
 * Barangay Bidduang - Audit Log Viewer
 * Role: admin, staff
 *
 * Features:
 *   - Date range filtering (date_from, date_to)
 *   - Filter by module, action type, user, severity
 *   - Search across all fields
 *   - JSON diff viewer for old_values / new_values
 *   - Pagination
 */

require_once __DIR__ . '/config.php';
require_role(['admin', 'staff']);

$currentUser = current_user();

// Sidebar nav order (canonical)
$sidebar = [
    'dashboard.php'    => ['Dashboard',       'fa-tachometer-alt'],
    'residents.php'    => ['Residents',       'fa-users'],
    'households.php'   => ['Households',      'fa-house'],
    'officials.php'    => ['Officials',       'fa-user-tie'],
    'documents.php'    => ['Documents',       'fa-file-text'],
    'blotter.php'      => ['Blotter',         'fa-scale-balanced'],
    'welfare.php'      => ['Welfare',         'fa-hand-holding-heart'],
    'health.php'       => ['Health',          'fa-heartbeat'],
    'reports.php'      => ['Reports',         'fa-chart-bar'],
    'accounts.php'     => ['Accounts',        'fa-user-gear'],
    'admin-audit.php'  => ['Audit Logs',      'fa-search-plus'],
    'broadcast.php'    => ['Broadcast Manager', 'fa-tower-broadcast'],    'logout.php'       => ['Logout',          'fa-sign-out-alt'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="assets/img/Brgy_Bidduang.png">
    <link rel="shortcut icon" type="image/png" href="assets/img/Brgy_Bidduang.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Logs - Barangay Bidduang Portal</title>
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= filemtime(__DIR__ . '/assets/css/dashboard.css') ?>">
    <link rel="stylesheet" href="assets/css/fontawesome.min.css">
    <style>
        .audit-filters {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border: 1px solid #e9ecef;
        }
        .audit-filters .row {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 0.75rem;
        }
        .audit-filters .row:last-child {
            margin-bottom: 0;
        }
        .audit-filters .col {
            flex: 1;
            min-width: 180px;
        }
        .audit-filters label {
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            color: #495057;
            margin-bottom: 0.25rem;
        }
        .audit-filters input,
        .audit-filters select {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 1px solid #ced4da;
            border-radius: 4px;
            font-size: 0.9rem;
        }
        .audit-filters button {
            min-width: 120px;
        }
        .severity-badge {
            padding: 0.2rem 0.6rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .severity-INFO { background: #cce5ff; color: #004085; }
        .severity-WARN { background: #fff3cd; color: #856404; }
        .severity-CRITICAL { background: #f8d7da; color: #721c24; }
        .action-badge {
            padding: 0.2rem 0.6rem;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        .action-CREATE { background: #d4edda; color: #155724; }
        .action-READ { background: #d1ecf1; color: #0c5460; }
        .action-UPDATE { background: #fff3cd; color: #856404; }
        .action-DELETE { background: #f8d7da; color: #721c24; }
        .action-EXPORT { background: #e2d9f3; color: #4b0f7a; }
        .action-AUTH { background: #f5c6f0; color: #66107a; }
        .json-cell {
            font-family: 'Courier New', monospace;
            font-size: 0.8rem;
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            cursor: pointer;
        }
        .json-cell:hover {
            background: #f1f1f1;
            white-space: pre-wrap;
            overflow-x: auto;
        }
        .json-diff-modal {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.7);
            z-index: 9999;
            padding: 2rem;
            overflow-y: auto;
        }
        .json-diff-modal .content {
            background: #fff;
            border-radius: 8px;
            padding: 1.5rem;
            max-width: 800px;
            margin: 0 auto;
            font-family: 'Courier New', monospace;
            font-size: 0.85rem;
            white-space: pre-wrap;
            overflow-x: auto;
        }
        .json-diff-modal .close-btn {
            position: absolute;
            top: 1rem;
            right: 1.5rem;
            font-size: 1.5rem;
            cursor: pointer;
            color: #fff;
        }
        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 1.5rem;
        }
        .pagination button,
        .pagination .page-link {
            padding: 0.5rem 1rem;
            border: 1px solid #ddd;
            background: #fff;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
        }
        .pagination button:hover,
        .pagination .page-link:hover {
            background: #f0f0f0;
        }
        .pagination .current-page {
            background: #1a5c38;
            color: #fff;
            border-color: #1a5c38;
        }
        .table-responsive {
            overflow-x: auto;
        }
        .audit-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }
        .audit-table th {
            background: #1a5c38;
            color: #fff;
            padding: 0.75rem;
            text-align: left;
            font-weight: 600;
            white-space: nowrap;
        }
        .audit-table td {
            padding: 0.6rem 0.75rem;
            border-bottom: 1px solid #e9ecef;
            vertical-align: top;
        }
        .audit-table tr:hover {
            background: #f8f9fa;
        }
        .audit-table th:first-child,
        .audit-table td:first-child {
            width: 50px;
        }
        .loading-row td {
            text-align: center;
            padding: 3rem;
            color: #999;
        }
    </style>
</head>
<body>
<div class="app">
    <!-- Sidebar -->
    <?php include __DIR__ . '/views/sidebar.php'; ?>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Header -->
        <div class="page-header">
            <div>
                <h1><i class="fas fa-search-plus"></i> Audit Log Viewer</h1>
                <p>Monitor all system actions and changes</p>
            </div>
        </div>

        <!-- Filters -->
        <div class="audit-filters">
            <div class="row">
                <div class="col">
                    <label for="dateFrom">Date From</label>
                    <input type="date" id="dateFrom">
                </div>
                <div class="col">
                    <label for="dateTo">Date To</label>
                    <input type="date" id="dateTo">
                </div>
                <div class="col">
                    <label for="moduleFilter">Module</label>
                    <select id="moduleFilter">
                        <option value="">All Modules</option>
                    </select>
                </div>
                <div class="col">
                    <label for="actionFilter">Action Type</label>
                    <select id="actionFilter">
                        <option value="">All Actions</option>
                        <option value="CREATE">CREATE</option>
                        <option value="READ">READ</option>
                        <option value="UPDATE">UPDATE</option>
                        <option value="DELETE">DELETE</option>
                        <option value="EXPORT">EXPORT</option>
                        <option value="AUTH">AUTH</option>
                    </select>
                </div>
                <div class="col">
                    <label for="severityFilter">Severity</label>
                    <select id="severityFilter">
                        <option value="">All Severities</option>
                        <option value="INFO">INFO</option>
                        <option value="WARN">WARN</option>
                        <option value="CRITICAL">CRITICAL</option>
                    </select>
                </div>
            </div>
            <div class="row">
                <div class="col">
                    <label for="userFilter">User ID</label>
                    <input type="number" id="userFilter" placeholder="User ID">
                </div>
                <div class="col">
                    <label for="searchFilter">Search</label>
                    <input type="text" id="searchFilter" placeholder="Search in logs...">
                </div>
                <div class="col d-flex-end">
                    <button class="btn btn-primary" onclick="loadAuditLogs(0)">
                        <i class="fas fa-search"></i> Apply Filters
                    </button>
                    <button class="btn btn-secondary" onclick="clearFilters()">
                        <i class="fas fa-redo"></i> Clear
                    </button>
                </div>
            </div>
        </div>

        <!-- Results -->
        <div class="card">
            <div class="card-header">
                <h2>Audit Log Entries</h2>
                <div class="toolbar">
                    <span class="text-muted" id="totalCount">0 entries</span>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="audit-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Timestamp</th>
                                <th>User</th>
                                <th>Action</th>
                                <th>Module</th>
                                <th>Record ID</th>
                                <th>Old Values</th>
                                <th>New Values</th>
                                <th>IP Address</th>
                                <th>Severity</th>
                            </tr>
                        </thead>
                        <tbody id="auditTableBody">
                            <tr class="loading-row">
                                <td colspan="10">Loading audit logs... <i class="fas fa-spinner fa-spin"></i></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="pagination" id="pagination"></div>
            </div>
        </div>
    </main>
</div>

<!-- JSON Diff Modal -->
<div class="json-diff-modal" id="jsonDiffModal">
    <span class="close-btn" onclick="closeJsonModal()">&times;</span>
    <div class="content" id="jsonDiffContent"></div>
</div>

<script>
const baseUrl = '<?= BASE_URL ?>';
let currentPage = 0;

// Load distinct module names for filter dropdown
async function loadModules() {
    try {
        const res = await fetch(baseUrl + '/api/audit-logs.php?action=modules');
        const data = await res.json();
        if (data.status === 'success' && data.modules) {
            const select = document.getElementById('moduleFilter');
            data.modules.forEach(m => {
                const opt = document.createElement('option');
                opt.value = m;
                opt.textContent = m;
                select.appendChild(opt);
            });
        }
    } catch (e) {
        console.error('Failed to load modules:', e);
    }
}

// Load audit logs with filters
async function loadAuditLogs(page = 0) {
    currentPage = page;

    const params = new URLSearchParams({
        limit: 50,
        offset: page * 50,
        module: document.getElementById('moduleFilter').value,
        action_type: document.getElementById('actionFilter').value,
        severity_level: document.getElementById('severityFilter').value,
        user_id: document.getElementById('userFilter').value,
        date_from: document.getElementById('dateFrom').value,
        date_to: document.getElementById('dateTo').value,
        search: document.getElementById('searchFilter').value,
        sort_by: 'timestamp',
        sort_dir: 'DESC',
    });

    const tbody = document.getElementById('auditTableBody');
    tbody.innerHTML = '<tr class="loading-row"><td colspan="10">Loading... <i class="fas fa-spinner fa-spin"></i></td></tr>';

    try {
        const res = await fetch(baseUrl + '/api/audit-logs.php?' + params.toString());
        const data = await res.json();

        if (data.status !== 'success') {
            tbody.innerHTML = '<tr><td colspan="10">Error loading data</td></tr>';
            return;
        }

        document.getElementById('totalCount').textContent = data.total + ' entries';

        if (data.data.length === 0) {
            tbody.innerHTML = '<tr class="loading-row"><td colspan="10">No audit log entries found</td></tr>';
        } else {
            tbody.innerHTML = data.data.map((row, i) => {
                const rowNum = data.offset + i + 1;
                const severityClass = row.severity_level || 'INFO';
                const actionClass = row.action_type || 'READ';
                const userName = row.user_id ? (row.user_role || 'User #' + row.user_id) : 'System';

                return `
                <tr>
                    <td>${rowNum}</td>
                    <td>${new Date(row.timestamp).toLocaleString()}</td>
                    <td>${userName}</td>
                    <td><span class="action-badge ${actionClass}">${row.action_type}</span></td>
                    <td>${row.module_name || ''}</td>
                    <td>${row.record_id || ''}</td>
                    <td class="json-cell" onclick="showJsonDiff('${row.old_values || 'null'}', 'Old Values')">${formatJsonCell(row.old_values)}</td>
                    <td class="json-cell" onclick="showJsonDiff('${row.new_values || 'null'}', 'New Values')">${formatJsonCell(row.new_values)}</td>
                    <td>${row.ip_address || ''}</td>
                    <td><span class="severity-badge severity-${severityClass}">${severityClass}</span></td>
                </tr>`;
            }).join('');
        }

        // Pagination
        const totalPages = Math.ceil(data.total / 50);
        const pagination = document.getElementById('pagination');
        let html = '';
        if (totalPages > 1) {
            for (let i = 0; i < totalPages; i++) {
                const isCurrent = (i === page);
                html += `<button ${isCurrent ? 'class="current-page"' : ''} onclick="loadAuditLogs(${i})">${i + 1}</button>`;
            }
        }
        pagination.innerHTML = html;

    } catch (e) {
        tbody.innerHTML = '<tr><td colspan="10">Error: ' + e.message + '</td></tr>';
    }
}

function formatJsonCell(jsonStr) {
    if (!jsonStr) return '<span class="text-muted">null</span>';
    try {
        const obj = JSON.parse(jsonStr);
        const keys = Object.keys(obj);
        if (keys.length === 0) return '{}';
        return keys.slice(0, 3).map(k => `${k}: ${JSON.stringify(obj[k])}`).join(', ') + (keys.length > 3 ? '...' : '');
    } catch (e) {
        return jsonStr.substring(0, 60) + (jsonStr.length > 60 ? '...' : '');
    }
}

function showJsonDiff(jsonStr, title) {
    const modal = document.getElementById('jsonDiffModal');
    const content = document.getElementById('jsonDiffContent');

    if (!jsonStr) {
        content.textContent = 'null';
    } else {
        try {
            const obj = JSON.parse(jsonStr);
            content.textContent = JSON.stringify(obj, null, 2);
        } catch (e) {
            content.textContent = jsonStr;
        }
    }

    modal.style.display = 'block';
}

function closeJsonModal() {
    document.getElementById('jsonDiffModal').style.display = 'none';
}

function clearFilters() {
    document.getElementById('moduleFilter').value = '';
    document.getElementById('actionFilter').value = '';
    document.getElementById('severityFilter').value = '';
    document.getElementById('userFilter').value = '';
    document.getElementById('dateFrom').value = '';
    document.getElementById('dateTo').value = '';
    document.getElementById('searchFilter').value = '';
    loadAuditLogs(0);
}

window.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeJsonModal();
});

loadModules();
loadAuditLogs(0);
</script>
<script src="assets/js/main.js"></script>
</body>
</html>
