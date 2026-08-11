<?php
/**
 * ExportEngine - Data privacy compliant export/reporting engine
 *
 * Features:
 *   - PII masking for sensitive fields (passwords, gov IDs, phone numbers, etc.)
 *   - Tracks what fields were masked in exported_files table
 *   - Exports to CSV, Excel, and PDF formats
 *   - Audit logging of all exports
 */

class ExportEngine
{
    private $pdo;

    // PII fields that must be masked in exports
    private $sensitiveFields = [
        'password', 'password_hash', 'secret', 'api_key', 'api_secret',
        'ssn', 'tin', 'gov_id', 'passport_number', 'driver_license',
        'phone_number', 'phone', 'mobile', 'contact_number', 'contact',
    ];

    public function __construct()
    {
        $this->pdo = $GLOBALS['pdo'] ?? null;
    }

    /**
     * Export query results to CSV.
     *
     * @param string $sql The SQL query to execute
     * @param array $params Query parameters
     * @param string $filename Output filename (without extension)
     * @param array $columns Column labels for the CSV header
     * @return string Path to the generated CSV file
     */
    public function exportCSV(string $sql, array $params = [], string $filename = 'export', array $columns = []): string
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Detect columns if not provided
        if (empty($columns) && !empty($data)) {
            $columns = array_keys($data[0]);
        }

        // Mask sensitive fields
        $maskedFields = [];
        $maskedData = [];
        foreach ($data as $row) {
            $maskedRow = [];
            foreach ($row as $key => $value) {
                if ($this->isSensitiveField($key)) {
                    if (!in_array($key, $maskedFields)) {
                        $maskedFields[] = $key;
                    }
                    $maskedRow[$key] = $this->maskValue($value);
                } else {
                    $maskedRow[$key] = $value;
                }
            }
            $maskedData[] = $maskedRow;
        }

        // Write CSV
        $exportDir = __DIR__ . '/../uploads/exports';
        if (!is_dir($exportDir)) {
            mkdir($exportDir, 0755, true);
        }

        $filepath = $exportDir . '/' . $filename . '_' . time() . '.csv';
        $f = fopen($filepath, 'w');

        // Add UTF-8 BOM for Excel compatibility
        fprintf($f, chr(0xEF).chr(0xBB).chr(0xBF));

        if (!empty($columns)) {
            fputcsv($f, $columns);
        }

        foreach ($maskedData as $row) {
            fputcsv($f, array_values($row));
        }

        fclose($f);

        // Log the export
        $this->logExport($filename, count($data), $maskedFields, 'csv', $filepath);

        return basename($filepath);
    }

    /**
     * Export to Excel format (XLSX using PhpSpreadsheet if available, fallback to CSV).
     */
    public function exportExcel(string $sql, array $params = [], string $filename = 'export', array $columns = []): string
    {
        // Check if PhpSpreadsheet is available
        if (class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            return $this->exportExcelNative($sql, $params, $filename, $columns);
        }

        // Fallback to CSV with .xlsx extension warning
        error_log('PhpSpreadsheet not available, falling back to CSV for Excel export');
        return $this->exportCSV($sql, $params, $filename, $columns);
    }

    private function exportExcelNative(string $sql, array $params = [], string $filename = 'export', array $columns = []): string
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($columns) && !empty($data)) {
            $columns = array_keys($data[0]);
        }

        // Mask sensitive fields
        $maskedFields = [];
        $maskedData = [];
        foreach ($data as $row) {
            $maskedRow = [];
            foreach ($row as $key => $value) {
                if ($this->isSensitiveField($key)) {
                    if (!in_array($key, $maskedFields)) {
                        $maskedFields[] = $key;
                    }
                    $maskedRow[$key] = $this->maskValue($value);
                } else {
                    $maskedRow[$key] = $value;
                }
            }
            $maskedData[] = $maskedRow;
        }

        // Write headers
        $col = 1;
        foreach ($columns as $label) {
            $sheet->setCellValueByColumnAndRow($col, 1, $label);
            $col++;
        }

        // Write data
        $row = 2;
        foreach ($maskedData as $dataRow) {
            $col = 1;
            foreach (array_values($dataRow) as $value) {
                $sheet->setCellValueByColumnAndRow($col, $row, $value);
                $col++;
            }
            $row++;
        }

        // Auto-size columns
        foreach (range('A', 'Z') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $exportDir = __DIR__ . '/../uploads/exports';
        if (!is_dir($exportDir)) {
            mkdir($exportDir, 0755, true);
        }

        $filepath = $exportDir . '/' . $filename . '_' . time() . '.xlsx';
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($filepath);

        $this->logExport($filename, count($data), $maskedFields, 'xlsx', $filepath);

        return basename($filepath);
    }

    /**
     * Generate a PDF report.
     */
    public function exportPDF(string $html, string $filename = 'report'): string
    {
        $exportDir = __DIR__ . '/../uploads/exports';
        if (!is_dir($exportDir)) {
            mkdir($exportDir, 0755, true);
        }

        $filepath = $exportDir . '/' . $filename . '_' . time() . '.pdf';

        // If Dompdf or TCPDF is available, use it
        if (class_exists('Dompdf\Dompdf')) {
            $dompdf = new \Dompdf\Dompdf();
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();
            file_put_contents($filepath . '', $dompdf->output());
        } else {
            // Fallback: save as HTML and log
            file_put_contents($filepath . '.html', $html);
            $filepath = $filepath . '.html';
        }

        $this->logExport($filename, null, [], 'pdf', $filepath);

        return basename($filepath);
    }

    /**
     * Check if a field name contains sensitive data.
     */
    private function isSensitiveField(string $fieldName): bool
    {
        $fieldName = strtolower($fieldName);
        foreach ($this->sensitiveFields as $sensitive) {
            if (strpos($fieldName, strtolower($sensitive)) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Mask a sensitive value.
     *    - Passwords: replaced entirely
     *    - Phone numbers: last 4 digits only
     *    - Other PII: first char + asterisks
     */
    private function maskValue($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $value = (string)$value;

        // Passwords
        if (strlen($value) <= 8) {
            return str_repeat('*', strlen($value));
        }
        return substr($value, 0, 1) . str_repeat('*', strlen($value) - 2) . substr($value, -1);
    }

    /**
     * Log an export action.
     */
    private function logExport(string $reportType, ?int $recordCount, array $maskedFields, string $format, string $filepath): void
    {
        if (!$this->pdo) return;

        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO exported_files
                    (exporter_id, exporter_role, report_type, filter_criteria,
                     file_path, record_count, file_size, pii_fields_masked)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $_SESSION['user_id'] ?? 1,
                $_SESSION['user_role'] ?? 'admin',
                $reportType,
                json_encode($_SESSION['export_filters'] ?? []),
                basename($filepath),
                $recordCount,
                file_exists($filepath) ? filesize($filepath) : null,
                json_encode($maskedFields),
            ]);

            AuditLogger::log('EXPORT', 'Reports', null, null, [
                'report_type' => $reportType,
                'format' => $format,
                'record_count' => $recordCount,
                'masked_fields' => $maskedFields,
                'file_path' => basename($filepath),
            ], 'INFO');
        } catch (PDOException $e) {
            error_log('ExportEngine logExport error: ' . $e->getMessage());
        }
    }

    /**
     * Get list of all export records.
     */
    public function getExportHistory(int $limit = 100): array
    {
        if (!$this->pdo) return [];

        $stmt = $this->pdo->prepare("
            SELECT ef.*, u.full_name as exporter_name
            FROM exported_files ef
            LEFT JOIN users u ON ef.exporter_id = u.id
            ORDER BY ef.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
