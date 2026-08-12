<?php
/**
 * API: export-report.php
 * Data privacy compliant report export engine
 *
 * Endpoints:
 *   POST   /api/export-report.php           - Export a report
 *   GET    /api/export-report.php?action=history  - Get export history
 *   GET    /api/export-report.php?action=log     - Get export audit log
 *
 * Features:
 *   - PII masking for sensitive fields
 *   - Export history tracking
 *   - Multiple formats (CSV, Excel, PDF)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/AuditLogger.php';
require_once __DIR__ . '/../lib/ExportEngine.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

require_role(['admin', 'staff', 'official']);

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

$engine = new ExportEngine();

if ($method === 'GET' && $action === 'history') {
    $history = $engine->getExportHistory();
    echo json_encode(['success' => true, 'history' => $history]);
    exit;
}

if ($method === 'GET' && $action === 'log') {
    // Get export audit log from audit_logs
    $stmt = $pdo->prepare("
        SELECT * FROM audit_logs
        WHERE action_type = 'EXPORT'
        ORDER BY timestamp DESC
        LIMIT 50
    ");
    $stmt->execute();
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'logs' => $logs]);
    exit;
}

if ($method === 'POST') {
    $reportType = $_POST['report_type'] ?? '';
    $dateFrom = $_POST['date_from'] ?? date('Y-01-01');
    $dateTo = $_POST['date_to'] ?? date('Y-m-t');
    $format = $_POST['format'] ?? 'csv';

    // Validate report type
    $allowedTypes = ['residents', 'documents', 'blotter', 'welfare', 'health', 'households'];
    if (!in_array($reportType, $allowedTypes)) {
        echo json_encode(['success' => false, 'message' => 'Invalid report type']);
        exit;
    }

    // Store filters in session for logging
    $_SESSION['export_filters'] = [
        'report_type' => $reportType,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'format' => $format,
    ];

    try {
        $sql = '';
        $params = [];
        $filename = $reportType . '_export';

        switch ($reportType) {
            case 'residents':
                $sql = "
                    SELECT r.id, r.first_name, r.middle_name, r.last_name, r.suffix,
                           r.birth_date, r.gender, r.civil_status, r.citizenship, r.religion,
                           r.occupation, r.contact_number, r.email,
                           h.household_number, p.purok_name,
                           r.is_pwd, r.is_senior, r.is_indigent, r.fourps_beneficiary
                    FROM residents r
                    LEFT JOIN households h ON r.household_id = h.id
                    LEFT JOIN puroks p ON r.purok_id = p.id
                    WHERE r.created_at BETWEEN ? AND ?
                    ORDER BY r.last_name, r.first_name
                ";
                $params = [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'];
                $columns = ['ID', 'First Name', 'Middle Name', 'Last Name', 'Suffix',
                           'Birth Date', 'Gender', 'Civil Status', 'Citizenship', 'Religion',
                           'Occupation', 'Contact', 'Email', 'Household', 'Purok',
                           'PWD', 'Senior', 'Indigent', '4Ps'];
                break;

            case 'documents':
                $sql = "
                    SELECT d.id, r.first_name, r.last_name, dt.document_name,
                           d.control_number, d.or_number, d.status, d.created_at,
                           d.amount, u.full_name as encoder_name
                    FROM document_requests d
                    LEFT JOIN residents r ON d.resident_id = r.id
                    LEFT JOIN document_types dt ON d.document_type_id = dt.id
                    LEFT JOIN users u ON d.processed_by = u.id
                    WHERE d.requested_at BETWEEN ? AND ?
                    ORDER BY d.requested_at DESC
                ";
                $params = [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'];
                $columns = ['ID', 'Resident', 'Document Type', 'Control No.', 'OR No.',
                           'Status', 'Date', 'Amount', 'Encoder'];
                break;

            case 'blotter':
                $sql = "
                    SELECT bc.id, bc.case_number, bc.case_type,
                           bc.complainant_name, bc.respondent_name,
                           bc.incident_date, bc.filing_date, bc.status, bc.resolution
                    FROM blotter_cases bc
                    WHERE bc.filing_date BETWEEN ? AND ?
                    ORDER BY bc.filing_date DESC
                ";
                $params = [$dateFrom, $dateTo];
                $columns = ['ID', 'Case No.', 'Type', 'Complainant', 'Respondent',
                           'Incident Date', 'Filing Date', 'Status', 'Resolution'];
                break;

            case 'welfare':
                $sql = "
                    SELECT wb.id, r.first_name, r.last_name, wp.program_name,
                           wb.enrollment_date, wb.status
                    FROM welfare_beneficiaries wb
                    LEFT JOIN residents r ON wb.resident_id = r.id
                    LEFT JOIN welfare_programs wp ON wb.program_id = wp.id
                    WHERE wb.enrollment_date BETWEEN ? AND ?
                    ORDER BY wb.enrollment_date DESC
                ";
                $params = [$dateFrom, $dateTo];
                $columns = ['ID', 'Resident', 'Program', 'Enrollment Date', 'Status'];
                break;

            case 'health':
                $sql = "
                    SELECT hr.id, r.first_name, r.last_name,
                           hr.blood_type, hr.height_cm, hr.weight_kg, hr.bmi,
                           hr.vaccination_status, hr.last_checkup
                    FROM health_records hr
                    LEFT JOIN residents r ON hr.resident_id = r.id
                    WHERE hr.created_at BETWEEN ? AND ?
                    ORDER BY r.last_name, r.first_name
                ";
                $params = [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'];
                $columns = ['ID', 'Resident', 'Blood Type', 'Height (cm)', 'Weight (kg)',
                           'BMI', 'Vaccination', 'Last Checkup'];
                break;

            case 'households':
                $sql = "
                    SELECT h.id, h.household_number, p.purok_name,
                           h.head_resident_id, COUNT(r.id) as member_count,
                           h.address
                    FROM households h
                    LEFT JOIN puroks p ON h.purok_id = p.id
                    LEFT JOIN residents r ON h.id = r.household_id
                    WHERE h.created_at BETWEEN ? AND ?
                    GROUP BY h.id
                    ORDER BY h.household_number
                ";
                $params = [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'];
                $columns = ['ID', 'Household No.', 'Purok', 'Head Resident ID',
                           'Member Count', 'Address'];
                break;
        }

        if (!$sql) {
            echo json_encode(['success' => false, 'message' => 'Invalid report type']);
            exit;
        }

        if ($format === 'csv' || $format === 'excel') {
            $filepath = $engine->exportCSV($sql, $params, $filename, $columns);
        } elseif ($format === 'pdf') {
            // For PDF, generate simple HTML
            $html = "<h1>" . ucfirst($reportType) . " Report</h1>";
            $html .= "<p>Date Range: " . $dateFrom . " to " . $dateTo . "</p>";
            $engine->exportPDF($html, $filename);
            $filepath = $filename . '_' . time() . '.html'; // Fallback name
        } else {
            $filepath = $engine->exportCSV($sql, $params, $filename, $columns);
        }

        echo json_encode([
            'success' => true,
            'message' => 'Export generated successfully',
            'file_path' => $filepath,
            'download_url' => 'uploads/exports/' . $filepath,
        ]);
    } catch (Exception $e) {
        error_log('Export error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Export failed: ' . $e->getMessage()]);
    }
}

exit;
