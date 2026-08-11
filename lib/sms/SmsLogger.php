<?php
/**
 * SmsLogs Repository
 *
 * Persists every outbound SMS attempt to the `SmsLogs` audit table. This is the
 * system-of-record for SMS delivery (independent of the broadcast message_queue).
 * All writes go through prepared statements; nothing here performs network I/O.
 */

class SmsLogger
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Insert a single log row. Returns the new LogID.
     * @param array $data [recipient_number, message_body, category, gateway_response_code, delivery_status, reference_id, gateway, segment_index, segment_count]
     */
    public function log(array $data): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO SmsLogs
                (RecipientNumber, MessageBody, Category, GatewayResponseCode, DeliveryStatus, ReferenceID, Gateway, SegmentIndex, SegmentCount, Timestamp)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $data['recipient_number'] ?? '',
            $data['message_body']   ?? '',
            $data['category']       ?? 'Custom',
            $data['gateway_response_code'] ?? null,
            $data['delivery_status'] ?? 'Pending',
            $data['reference_id']   ?? null,
            $data['gateway']        ?? null,
            $data['segment_index']  ?? 0,
            $data['segment_count']  ?? 1,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function updateStatus(int $logId, string $status, ?string $gatewayResponseCode = null): void
    {
        if ($gatewayResponseCode !== null) {
            $stmt = $this->pdo->prepare("UPDATE SmsLogs SET DeliveryStatus = ?, GatewayResponseCode = ? WHERE LogID = ?");
            $stmt->execute([$status, $gatewayResponseCode, $logId]);
        } else {
            $stmt = $this->pdo->prepare("UPDATE SmsLogs SET DeliveryStatus = ? WHERE LogID = ?");
            $stmt->execute([$status, $logId]);
        }
    }

    /** Recent logs for the audit UI / debugging. */
    public function recent(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM SmsLogs ORDER BY Timestamp DESC LIMIT ?");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Failure-rate over the last $minutes. Returns fraction (0..1) of failed attempts.
     * Used to raise failure-rate alerts when the gateway is unhealthy.
     */
    public function failureRate(int $minutes = 10): float
    {
        $stmt = $this->pdo->prepare("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN DeliveryStatus = 'Failed' THEN 1 ELSE 0 END) AS failed
            FROM SmsLogs
            WHERE Timestamp >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
        ");
        $stmt->bindValue(1, $minutes, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)$row['total'] === 0) {
            return 0.0;
        }
        return (int)$row['failed'] / (int)$row['total'];
    }
}
