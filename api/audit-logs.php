<?php
/**
 * API: audit-logs.php
 * Returns paginated, filtered audit log entries as JSON.
 *
 * Query parameters:
 *   ?limit=50&offset=0
 *   ?module=Resident&action_type=CREATE&severity_level=WARN
 *   ?user_id=1&date_from=2024-01-01&date_to=2024-12-31
 *   ?search=password
 *   ?sort_by=timestamp&sort_dir=DESC
 */

require_once __DIR__ . '/../config.php';
require_role(['admin', 'staff']);

header('Content-Type: application/json');

// Handle modules list request
if (isset($_GET['action']) && $_GET['action'] === 'modules') {
    $stmt = $pdo->query("SELECT DISTINCT module_name FROM audit_logs WHERE module_name IS NOT NULL ORDER BY module_name");
    $modules = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    echo json_encode(['status' => 'success', 'modules' => $modules]);
    exit;
}

// Build query and parameters dynamically
$whereClauses = [];
$params = [];

$limit = min((int)($_GET['limit'] ?? 50), 200);
$offset = (int)($_GET['offset'] ?? 0);
$search = trim($_GET['search'] ?? '');
$module = trim($_GET['module'] ?? '');
$actionType = trim($_GET['action_type'] ?? '');
$severity = trim($_GET['severity_level'] ?? '');
$userId = trim($_GET['user_id'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$sortDir = strtoupper($_GET['sort_dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
$sortBy = in_array($_GET['sort_by'] ?? '', ['timestamp', 'user_id', 'action_type', 'module_name', 'severity_level'])
    ? $_GET['sort_by'] : 'timestamp';

// Apply filters
if ($module !== '') {
    $whereClauses[] = 'module_name = :module';
    $params[':module'] = $module;
}
if ($actionType !== '') {
    $whereClauses[] = 'action_type = :action';
    $params[':action'] = $actionType;
}
if ($severity !== '') {
    $whereClauses[] = 'severity_level = :severity';
    $params[':severity'] = $severity;
}
if ($userId !== '') {
    $whereClauses[] = 'user_id = :user_id';
    $params[':user_id'] = (int)$userId;
}
if ($dateFrom !== '') {
    $whereClauses[] = 'timestamp >= :date_from';
    $params[':date_from'] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $whereClauses[] = 'timestamp <= :date_to';
    $params[':date_to'] = $dateTo . ' 23:59:59';
}
if ($search !== '') {
    $whereClauses[] = "(old_values LIKE :search OR new_values LIKE :search OR user_agent LIKE :search OR ip_address LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

$whereSQL = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

// Get total count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs $whereSQL");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

// Get paginated results
// Note: LIMIT/OFFSET cannot use named parameters with PDO::ATTR_EMULATE_PREPARES=false,
// so we inline the already-validated integer values and bind only the WHERE params.
$stmt = $pdo->prepare("
    SELECT log_id, timestamp, user_id, user_role, action_type, module_name,
           record_id, old_values, new_values, ip_address, user_agent, severity_level
    FROM audit_logs
    $whereSQL
    ORDER BY $sortBy $sortDir
    LIMIT " . (int)$limit . " OFFSET " . (int)$offset
);

$stmt->execute($params);
$logs = $stmt->fetchAll();

echo json_encode([
    'status' => 'success',
    'data' => $logs,
    'total' => $total,
    'limit' => $limit,
    'offset' => $offset,
]);
