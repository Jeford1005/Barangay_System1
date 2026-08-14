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
require_role(['admin', 'staff', 'official']);

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
    <link rel="stylesheet" href="assets/css/design-system.css?v=<?= ASSET_VERSION ?>">
    <link rel="stylesheet" href="assets/css/fontawesome.min.css">
    <style>
        .audit-filters {
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-radius: var(--radius-card);
            padding: 1.25rem 1.5rem;
            margin-bottom: 1.5rem;
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            align-items: flex-end;
        }
        .audit-filters .form-group { margin: 0; min-width: 180px; flex: 1; }
        .audit-filters label { font-size: 12px; color: var(--muted); margin-bottom: 4px; display: block; }
        .audit-filters .form-control { width: 100%; }
        .audit-filters .filter-actions { display: flex; gap: 8px; }
        .pagination { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 1rem; }
        .pagination button,
        .pagination .page-link {
            padding: 6px 12px;
            border: 1px solid var(--border);
            background: var(--surface);
            border-radius: var(--radius-input);
            cursor: pointer;
            font-size: 13px;
            color: var(--ink);
        }
        .pagination button:hover,
        .pagination .page-link:hover { border-color: var(--amber-500); }
        .pagination .current-page {
            background: var(--amber-500);
            color: #2a1c00;
            border-color: var(--amber-500);
        }
        .table-responsive { overflow-x: auto; }
        .audit-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        .audit-table th {
            background: var(--graphite-950);
            color: #fff;
            padding: 0.7rem 0.75rem;
            text-align: left;
            font-weight: 600;
            white-space: nowrap;
            position: sticky;
            top: 0;
        }
        .audit-table td {
            padding: 0.6rem 0.75rem;
            border-bottom: 1px solid var(--border);
            vertical-align: top;
        }
        .audit-table tr:hover { background: var(--surface-2); }
        .audit-table th:first-child,
        .audit-table td:first-child { width: 50px; }
        .audit-table th:nth-child(2), .audit-table td:nth-child(2) { width: 150px; }
        .audit-table th:nth-child(3), .audit-table td:nth-child(3) { width: 110px; }
        .audit-table th:nth-child(5), .audit-table td:nth-child(5) { width: 130px; }
        .audit-table th:nth-child(6), .audit-table td:nth-child(6) { width: 80px; }
        .audit-table th:nth-child(7), .audit-table td:nth-child(7),
        .audit-table th:nth-child(8), .audit-table td:nth-child(8) {
            max-width: 240px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .audit-table td:nth-child(7):hover, .audit-table td:nth-child(8):hover { white-space: normal; overflow: visible; }
        .audit-table th:nth-child(9), .audit-table td:nth-child(9) { width: 130px; }
        .audit-table th:nth-child(10), .audit-table td:nth-child(10) { width: 90px; }
        .loading-row td { text-align: center; padding: 3rem; color: var(--muted); }
        .severity-badge { display: inline-block; padding: 3px 10px; border-radius: var(--radius-pill); font-size: 11px; font-weight: 600; }
        .severity-INFO { background: var(--neutral-bg); color: var(--neutral-fg); }
        .severity-LOW { background: var(--neutral-bg); color: var(--neutral-fg); }
        .severity-MEDIUM { background: var(--warning-bg); color: var(--warning-fg); }
        .severity-HIGH { background: var(--danger-bg); color: var(--danger-fg); }
        .severity-CRITICAL { background: var(--danger-bg); color: var(--danger-fg); }
        .action-badge { display: inline-block; padding: 2px 9px; border-radius: var(--radius-pill); font-size: 11px; font-weight: 600; text-transform: capitalize; }
        .action-create { background: var(--success-bg); color: var(--success-fg); }
        .action-update { background: var(--warning-bg); color: var(--warning-fg); }
        .action-delete { background: var(--danger-bg); color: var(--danger-fg); }
        .action-login { background: var(--info-bg); color: var(--info-fg); }
        .audit-desc { font-size: 11px; color: var(--muted); margin-top: 3px; }
    </style>
</head>
<body>
<div class="app">
    <!-- Sidebar -->
    <?php include __DIR__ . '/views/sidebar.php'; ?>
        

    <!-- Main Content -->
    <main class="main-content">
        <?php $variant = 'admin'; include __DIR__ . '/views/mobile-topbar.php'; ?>
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
                    <td><span class="action-badge ${actionClass}">${row.action_type}</span>${row.description ? `<div class="audit-desc">${row.description}</div>` : ''}</td>
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
