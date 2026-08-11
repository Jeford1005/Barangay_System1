<?php
/**
 * SmsTriggers — Event integration points (static facade).
 *
 * Called from business flows (document status update, blotter hearing
 * scheduling, broadcast dispatch). Each builds a context message and pushes
 * it through SmsService (async queue). Failures are logged, never fatal.
 */

require_once __DIR__ . '/SmsService.php';
require_once __DIR__ . '/SemaphoreSmsProvider.php';

class SmsTriggers
{
    private static ?PDO $pdo = null;

    /** Configure the underlying static SmsService. Call once per request. */
    public static function configure(PDO $pdo): void
    {
        self::$pdo = $pdo;
        $logger = new SmsLogger($pdo);
        $transport = new SemaphoreSmsProvider();
        SmsService::init($transport, $logger, $pdo);
    }

    /** Factory: configure using the global $pdo. */
    public static function make(): void
    {
        if (self::$pdo === null) {
            self::configure($GLOBALS['pdo']);
        }
    }

    // --------------------------------------------------------------
    // 1) DOCUMENT PROCESSING
    // --------------------------------------------------------------
    public static function documentStatusUpdate(int $requestId, string $newStatus): void
    {
        self::make();
        if (!in_array($newStatus, ['Ready for Pickup', 'Rejected'], true)) return;

        $row = self::$pdo->prepare("
            SELECT dr.resident_id, dr.document_type_id, dt.name AS doc_name,
                   r.first_name, r.contact_number, r.phone_number
            FROM document_requests dr
            JOIN residents r ON r.id = dr.resident_id
            JOIN document_types dt ON dt.id = dr.document_type_id
            WHERE dr.id = ?
        ");
        $row->execute([$requestId]);
        $d = $row->fetch(PDO::FETCH_ASSOC);
        if (!$d) return;

        $phone = $d['contact_number'] ?: $d['phone_number'];
        if (!$phone) return;

        $body = ($newStatus === 'Ready for Pickup')
            ? "Your {$d['doc_name']} is READY FOR PICKUP at the Barangay Hall. Bring your OR/receipt. Thank you."
            : "Your {$d['doc_name']} request was REJECTED. Please visit the Barangay Hall for details. Thank you.";

        SmsService::enqueue($phone, 'Document', $body, $requestId, $d['first_name'], 2);
    }

    // --------------------------------------------------------------
    // 2) BLOTTER & SUMMONS
    // --------------------------------------------------------------
    public static function hearingScheduled(int $caseId, string $hearingDate, string $hearingTime, string $luponDesk = 'Lupon Desk'): void
    {
        self::make();
        $row = self::$pdo->prepare("
            SELECT bc.case_number,
                   c.first_name AS complainant_fn, c.contact_number AS complainant_phone,
                   rp.first_name AS respondent_fn, rp.contact_number AS respondent_phone
            FROM blotter_cases bc
            LEFT JOIN residents c  ON c.id  = bc.complainant_id
            LEFT JOIN residents rp ON rp.id = bc.respondent_id
            WHERE bc.id = ?
        ");
        $row->execute([$caseId]);
        $b = $row->fetch(PDO::FETCH_ASSOC);
        if (!$b) return;

        $body = "Hearing for case {$b['case_number']} set on {$hearingDate} at {$hearingTime}, {$luponDesk}. Attendance required.";

        if (!empty($b['complainant_phone'])) {
            SmsService::enqueue($b['complainant_phone'], 'Summons', $body, $caseId, $b['complainant_fn'], 3);
        }
        if (!empty($b['respondent_phone'])) {
            SmsService::enqueue($b['respondent_phone'], 'Summons', $body, $caseId, $b['respondent_fn'], 3);
        }
    }

    public static function hearingReminder(int $caseId, string $hearingDate, string $hearingTime, string $luponDesk = 'Lupon Desk'): void
    {
        self::hearingScheduled($caseId, $hearingDate, $hearingTime, $luponDesk);
    }

    // --------------------------------------------------------------
    // 3) COMMUNITY BROADCAST
    // --------------------------------------------------------------
    public static function broadcast(int $broadcastId, string $message, array $recipients, string $category = 'Broadcast'): array
    {
        self::make();
        $enqueued = 0;
        foreach ($recipients as $r) {
            $phone = $r['phone'] ?? ($r['contact_number'] ?? null);
            if (!$phone) continue;
            SmsService::enqueue($phone, 'Broadcast', $message, $broadcastId, $r['first_name'] ?? null, 1);
            $enqueued++;
        }
        return ['enqueued' => $enqueued];
    }

    /** Immediate (synchronous) send. */
    public static function sendNow(string $to, string $category, string $body, ?int $ref = null, ?string $name = null): array
    {
        self::make();
        return SmsService::send($to, $category, $body, $ref, $name);
    }
}
