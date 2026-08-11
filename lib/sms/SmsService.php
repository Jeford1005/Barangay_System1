<?php
/**
 * SmsService — Orchestrator / Application Service (static facade)
 *
 * Implemented as a static service to avoid instance-method dispatch issues
 * on the target PHP build. All state is held in static properties; configure
 * once via SmsService::init($transport, $logger, $pdo).
 *
 * Responsibilities: sanitize (plain text), E.164 normalize (via transport),
 * 160-char GSM segmentation, [BRGY NOTICE] envelope + STOP opt-out footer,
 * retry with exponential backoff, SmsLogs audit, failure-rate alerting,
 * and async dispatch through the sms_outbox queue.
 */

require_once __DIR__ . '/ISmsService.php';
require_once __DIR__ . '/SmsLogger.php';

class SmsService
{
    private static ISmsService $transport;
    private static SmsLogger $logger;
    private static $pdo;

    private static int $maxRetries = 3;
    private static int $segCharLimit = 160;
    private static array $optOutFooter = ['', ' Reply STOP to opt-out.'];
    private static float $failureAlertThreshold = 0.5;
    private static int $failureAlertWindowMin = 10;

    public static function init(ISmsService $transport, SmsLogger $logger, $pdo): void
    {
        self::$transport = $transport;
        self::$logger = $logger;
        self::$pdo = $pdo;
    }

    public static function setMaxRetries(int $n): void { self::$maxRetries = max(0, $n); }
    public static function setFailureAlertThreshold(float $t): void { self::$failureAlertThreshold = $t; }

    // ----------------------------------------------------------------
    public static function send(string $to, string $category, string $body, ?int $referenceId = null, ?string $recipientName = null): array
    {
        $body = self::envelope(self::sanitize($body), $recipientName);
        $segments = self::splitSegments($body);
        $logIds = [];
        $anySuccess = false;
        foreach ($segments as $idx => $seg) {
            $logId = self::dispatchWithRetry($to, $seg, $category, $referenceId, $idx, count($segments));
            $logIds[] = $logId;
            if (self::logStatus($logId) === 'Sent' || self::logStatus($logId) === 'Delivered') {
                $anySuccess = true;
            }
        }
        self::maybeAlertFailure();
        return ['success' => $anySuccess, 'log_ids' => $logIds, 'segments' => count($segments)];
    }

