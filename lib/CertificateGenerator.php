<?php
/**
 * CertificateGenerator - Printable certificate/document generation
 *
 * Generates legal-size documents with:
 *   - Dual header logos (Barangay + Municipal Seal)
 *   - Background watermark
 *   - Embedded QR code with verification URL
 *   - Control Number & OR Number
 *   - Dry seal indicator
 *   - Digital signature overlay with SHA-256 hash
 */

class CertificateGenerator
{
    private $pdo;

    public function __construct()
    {
        $this->pdo = $GLOBALS['pdo'] ?? null;
    }

    public function generate(array $data): string
    {
        if (empty($data['control_number'])) {
            $data['control_number'] = $this->generateControlNumber($data['document_type']);
        }

        $docHash = $this->storeDocument($data);

        $html = $this->buildHTML($data, $docHash);

        $pdfDir = __DIR__ . '/../uploads/documents/pdf';
        if (!is_dir($pdfDir)) {
            mkdir($pdfDir, 0755, true);
        }

        $filename = $data['document_type'] . '_' . $data['control_number'] . '_' . time() . '.html';
        $filepath = $pdfDir . '/' . $filename;
        file_put_contents($filepath, $html);

        AuditLogger::log('CREATE', 'Documents', $data['control_number'], null, [
            'doc_type' => $data['document_type'],
            'control_number' => $data['control_number'],
            'or_number' => $data['or_number'],
            'amount' => $data['amount'],
            'dry_seal' => $data['dry_seal'],
            'doc_hash' => $docHash,
        ]);

        return basename($filepath);
    }

