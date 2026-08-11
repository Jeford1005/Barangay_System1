<?php
/**
 * DigitalSignature - Digital signature and approval workflow
 *
 * Lifecycle State Machine:
 *   DRAFT -> PENDING_REVIEW -> APPROVED (or REJECTED) -> QUEUED_FOR_PRINT -> PRINTED_AND_ISSUED
 *
 * Security:
 *   - SHA-256 document hashing for tamper detection
 *   - Signature image overlay on documents
 *   - Approval trail stored in document_approvals table
 */

class DigitalSignature
{
    private static $instance = null;
    private $pdo;

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
     * Generate a SHA-256 hash of document content for tamper detection.
     */
    public function generateDocumentHash(string $documentId, array $data): string
    {
        $content = $documentId . '|' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return hash('sha256', $content);
    }

    /**
     * Verify document integrity against stored hash.
     */
    public function verifyDocumentHash(int $documentId, array $data, string $storedHash): bool
    {
        $currentHash = $this->generateDocumentHash((string)$documentId, $data);
        return hash_equals($storedHash, $currentHash);
    }

    /**
     * Store an approval action.
     */
    public function recordApproval(int $documentId, int $approverId, string $role, string $action, ?string $notes = null, ?string $signatureData = null): bool
    {
        if (!$this->pdo) return false;

        try {
            $this->pdo->beginTransaction();

            // Generate document hash at approval time
            $stmt = $this->pdo->prepare("SELECT * FROM documents WHERE id = ?");
            $stmt->execute([$documentId]);
            $doc = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$doc) {
                $this->pdo->rollBack();
                return false;
            }

            $docHash = $this->generateDocumentHash($documentId, $doc);

            // Record the approval
            $stmt = $this->pdo->prepare("
                INSERT INTO document_approvals
                    (document_id, approver_id, approver_role, action, notes, document_hash, signature_data)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $documentId, $approverId, $role, $action, $notes, $docHash, $signatureData
            ]);

            // Update document status
            $newStatus = ($action === 'approve') ? 'APPROVED' : (($action === 'reject') ? 'REJECTED' : 'PENDING_REVIEW');
            
            if ($action === 'approve') {
                $stmt = $this->pdo->prepare("
                    UPDATE documents 
                    SET status = ?, approved_by = ?, approved_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$newStatus, $approverId, $documentId]);
            } else {
                $stmt = $this->pdo->prepare("UPDATE documents SET status = ? WHERE id = ?");
                $stmt->execute([$newStatus, $documentId]);
            }

            // Log the action
            AuditLogger::log(
                strtoupper($action),
                'Documents',
                $documentId,
                null,
                ['approver' => $approverId, 'role' => $role, 'action' => $action, 'notes' => $notes],
                $action === 'approve' ? 'INFO' : 'WARN'
            );

            $this->pdo->commit();
            return true;
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            error_log('DigitalSignature approval error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get approval history for a document.
     */
    public function getApprovalHistory(int $documentId): array
    {
        if (!$this->pdo) return [];

        $stmt = $this->pdo->prepare("
            SELECT da.*, u.full_name as approver_name, u.role as approver_role
            FROM document_approvals da
            LEFT JOIN users u ON da.approver_id = u.id
            WHERE da.document_id = ?
            ORDER BY da.created_at DESC
        ");
        $stmt->execute([$documentId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Store or update a user's digital signature.
     */
    public function saveSignature(int $userId, string $signatureData): bool
    {
        if (!$this->pdo) return false;

        try {
            // Deactivate old signatures
            $stmt = $this->pdo->prepare("UPDATE digital_signatures SET is_active = 0 WHERE user_id = ?");
            $stmt->execute([$userId]);

            // Insert new signature
            $stmt = $this->pdo->prepare("
                INSERT INTO digital_signatures (user_id, signature_data, document_hash, secret_key)
                VALUES (?, ?, ?, ?)
            ");
            $secretKey = bin2hex(random_bytes(32));
            $docHash = hash('sha256', $signatureData . $secretKey);
            $stmt->execute([$userId, $signatureData, $docHash, $secretKey]);

            return true;
        } catch (PDOException $e) {
            error_log('DigitalSignature save error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get user's active signature.
     */
    public function getUserSignature(int $userId): ?array
    {
        if (!$this->pdo) return null;

        $stmt = $this->pdo->prepare("
            SELECT * FROM digital_signatures 
            WHERE user_id = ? AND is_active = 1 
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Get current workflow state for a document.
     */
    public function getWorkflowState(int $documentId): string
    {
        if (!$this->pdo) return 'DRAFT';

        $stmt = $this->pdo->prepare("SELECT status FROM documents WHERE id = ?");
        $stmt->execute([$documentId]);
        $doc = $stmt->fetch();

        return $doc ? $doc['status'] : 'DRAFT';
    }

    /**
     * Check if user can perform the given action on a document.
     */
    public function canPerformAction(int $userId, string $userRole, string $action, int $documentId): bool
    {
        $state = $this->getWorkflowState($documentId);

        // Encoder can draft/review
        if ($userRole === 'staff' || $userRole === 'admin') {
            if (in_array($action, ['draft', 'review'])) {
                return true;
            }
        }

        // Secretary can review
        if ($userRole === 'staff' || $userRole === 'admin') {
            if ($action === 'review' && in_array($state, ['DRAFT', 'PENDING_REVIEW'])) {
                return true;
            }
        }

        // Punong Barangay can approve/reject
        if ($userRole === 'admin') {
            if (in_array($action, ['approve', 'reject']) && $state === 'PENDING_REVIEW') {
                return true;
            }
            if ($action === 'print' && $state === 'APPROVED') {
                return true;
            }
        }

        return false;
    }

    /**
     * Get state machine diagram text.
     */
    public function getStateDiagram(): string
    {
        return <<<DIAGRAM
Lifecycle State Machine:

    ┌────────┐
    │  DRAFT │
    └───┬────┘
        │ [Submit for Review]
        ▼
    ┌────────────────┐
    │ PENDING_REVIEW │
    └────┬───────────┘
         │ [Approve]    │ [Reject]
         ▼              ▼
    ┌─────────┐  ┌──────────┐
    │ APPROVED │  │ REJECTED │
    └────┬─────┘  └──────────┘
         │ [Print Queue]
         ▼
    ┌────────────────┐
    │ QUEUED_FOR_PRINT │
    └────┬───────────┘
         │ [Print Complete]
         ▼
    ┌────────────────────┐
    │ PRINTED_AND_ISSUED │
    └────────────────────┘

Roles & Permissions:
  - Encoder (staff): Create DRAFT, review PENDING_REVIEW
  - Secretary (staff): Review documents
  - Punong Barangay (admin): Approve/Reject, trigger print
DIAGRAM;
    }
}