    public static function enqueue(string $to, string $category, string $body, ?int $referenceId = null, ?string $recipientName = null, int $priority = 1, ?string $scheduledAt = null): int
    {
        $body = self::envelope(self::sanitize($body), $recipientName);
        $stmt = self::$pdo->prepare("
            INSERT INTO sms_outbox
                (recipient, category, message_body, reference_id, recipient_name, priority, status, scheduled_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'QUEUED', ?, NOW())
        ");
        $stmt->execute([$to, $category, $body, $referenceId, $recipientName, $priority, $scheduledAt]);
        return (int) self::$pdo->lastInsertId();
    }

    public static function processOutbox(int $outboxId): bool
    {
        $stmt = self::$pdo->prepare("SELECT * FROM sms_outbox WHERE id = ? AND status IN ('QUEUED','PROCESSING','RETRY')");
        $stmt->execute([$outboxId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return false;

        self::$pdo->prepare("UPDATE sms_outbox SET status='PROCESSING', updated_at=NOW() WHERE id=?")->execute([$outboxId]);

        $segments = self::splitSegments($row['message_body']);
        $anySuccess = false;
        $lastError = null;
        foreach ($segments as $idx => $seg) {
            $logId = self::dispatchWithRetry($row['recipient'], $seg, $row['category'], $row['reference_id'] ? (int)$row['reference_id'] : null, $idx, count($segments));
            $status = self::logStatus($logId);
            if ($status === 'Sent' || $status === 'Delivered') {
                $anySuccess = true;
            } else {
                $lastError = self::logError($logId);
            }
        }
        $finalStatus = $anySuccess ? 'SENT' : 'FAILED';
        self::$pdo->prepare("UPDATE sms_outbox SET status=?, error_message=?, sent_at=NOW(), updated_at=NOW() WHERE id=?")
            ->execute([$finalStatus, $lastError, $outboxId]);
        self::maybeAlertFailure();
        return $anySuccess;
    }

    public static function fetchPending(int $limit = 50): array
    {
        $stmt = self::$pdo->prepare("
            SELECT * FROM sms_outbox
            WHERE status IN ('QUEUED','RETRY')
              AND attempts < max_attempts
              AND (scheduled_at IS NULL OR scheduled_at <= NOW())
            ORDER BY priority DESC, created_at ASC
            LIMIT ?
        ");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ----------------------------------------------------------------
    private static function dispatchWithRetry(string $to, string $seg, string $category, ?int $ref, int $idx, int $count): int
    {
        $logId = self::$logger->log([
            'recipient_number' => $to, 'message_body' => $seg, 'category' => $category,
            'delivery_status' => 'Pending', 'reference_id' => $ref,
            'gateway' => self::$transport->getName(), 'segment_index' => $idx, 'segment_count' => $count,
        ]);

        $attempt = 0;
        $delayMs = 500;
        while ($attempt <= self::$maxRetries) {
            $result = self::$transport->sendOne($to, $seg);
            $code = self::responseCode($result);
            if ($result->success) {
                self::$logger->updateStatus($logId, $result->simulated ? 'Delivered' : 'Sent', $result->gatewayResponse);
                return $logId;
            }
            if (!self::isRetryable($result)) {
                self::$logger->updateStatus($logId, 'Failed', $result->gatewayResponse);
                return $logId;
            }
            $attempt++;
            if ($attempt <= self::$maxRetries) {
                usleep($delayMs * 1000);
                $delayMs *= 2;
            }
        }
        self::$logger->updateStatus($logId, 'Failed', $result->gatewayResponse ?? 'RETRY_EXHAUSTED');
        return $logId;
    }

    private static function isRetryable(SmsResult $r): bool
    {
        $err = strtolower((string)($r->error ?? ''));
        if (str_contains($err, 'api key') || str_contains($err, 'not configured') || str_contains($err, 'unauthorized')) return false;
        if (str_contains($err, 'invalid number') || str_contains($err, 'invalid phone')) return false;
        return true;
    }

    private static function responseCode(SmsResult $r): ?string
    {
        if ($r->simulated) return 'SIMULATED';
        if ($r->success) return '200';
        if (preg_match('/HTTP\s+(\d{3})/', (string)$r->error, $m)) return $m[1];
        return 'ERR';
    }

    private static function maybeAlertFailure(): void
    {
        $rate = self::$logger->failureRate(self::$failureAlertWindowMin);
        if ($rate >= self::$failureAlertThreshold) {
            $msg = sprintf('SMS FAILURE-RATE ALERT: %.0f%% of SMS failed in last %d min (gateway=%s, simulation=%s)',
                $rate * 100, self::$failureAlertWindowMin, self::$transport->getName(), var_export(self::$transport->isSimulation(), true));
            error_log($msg);
            if (class_exists('AuditLogger')) {
                try { AuditLogger::log('ALERT', 'SmsGateway', null, null, ['rate' => $rate, 'gateway' => self::$transport->getName()], 'WARN'); } catch (Throwable $e) {}
            }
        }
    }

    // ---- formatting helpers (static) ----
    private static function sanitize(string $text): string
    {
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = strtr($text, array("**"=>"", "__"=>"", "`"=>"", "*"=>"", "_"=>"", "#"=>"", ">"=>"", "<"=>"", "\r"=>" ", "\n"=>" ", "\t"=>" "));
        $collapsed = ''; $prevSpace = false; $clen = strlen($text);
        for ($ci = 0; $ci < $clen; $ci++) {
            $c = $text[$ci];
            if ($c === ' ' || $c === "\t") { if (!$prevSpace) $collapsed .= ' '; $prevSpace = true; }
            else { $collapsed .= $c; $prevSpace = false; }
        }
        return trim($collapsed);
    }

    private static function envelope(string $body, ?string $name): string
    {
        $header = '[BRGY NOTICE]';
        $prefix = $name ? $header . ' ' . $name . ',' : $header;
        return $prefix . ' ' . $body . self::$optOutFooter[1];
    }

    private static function splitSegments(string $text): array
    {
        $text = substr($text, 0, 160 * 10);
        if (strlen($text) <= self::$segCharLimit) return array($text);
        $limit = self::$segCharLimit; $chunks = array();
        for ($si = 0; $si < strlen($text); $si += $limit) { $chunks[] = substr($text, $si, $limit); }
        $total = count($chunks);
        if ($total <= 1) return $chunks;
        foreach ($chunks as $i => $chunk) { $chunks[$i] = sprintf('(%d/%d) %s', $i + 1, $total, $chunk); }
        return $chunks;
    }

    private static function logStatus(int $logId): string
    {
        $stmt = self::$pdo->prepare("SELECT DeliveryStatus FROM SmsLogs WHERE LogID = ?");
        $stmt->execute([$logId]);
        return (string)$stmt->fetchColumn();
    }
    private static function logError(int $logId): ?string
    {
        $stmt = self::$pdo->prepare("SELECT GatewayResponseCode FROM SmsLogs WHERE LogID = ?");
        $stmt->execute([$logId]);
        $v = $stmt->fetchColumn();
        return $v ?: null;
    }
}
