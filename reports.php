<?php
require_once __DIR__ . '/config.php';
require_role(['admin', 'staff', 'official']);

$message = '';
$currentUser = current_user();

// Handle report generation requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_report'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $message = '<div class="toast-alert toast-danger" id="floatingAlert"><i class="fas fa-exclamation"></i> Invalid security token.</div>';
    } else {
        $reportType = $_POST['report_type'] ?? '';
        $dateFrom = $_POST['date_from'] ?? '';
        $dateTo = $_POST['date_to'] ?? '';

        // Store report parameters in session for the report page
        $_SESSION['report_params'] = [
            'type' => $reportType,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'generated_at' => date('Y-m-d H:i:s')
        ];
        header('Location: ' . BASE_URL . '/reports.php?view=' . $reportType);
        exit;
    }
}

$view = $_GET['view'] ?? '';
$params = $_SESSION['report_params'] ?? null;

$reportData = [];
$reportTitle = '';

if ($view && $params) {
    $dateFrom = $params['date_from'] ?: date('Y-01-01');
    $dateTo = $params['date_to'] ?: date('Y-m-t');

    if ($view === 'residents') {
        $reportTitle = 'Resident List Report';
        $stmt = $pdo->prepare("
            SELECT r.*, h.household_number, p.purok_name
            FROM residents r
            LEFT JOIN households h ON r.household_id = h.id
            LEFT JOIN puroks p ON r.purok_id = p.id
            WHERE r.created_at BETWEEN ? AND ?
            ORDER BY r.last_name, r.first_name
        ");
        $stmt->execute([$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
        $reportData = $stmt->fetchAll();
    } elseif ($view === 'documents') {
        $reportTitle = 'Document Requests Report';
        $stmt = $pdo->prepare("
            SELECT dr.*, r.first_name, r.last_name, dt.document_name, dt.fee
            FROM document_requests dr
            LEFT JOIN residents r ON dr.resident_id = r.id
            LEFT JOIN document_types dt ON dr.document_type_id = dt.id
            WHERE dr.requested_at BETWEEN ? AND ?
            ORDER BY dr.requested_at DESC
        ");
        $stmt->execute([$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
        $reportData = $stmt->fetchAll();
    } elseif ($view === 'blotter') {
        $reportTitle = 'Blotter Cases Report';
        $stmt = $pdo->prepare("
            SELECT bc.*, r.first_name as complainant_first, r.last_name as complainant_last
            FROM blotter_cases bc
            LEFT JOIN residents r ON bc.complainant_id = r.id
            WHERE bc.filing_date BETWEEN ? AND ?
            ORDER BY bc.filing_date DESC
        ");
        $stmt->execute([$dateFrom, $dateTo]);
        $reportData = $stmt->fetchAll();
    } elseif ($view === 'welfare') {
        $reportTitle = 'Welfare Beneficiaries Report';
        $stmt = $pdo->prepare("
            SELECT wb.*, wp.program_name, r.first_name, r.last_name
            FROM welfare_beneficiaries wb
            LEFT JOIN welfare_programs wp ON wb.program_id = wp.id
            LEFT JOIN residents r ON wb.resident_id = r.id
            WHERE wb.enrollment_date BETWEEN ? AND ?
            ORDER BY wb.enrollment_date DESC
        ");
        $stmt->execute([$dateFrom, $dateTo]);
        $reportData = $stmt->fetchAll();
    } elseif ($view === 'health') {
        $reportTitle = 'Health Records Summary';
        $stmt = $pdo->prepare("
            SELECT hr.*, r.first_name, r.last_name
            FROM health_records hr
            LEFT JOIN residents r ON hr.resident_id = r.id
            WHERE hr.created_at BETWEEN ? AND ?
            ORDER BY hr.last_checkup DESC
        ");
        $stmt->execute([$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
        $reportData = $stmt->fetchAll();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="assets/img/Brgy_Bidduang.png">
    <link rel="shortcut icon" type="image/png" href="assets/img/Brgy_Bidduang.png">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - Barangay Bidduang Portal</title>
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= ASSET_VERSION ?>">
    <link rel="stylesheet" href="assets/css/fontawesome.min.css">
</head>
<body>
<div class="app">
    <?php include __DIR__ . '/views/sidebar.php'; ?>

    <main class="main-content">
        <div class="page-header">
            <div>
                <h1><i class="fas fa-chart-bar"></i> Reports Hub</h1>
                <p>Generate and view system reports</p>
            </div>
        </div>

        <?= $message ?>

        <?php if ($view && $reportData !== null): ?>
        <div class="card no-print">
            <div class="card-header">
                <h2><?= esc($reportTitle) ?></h2>
                <div class="toolbar">
                    <span class="text-muted">Generated: <?= date('M d, Y h:i A') ?></span>
                    <button class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Print Report</button>
                    <button class="btn btn-outline" onclick="window.location.href='reports.php'">Back to Reports</button>
                </div>
            </div>
            <div class="table-responsive">
                <table>
                    <thead>
                        <?php if ($view === 'residents'): ?>
                            <tr><th>Name</th><th>Gender</th><th>Birth Date</th><th>Purok</th><th>Household</th><th>Contact</th><th>Status</th></tr>
                        <?php elseif ($view === 'documents'): ?>
                            <tr><th>Request #</th><th>Resident</th><th>Document</th><th>Fee</th><th>Status</th><th>Date</th></tr>
                        <?php elseif ($view === 'blotter'): ?>
                            <tr><th>Case #</th><th>Type</th><th>Complainant</th><th>Incident Date</th><th>Location</th><th>Status</th></tr>
                        <?php elseif ($view === 'welfare'): ?>
                            <tr><th>Resident</th><th>Program</th><th>Enrollment Date</th><th>Status</th></tr>
                        <?php elseif ($view === 'health'): ?>
                            <tr><th>Resident</th><th>Blood Type</th><th>Height</th><th>Weight</th><th>BMI</th><th>Vaccination</th></tr>
                        <?php endif; ?>
                    </thead>
                    <tbody>
                        <?php if (empty($reportData)): ?>
                            <tr><td colspan="7"><div class="empty-state"><i class="fas fa-chart-bar"></i><h3>No data found for the selected period</h3></div></td></tr>
                        <?php else: ?>
                            <?php foreach ($reportData as $row): ?>
                            <tr>
                                <?php if ($view === 'residents'): ?>
                                    <td><?= esc($row['first_name'] . ' ' . $row['last_name']) ?></td>
                                    <td><?= esc($row['gender']) ?></td>
                                    <td><?= date('M d, Y', strtotime($row['birth_date'])) ?></td>
                                    <td><?= esc($row['purok_name'] ?? '-') ?></td>
                                    <td><?= esc($row['household_number'] ?? '-') ?></td>
                                    <td><?= esc($row['contact_number'] ?: '-') ?></td>
                                    <td><?= esc($row['status']) ?></td>
                                <?php elseif ($view === 'documents'): ?>
                                    <td>DR-<?= str_pad($row['id'], 6, '0', STR_PAD_LEFT) ?></td>
                                    <td><?= esc($row['first_name'] . ' ' . $row['last_name']) ?></td>
                                    <td><?= esc($row['document_name']) ?></td>
                                    <td><?= $row['fee'] ? '₱'.number_format($row['fee'],2) : 'Free' ?></td>
                                    <td><?= esc($row['status']) ?></td>
                                    <td><?= date('M d, Y', strtotime($row['requested_at'])) ?></td>
                                <?php elseif ($view === 'blotter'): ?>
                                    <td><?= esc($row['case_number']) ?></td>
                                    <td><?= esc($row['case_type']) ?></td>
                                    <td><?= esc(($row['complainant_first'] ?? '').' '.($row['complainant_last'] ?? '')) ?></td>
                                    <td><?= date('M d, Y', strtotime($row['incident_date'])) ?></td>
                                    <td><?= esc($row['incident_location']) ?></td>
                                    <td><?= esc($row['status']) ?></td>
                                <?php elseif ($view === 'welfare'): ?>
                                    <td><?= esc($row['first_name'] . ' ' . $row['last_name']) ?></td>
                                    <td><?= esc($row['program_name']) ?></td>
                                    <td><?= date('M d, Y', strtotime($row['enrollment_date'])) ?></td>
                                    <td><?= esc($row['status']) ?></td>
                                <?php elseif ($view === 'health'): ?>
                                    <td><?= esc($row['first_name'] . ' ' . $row['last_name']) ?></td>
                                    <td><?= esc($row['blood_type']) ?></td>
                                    <td><?= $row['height_cm'] ? $row['height_cm'].' cm' : '-' ?></td>
                                    <td><?= $row['weight_kg'] ? $row['weight_kg'].' kg' : '-' ?></td>
                                    <td><?= $row['bmi'] ?: '-' ?></td>
                                    <td><?= esc($row['vaccination_status']) ?></td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div style="padding:15px;text-align:center;color:var(--text-muted);font-size:14px;">
                Total Records: <?= number_format(count($reportData)) ?> | Generated on <?= date('F d, Y h:i A') ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!$view): ?>
        <div class="card">
            <div class="card-header">
                <h2>Generate Report</h2>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                    <div class="form-group">
                        <label>Report Type *</label>
                        <select name="report_type" class="form-control" required>
                            <option value="">Select Report Type</option>
                            <option value="residents">Resident List</option>
                            <option value="documents">Document Requests</option>
                            <option value="blotter">Blotter Cases</option>
                            <option value="welfare">Welfare Beneficiaries</option>
                            <option value="health">Health Records Summary</option>
                        </select>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                        <div class="form-group"><label>Date From</label><input type="date" name="date_from" class="form-control" value="<?= date('Y-01-01') ?>"></div>
                        <div class="form-group"><label>Date To</label><input type="date" name="date_to" class="form-control" value="<?= date('Y-m-t') ?>"></div>
                    </div>
                </div>
                <div class="modal-footer" style="position:static;border:none;padding:0 25px 20px;">
                    <button type="submit" name="generate_report" class="btn btn-primary"><i class="fas fa-chart-bar"></i> Generate Report</button>
                </div>
            </form>
        </div>

        <div class="card">
            <div class="card-header"><h2>Quick Reports</h2></div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:15px;">
                <a href="reports.php?view=residents" class="btn btn-outline" style="justify-content:center;padding:20px;"><i class="fas fa-users"></i> Resident List</a>
                <a href="reports.php?view=documents" class="btn btn-outline" style="justify-content:center;padding:20px;"><i class="fas fa-file-text"></i> Document Requests</a>
                <a href="reports.php?view=blotter" class="btn btn-outline" style="justify-content:center;padding:20px;"><i class="fas fa-scale-balanced"></i> Blotter Cases</a>
                <a href="reports.php?view=welfare" class="btn btn-outline" style="justify-content:center;padding:20px;"><i class="fas fa-hand-holding-heart"></i> Welfare Beneficiaries</a>
                <a href="reports.php?view=health" class="btn btn-outline" style="justify-content:center;padding:20px;"><i class="fas fa-heartbeat"></i> Health Records</a>
            </div>
        </div>

        <!-- Export Section -->
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-file-export"></i> Export Reports</h2>
            </div>
            <form id="exportForm">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                    <div class="form-group">
                        <label>Report Type *</label>
                        <select name="report_type" class="form-control" required>
                            <option value="">Select Report for Export</option>
                            <option value="residents">Resident List</option>
                            <option value="documents">Document Requests</option>
                            <option value="blotter">Blotter Cases</option>
                            <option value="welfare">Welfare Beneficiaries</option>
                            <option value="health">Health Records</option>
                            <option value="households">Households</option>
                        </select>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                        <div class="form-group"><label>Date From</label><input type="date" name="date_from" class="form-control" value="<?= date('Y-01-01') ?>"></div>
                        <div class="form-group"><label>Date To</label><input type="date" name="date_to" class="form-control" value="<?= date('Y-m-t') ?>"></div>
                    </div>
                    <div class="form-group">
                        <label>Format *</label>
                        <select name="format" class="form-control" required>
                            <option value="csv">CSV (Microsoft Excel compatible)</option>
                            <option value="excel">Excel (XLSX)</option>
                            <option value="pdf">PDF</option>
                        </select>
                    </div>
                    <div style="background: #f8f9fa; border: 1px solid #e0e0e0; border-radius: 6px; padding: 10px; margin-top: 10px; font-size: 12px; color: #6c757d;">
                        <i class="fas fa-info"></i>
                        Sensitive fields (phone numbers, passwords, etc.) are automatically masked in exports per data privacy policy.
                    </div>
                </div>
                <div class="modal-footer" style="position:static;border:none;padding:0 25px 20px;">
                    <button type="button" class="btn btn-primary" onclick="exportReport()"><i class="fas fa-file-export"></i> Export Report</button>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </main>
</div>
<script>
function exportReport() {
    const form = document.getElementById('exportForm');
    const formData = new FormData(form);
    const reportType = formData.get('report_type');
    const format = formData.get('format');

    if (!reportType || !format) {
        if (typeof Swal !== 'undefined') {
            Swal.fire('Error', 'Please select a report type and format.', 'error');
        } else {
            alert('Please select a report type and format.');
        }
        return;
    }

    const data = new URLSearchParams();
    data.append('report_type', reportType);
    data.append('date_from', formData.get('date_from'));
    data.append('date_to', formData.get('date_to'));
    data.append('format', format);

    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Exporting...',
            html: 'Please wait while the report is being generated.',
            allowOutsideClick: false,
            showConfirmButton: false,
            didOpen: () => { Swal.showLoading(); }
        });
    }

    fetch('api/export-report.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: data
    })
    .then(r => r.json())
    .then(result => {
        if (result.success) {
            const msg = result.message || 'Export completed';
            if (typeof Swal !== 'undefined') {
                Swal.fire('Success', msg, 'success').then(() => {
                    window.open('uploads/exports/' + result.file_path, '_blank');
                });
            } else {
                alert(msg);
                window.open('uploads/exports/' + result.file_path, '_blank');
            }
        } else {
            if (typeof Swal !== 'undefined') {
                Swal.fire('Error', result.message, 'error');
            } else {
                alert('Error: ' + result.message);
            }
        }
    })
    .catch(err => {
        if (typeof Swal !== 'undefined') {
            Swal.fire('Error', 'Network error: ' + err.message, 'error');
        } else {
            alert('Network error: ' + err.message);
        }
    });
}
</script>
<script src="assets/js/main.js"></script>
</body>
</html>
