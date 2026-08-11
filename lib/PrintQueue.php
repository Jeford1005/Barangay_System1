<?php
/**
 * PrintQueue - Async print queue management system
 *
 * Features:
 *   - Single-item instant print vs batch queueing
 *   - Priority-based sorting
 *   - Retry logic for failed prints
 *   - Pause/resume queue
 *   - Damaged re-issue tracking
 *   - SMS pickup alerts to residents
 */

class PrintQueue
{
    private static $instance = null;
    private $pdo;
    private $isPaused = false;

    private function __construct()
    {
        $this->pdo = $GLOBALS['pdo'] ?? null;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Add a document to the print queue
     *
     * @param int $documentId The document to print
     * @param int $priority 1=low, 5=critical
     * @param bool $instantPrint If true, process immediately
     */
    public function enqueue(int $documentId, int $priority = 1, bool $instantPrint = false): bool
    {
        if (!$this->pdo) {
            error_log('PrintQueue: No PDO connection');
            return false;
        }

        if ($instantPrint) {
            return $this->processDocument($documentId);
        }

        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO print_queue (document_id, priority, status)
                VALUES (?, ?, 'PENDING')
            ");
            $stmt->execute([$documentId, $priority]);
            
            AuditLogger::log('CREATE', 'PrintQueue', $documentId, null, [
                'action' => 'queued',
                'priority' => $priority,
            ]);
            
            return true;
        } catch (PDOException $e) {
            error_log('PrintQueue enqueue error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Process a single document for printing
     */
    public function processDocument(int $documentId): bool
    {
        if (!$this->pdo) return false;

        try {
            // Update document status
            $stmt = $this->pdo->prepare("UPDATE documents SET status = 'QUEUED_FOR_PRINT' WHERE id = ?");
            $stmt->execute([$documentId]);

            // Process the print (in real implementation, this would call a printer driver)
            $printResult = $this->executePrint($documentId);

            if ($printResult['success']) {
                $stmt = $this->pdo->prepare("
                    UPDATE documents 
                    SET status = 'PRINTED_AND_ISSUED', printed_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$documentId]);

                // Get resident info for SMS notification
                $stmt = $this->pdo->prepare("
                    SELECT r.phone_number, r.full_name, d.control_number, d.document_type
                    FROM documents d
                    JOIN residents r ON d.resident_id = r.id
                    WHERE d.id = ?
                ");
                $stmt->execute([$documentId]);
                $doc = $stmt->fetch();

                if ($doc && $doc['phone_number']) {
                    // Send SMS pickup alert
                    $gateway = SMSGateway::getInstance();
                    $message = "Your Barangay document ({$doc['document_type']}) with Control No. {$doc['control_number']} is ready for pickup at the Barangay Hall. Thank you.";
                    $gateway->send($doc['phone_number'], $message);

                    AuditLogger::log('AUTH', 'Documents', $documentId, null, [
                        'event' => 'sms_pickup_alert',
                        'recipient' => $doc['phone_number'],
                        'message' => $message,
                    ]);
                }

                // Update print queue
                $stmt = $this->pdo->prepare("
                    UPDATE print_queue 
                    SET status = 'COMPLETED', completed_at = NOW() 
                    WHERE document_id = ?
                ");
                $stmt->execute([$documentId]);

                return true;
            } else {
                // Handle print failure
                $stmt = $this->pdo->prepare("
                    UPDATE print_queue 
                    SET status = 'FAILED', error_message = ?, attempts = attempts + 1
                    WHERE document_id = ?
                ");
                $stmt->execute([$printResult['error'], $documentId]);

                return false;
            }
        } catch (PDOException $e) {
            error_log('PrintQueue processDocument error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Execute the actual print operation
     * In production, this would connect to a printer driver
     */
    private function executePrint(int $documentId): array
    {
        try {
            // Simulate print operation
            // In production: call printer driver, generate PDF, send to printer
            $pdfPath = $this->generatePDF($documentId);
            
            // Log the print action
            AuditLogger::log('READ', 'Documents', $documentId, null, [
                'event' => 'print_executed',
                'pdf_path' => $pdfPath,
            ]);

            return ['success' => true, 'pdf_path' => $pdfPath];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Generate PDF for document
     */
    private function generatePDF(int $documentId): string
    {
        // In production, use a PDF library like TCPDF or Dompdf
        // For now, simulate
        $pdfDir = __DIR__ . '/../uploads/documents/pdf';
        if (!is_dir($pdfDir)) {
            mkdir($pdfDir, 0755, true);
        }
        
        $filename = 'document_' . $documentId . '_' . time() . '.pdf';
        $filepath = $pdfDir . '/' . $filename;
        
        // Create a simple placeholder PDF
        file_put_contents($filepath, '%PDF-1.4
1 0 obj<</Pages 2 0 R>>endobj
2 0 obj<</Kids[3 0 R]/Count 1>>endobj
3 0 obj<</Parent 2 0 R/MediaBox[0 0 612 792]>>endobj
xref
0 4
0000000000 65535 f 
0000000009 00000 n 
0000000052 00000 n 
0000000101 00000 n 
trailer<</Size 4/Root 1 0 R>>
startxref
164
%%EOF');
        
        return basename($filepath);
    }

    /**
     * Get all pending jobs from the queue
     */
    public function getQueue(int $limit = 50, int $offset = 0): array
    {
        if (!$this->pdo) return [];

        $stmt = $this->pdo->prepare("
            SELECT pq.*, d.control_number, d.document_type, d.status as doc_status,
                   u.full_name as resident_name, u.phone_number
            FROM print_queue pq
            JOIN documents d ON pq.document_id = d.id
            JOIN residents u ON d.resident_id = u.id
            WHERE pq.status IN ('PENDING', 'FAILED')
              AND pq.attempts < pq.max_attempts
            ORDER BY pq.priority DESC, pq.queued_at ASC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Pause the print queue
     */
    public function pause(): bool
    {
        $this->isPaused = true;
        AuditLogger::log('AUTH', 'PrintQueue', null, null, ['event' => 'paused']);
        return true;
    }

    /**
     * Resume the print queue
     */
    public function resume(): bool
    {
        $this->isPaused = false;
        AuditLogger::log('AUTH', 'PrintQueue', null, null, ['event' => 'resumed']);
        return true;
    }

    /**
     * Reorder queue items (move up/down)
     */
    public function reorder(int $queueId, string $direction): bool
    {
        if (!$this->pdo) return false;

        try {
            if ($direction === 'up') {
                // Swap with higher priority item
                $stmt = $this->pdo->prepare("
                    UPDATE print_queue pq1
                    JOIN print_queue pq2 ON pq2.priority > pq1.priority AND pq2.status IN ('PENDING','FAILED')
                    SET pq1.priority = (@temp := pq1.priority),
                        pq1.priority = pq2.priority,
                        pq2.priority = @temp
                    WHERE pq1.id = ?
                    ORDER BY pq2.priority ASC, pq2.queued_at ASC
                    LIMIT 1
                ");
            } else {
                // Swap with lower priority item
                $stmt = $this->pdo->prepare("
                    UPDATE print_queue pq1
                    JOIN print_queue pq2 ON pq2.priority < pq1.priority AND pq2.status IN ('PENDING','FAILED')
                    SET pq1.priority = (@temp := pq1.priority),
                        pq1.priority = pq2.priority,
                        pq2.priority = @temp
                    WHERE pq1.id = ?
                    ORDER BY pq2.priority DESC, pq2.queued_at DESC
                    LIMIT 1
                ");
            }
            $stmt->execute([$queueId]);
            return true;
        } catch (PDOException $e) {
            error_log('PrintQueue reorder error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Retry a failed print job
     */
    public function retry(int $queueId): bool
    {
        if (!$this->pdo) return false;

        $stmt = $this->pdo->prepare("
            UPDATE print_queue 
            SET status = 'PENDING', error_message = NULL 
            WHERE id = ? AND status = 'FAILED'
        ");
        $stmt->execute([$queueId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Record a damaged document re-issue
     */
    public function reissue(int $documentId, string $reason): bool
    {
        if (!$this->pdo) return false;

        try {
            $this->pdo->beginTransaction();

            // Reset document status
            $stmt = $this->pdo->prepare("
                UPDATE documents 
                SET status = 'PENDING_REVIEW', printed_at = NULL 
                WHERE id = ?
            ");
            $stmt->execute([$documentId]);

            // Add new queue entry
            $stmt = $this->pdo->prepare("
                INSERT INTO print_queue (document_id, priority, status)
                VALUES (?, 5, 'PENDING')
            ");
            $stmt->execute([$documentId, 5]);

            AuditLogger::log('CREATE', 'PrintQueue', $documentId, null, [
                'event' => 'reissue',
                'reason' => $reason,
            ], 'WARN');

            $this->pdo->commit();
            return true;
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            error_log('PrintQueue reissue error: ' . $e->getMessage());
            return false;
        }
    }

    public function isPaused(): bool
    {
        return $this->isPaused;
    }
}