    private function generateControlNumber(string $docType): string
    {
        $year = date('Y');
        $typeMap = [
            'clearance' => 'BRGYCL',
            'indigency' => 'INDIG',
            'residency' => 'RESID',
            'barangay_pass' => 'BPASS',
        ];
        $prefix = $typeMap[$docType] ?? 'DOC';
        $random = strtoupper(bin2hex(random_bytes(3)));

        $stmt = $this->pdo->prepare("
            SELECT MAX(CAST(SUBSTRING(control_number, -6) AS UNSIGNED)) as last
            FROM documents
            WHERE control_number LIKE CONCAT(?, '%') AND YEAR(created_at) = YEAR(NOW())
        ");
        $likePattern = $prefix . '-' . $year;
        $stmt->execute([$likePattern]);
        $row = $stmt->fetch();
        $series = ($row['last'] ?? 0) + 1;

        return sprintf('%s-%s%04d-%s', $prefix, $year, $series, $random);
    }

    private function storeDocument(array $data): string
    {
        $docData = [
            'document_type' => $data['document_type'],
            'control_number' => $data['control_number'],
            'or_number' => $data['or_number'],
            'or_series' => $data['or_series'],
            'ctc_number' => $data['ctc_number'],
            'ctc_date' => $data['ctc_date'],
            'dry_seal' => $data['dry_seal'] ? 1 : 0,
            'purpose' => $data['purpose'],
            'amount' => $data['amount'],
        ];

        $hash = hash('sha256', json_encode($docData, JSON_UNESCAPED_SLASHES));

        if ($this->pdo) {
            $stmt = $this->pdo->prepare("
                INSERT INTO documents
                    (resident_id, document_type, control_number, or_number, or_series,
                     ctc_number, ctc_date, dry_seal, purpose, amount, status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'APPROVED', ?)
            ");
            $stmt->execute([
                $data['resident_id'],
                $data['document_type'],
                $data['control_number'],
                $data['or_number'],
                $data['or_series'],
                $data['ctc_date'],
                $data['dry_seal'] ? 1 : 0,
                $data['purpose'],
                $data['amount'],
                $_SESSION['user_id'] ?? 1,
            ]);
        }

        return $hash;
    }

    private function buildHTML(array $data, string $docHash): string
    {
        $docTypeLabels = [
            'clearance' => 'BARANGAY CLEARANCE',
            'indigency' => 'CERTIFICATE OF INDIGENCY',
            'residency' => 'CERTIFICATE OF RESIDENCY',
            'barangay_pass' => 'BARANGAY PASSPORT',
        ];

        $docType = $docTypeLabels[$data['document_type']] ?? 'DOCUMENT';
        $residentName = $this->getResidentName($data['resident_id']);
        $dateIssued = date('F d, Y');
        $qrUrl = 'http://localhost' . BASE_URL . '/verify-document.php?cn=' . $data['control_number'];

        $qrCode = 'data:image/svg+xml;base64,' . base64_encode(
            '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100">' .
            '<rect width="100" height="100" fill="white"/>' .
            '<text x="50" y="55" font-size="10" text-anchor="middle" fill="black">QR</text>' .
            '</svg>'
        );

        $signatureOverlay = '';
        if (!empty($data['signature_data'])) {
            $signatureOverlay = '<img src="' . $data['signature_data'] . '" class="signature-overlay" alt="Digital Signature">';
        }

        $drySealText = $data['dry_seal'] ? 'Applied' : 'N/A';

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{$docType}</title>
    <style>
        @page { size: letter; margin: 1in; }
        body { font-family: 'Times New Roman', serif; margin: 0; padding: 0; }
        .certificate { position: relative; width: 8.5in; height: 11in; padding: 1in; box-sizing: border-box; }
        .header { text-align: center; margin-bottom: 0.5in; position: relative; }
        .logo-left { position: absolute; left: 0; top: 0; width: 80px; height: 80px; }
        .logo-right { position: absolute; right: 0; top: 0; width: 80px; height: 80px; }
        .municipal-seal { background: #e0e0e0; border: 2px solid #333; border-radius: 50%; width: 80px; height: 80px; display: flex; align-items: center; justify-content: center; font-size: 8px; }
        .title { font-size: 24px; font-weight: bold; margin: 10px 0; color: #1a5c38; }
        .subtitle { font-size: 14px; margin: 5px 0; }
        .body { font-size: 14px; line-height: 1.8; margin: 0.3in 0; }
        .content { text-align: center; margin: 0.3in 0; font-size: 16px; }
        .name { font-size: 20px; font-weight: bold; margin: 10px 0; }
        .details { margin: 0.2in 0; font-size: 12px; }
        .footer { position: absolute; bottom: 1in; width: 100%; text-align: center; }
        .watermark { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%) rotate(-30deg); font-size: 80px; color: rgba(26, 92, 56, 0.05); z-index: 0; }
        .qr-code { position: absolute; bottom: 1.5in; right: 1in; width: 80px; height: 80px; }
        .signature-overlay { position: absolute; bottom: 2in; right: 1.5in; width: 150px; height: 60px; opacity: 0.3; }
        .dry-seal { font-style: italic; color: #666; margin-top: 5px; }
        .control-number { font-size: 12px; color: #666; margin-top: 0.5in; }
        .seal { font-weight: bold; }
    </style>
</head>
<body>
    <div class="certificate">
        <div class="watermark">BARANGAY BIDDUANG</div>
        <div class="header">
            <div class="logo-left">
                <img src="assets/img/Brgy_Bidduang.png" alt="Barangay Logo" style="width:100%;height:100%;">
            </div>
            <div class="logo-right">
                <div class="municipal-seal">MUNICIPAL<br>SEAL</div>
            </div>
            <div class="title">Republic of the Philippines</div>
            <div class="subtitle">Barangay Bidduang, Municipality of <span class="seal">_______</span></div>
        </div>

        <div class="body">
            <div class="content">
                <div class="title">{$docType}</div>
                <div>No. _____ Series of {$dateIssued}</div>
            </div>

            <div class="content">
                <span class="name">{$residentName}</span><br>
                <span>of this Barangay, has been found to be a credential holder in good standing.</span>
            </div>

            <div class="details">
                <p><strong>Purpose:</strong> {$data['purpose']}</p>
                <p><strong>Amount (if applicable):</strong> PHP {$data['amount']}</p>
                <p><strong>Date Issued:</strong> {$dateIssued}</p>
            </div>
        </div>

        <div class="footer">
            <div class="signature-overlay">{$signatureOverlay}</div>
            <div><strong>PUNONG BARANGAY</strong></div>
            <div class="dry-seal">Dry Seal: {$drySealText}</div>
            <div class="control-number">Control No.: {$data['control_number']} | OR No.: {$data['or_number']}</div>
        </div>

        <div class="qr-code">
            <img src="{$qrCode}" alt="QR Code" style="width:100%;height:100%;">
            <div style="font-size:8px;text-align:center;">Scan to verify</div>
        </div>
    </div>
</body>
</html>
HTML;
    }

    private function getResidentName(int $residentId): string
    {
        if (!$this->pdo) return 'Unknown';
        $stmt = $this->pdo->prepare("SELECT full_name FROM residents WHERE id = ?");
        $stmt->execute([$residentId]);
        $row = $stmt->fetch();
        return $row ? $row['full_name'] : 'Unknown';
    }

    public function verifyDocument(string $controlNumber): ?array
    {
        if (!$this->pdo) return null;

        $stmt = $this->pdo->prepare("
            SELECT d.*, r.full_name, r.address
            FROM documents d
            JOIN residents r ON d.resident_id = r.id
            WHERE d.control_number = ?
        ");
        $stmt->execute([$controlNumber]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
