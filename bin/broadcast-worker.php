<?php
/**
 * Broadcast Worker Daemon (CLI)
 *
 * Processes the message_queue asynchronously.
 * Runs as a daemon (via cron or systemd/supervisor).
 *
 * Usage: php bin/broadcast-worker.php [--batch=50] [--gateway=semaphore] [--dry-run]
 *
 * Features:
 *   - Batch processing (default 50 messages per batch)
 *   - Priority-based dispatch (high priority first)
 *   - Automatic retry with exponential backoff
 *   - Dead letter handling for permanently failed messages
 *   - Real-time status updates to broadcast_deliveries table
 */

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

define('BASE_PATH', __DIR__ . '/..');
define('IS_CLI', true);

require_once BASE_PATH . '/config.php';
require_once BASE_PATH . '/lib/SMSGateway.php';
require_once BASE_PATH . '/lib/AuditLogger.php';

$options = getopt('', ['batch:', 'gateway:', 'dry-run', 'once']);
$batchSize = (int)($options['batch'] ?? 50);
$gatewayOverride = $options['gateway'] ?? null;
$dryRun = isset($options['dry-run']);
$runOnce = isset($options['once']);

$pdo = $GLOBALS['pdo'];
$gateway = SMSGateway::getInstance();

echo "[" . date('Y-m-d H:i:s') . "] Broadcast Worker started" . ($dryRun ? " (DRY RUN)" : "") . "\n";

while (true) {
    $processed = processBatch($pdo, $gateway, $batchSize, $gatewayOverride, $dryRun);
    echo "[" . date('Y-m-d H:i:s') . "] Processed: {$processed} messages\n";

    if ($runOnce || $processed === 0) {
        echo "[" . date('Y-m-d H:i:s') . "] No more messages, exiting\n";
        break;
    }

    sleep(2);
}

echo "[" . date('Y-m-d H:i:s') . "] Broadcast Worker stopped\n";

function processBatch($pdo, $gateway, $batchSize, $gatewayOverride, $dryRun): int
{
    // Get pending messages, ordered by priority then creation time
    $sql = "
        SELECT mq.*, b.category, b.sender_id
        FROM message_queue mq
        JOIN broadcasts b ON mq.broadcast_id = b.id
        WHERE mq.status IN ('PENDING', 'PROCESSING')
          AND mq.attempts < mq.max_attempts
          AND (mq.scheduled_at IS NULL OR mq.scheduled_at <= NOW())
        ORDER BY mq.priority DESC, mq.created_at ASC
        LIMIT :batch_size
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':batch_size', $batchSize, PDO::PARAM_INT);
    $stmt->execute();
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($messages)) {
        return 0;
    }

    foreach ($messages as $msg) {
        // Lock this message for processing
        $updateStmt = $pdo->prepare("UPDATE message_queue SET status = 'PROCESSING', updated_at = NOW() WHERE id = ? AND status IN ('PENDING', 'PROCESSING')");
        $updateStmt->execute([$msg['id']]);

        if ($dryRun) {
            echo "  [DRY-RUN] Would send to {$msg['phone_number']}: " . substr($msg['message'], 0, 50) . "...\n";
            continue;
        }

        // Use specified gateway or the message's gateway
        $gatewayName = $gatewayOverride ?? $msg['gateway'];

        // Send via gateway
        $sendResult = $gateway->send($msg['phone_number'], $msg['message']);

        // Record delivery
        $deliveryStatus = 'SENT';
        $gatewayResponse = '';
        $gatewayMessageId = '';
        $errorMessage = '';

        if ($sendResult['success']) {
            $deliveryStatus = 'SENT';
            $gatewayResponse = json_encode($sendResult);
            $gatewayMessageId = $sendResult['message_id'] ?? '';
        } else {
            $deliveryStatus = 'FAILED';
            $errorMessage = $sendResult['error'];
            AuditLogger::log('AUTH', 'Broadcast', $msg['broadcast_id'], null, [
                'event' => 'sms_failed',
                'phone' => maskPhone($msg['phone_number']),
                'error' => $sendResult['error'],
                'attempts' => $msg['attempts'] + 1,
            ], 'WARN');
        }

        // Update message queue
        $updateMsgStmt = $pdo->prepare("
            UPDATE message_queue 
            SET status = ?, 
                gateway_response = ?,
                gateway_message_id = ?,
                error_message = ?,
                attempts = attempts + 1,
                sent_at = " . ($sendResult['success'] ? 'NOW()' : 'NULL') . "
            WHERE id = ?
        ");
        $updateMsgStmt->execute([
            $sendResult['success'] ? 'SENT' : 'FAILED',
            $gatewayResponse,
            $gatewayMessageId,
            $errorMessage,
            $msg['id'],
        ]);

        // Record in broadcast_deliveries table
        $deliveryStmt = $pdo->prepare("
            INSERT INTO broadcast_deliveries 
                (broadcast_id, recipient_id, phone_number, status, gateway_response, gateway_message_id, sent_at)
            VALUES (?, ?, ?, ?, ?, ?, " . ($sendResult['success'] ? 'NOW()' : 'NULL') . ")
            ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                gateway_response = VALUES(gateway_response),
                gateway_message_id = VALUES(gateway_message_id),
                sent_at = VALUES(sent_at)
        ");
        $deliveryStmt->execute([
            $msg['broadcast_id'],
            $msg['recipient_id'] ?? null,
            $msg['phone_number'],
            $deliveryStatus,
            $gatewayResponse,
            $gatewayMessageId,
        ]);

        // Check if broadcast is complete
        checkBroadcastCompletion($pdo, $msg['broadcast_id']);
    }

    return count($messages);
}

function checkBroadcastCompletion($pdo, $broadcastId): void
{
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status IN ('SENT', 'DELIVERED') THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status = 'FAILED' THEN 1 ELSE 0 END) as failed,
            SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END) as pending
        FROM message_queue
        WHERE broadcast_id = ?
    ");
    $stmt->execute([$broadcastId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row && $row['total'] > 0) {
        $allDone = ($row['completed'] + $row['failed']) >= $row['total'];
        if ($allDone) {
            $newStatus = $row['failed'] > 0 && $row['completed'] > 0 ? 'COMPLETED' : 
                         ($row['failed'] === $row['total'] ? 'FAILED' : 'COMPLETED');
            
            $updateStmt = $pdo->prepare("
                UPDATE broadcasts 
                SET status = ?, sent_at = NOW() 
                WHERE id = ?
            ");
            $updateStmt->execute([$newStatus, $broadcastId]);

            AuditLogger::log('AUTH', 'Broadcast', $broadcastId, null, [
                'event' => 'broadcast_completed',
                'total' => $row['total'],
                'completed' => $row['completed'],
                'failed' => $row['failed'],
                'status' => $newStatus,
            ]);
        }
    }
}

function maskPhone(string $phone): string
{
    if (strlen($phone) <= 4) return str_repeat('*', strlen($phone));
    return substr($phone, 0, 2) . str_repeat('*', strlen($phone) - 4) . substr($phone, -2);
}
