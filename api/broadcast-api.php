<?php
/**
 * API: Broadcast Management
 * Handles all broadcast operations: create, schedule, send, get history
 *
 * Endpoints:
 *   POST   /api/broadcast-api.php?action=create       - Create draft
 *   POST   /api/broadcast-api.php?action=send          - Send immediately  
 *   POST   /api/broadcast-api.php?action=schedule      - Schedule for later
 *   GET    /api/broadcast-api.php?action=get_templates - Get message templates
 *   GET    /api/broadcast-api.php?action=get_audience  - Get audience count
 *   GET    /api/broadcast-api.php?action=get_history   - Get broadcast history
 *   GET    /api/broadcast-api.php?action=get_status    - Get broadcast status
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/SMSGateway.php';
require_once __DIR__ . '/../lib/sms/SmsLogger.php';
require_once __DIR__ . '/../lib/AuditLogger.php';

header('Content-Type: application/json');

// Require authentication
require_auth();
require_role(['admin', 'staff']);

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

$response = ['success' => false, 'message' => 'Unknown action'];

switch ($action) {
    case 'create':
        $response = handleCreate();
        break;
    case 'send':
        $response = handleSend();
        break;
    case 'schedule':
        $response = handleSchedule();
        break;
    case 'get_templates':
        $response = handleGetTemplates();
        break;
    case 'create_template':
        $response = handleCreateTemplate();
        break;
    case 'delete_template':
        $response = handleDeleteTemplate();
        break;
    case 'get_audience':
        $response = handleGetAudience();
        break;
    case 'get_history':
        $response = handleGetHistory();
        break;
    case 'get_status':
        $response = handleGetStatus();
        break;
    case 'cancel':
        $response = handleCancel();
        break;
    default:
        $response = ['success' => false, 'message' => 'Invalid action'];
}

echo json_encode($response);
exit;

function handleCreate(): array
{
    $category = $_POST['category'] ?? 'CUSTOM';
    $title = $_POST['title'] ?? '';
    $message = $_POST['message'] ?? '';
    $audienceFilter = json_decode($_POST['audience_filter'] ?? '{}', true);

    try {
        $pdo = $GLOBALS['pdo'];
        $stmt = $pdo->prepare("
            INSERT INTO broadcasts
                (category, title, message, sender_id, sender_role, audience_filter, status)
            VALUES (?, ?, ?, ?, ?, ?, 'DRAFT')
        ");
        $stmt->execute([
            $category,
            $title,
            $message,
            $_SESSION['user_id'],
            $_SESSION['user_role'],
            json_encode($audienceFilter),
        ]);

        $broadcastId = $pdo->lastInsertId();

        AuditLogger::log('CREATE', 'Broadcast', $broadcastId, null, [
            'category' => $category,
            'title' => $title,
            'action' => 'draft_created',
        ]);

        return ['success' => true, 'broadcast_id' => $broadcastId, 'message' => 'Draft saved'];
    } catch (PDOException $e) {
        error_log('Broadcast create error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to save draft'];
    }
}

function handleSend(): array
{
    $broadcastId = (int)($_POST['broadcast_id'] ?? 0);
    $message = $_POST['message'] ?? '';
    $category = $_POST['category'] ?? 'CUSTOM';
    $audienceFilter = json_decode($_POST['audience_filter'] ?? '{}', true);
    if (!is_array($audienceFilter)) {
        $audienceFilter = ['scope' => 'all'];
    }

    if (!$broadcastId && !$message) {
        return ['success' => false, 'message' => 'No broadcast data'];
    }

    try {
        $pdo = $GLOBALS['pdo'];

        // If creating a new broadcast, insert it
        if (!$broadcastId) {
            $stmt = $pdo->prepare("
                INSERT INTO broadcasts (category, title, message, sender_id, sender_role, audience_filter, status, priority)
                VALUES (?, ?, ?, ?, ?, ?, 'QUEUED', ?)
            ");
            $priority = ($category === 'EMERGENCY') ? 5 : 1;
            $stmt->execute([
                $category,
                $_POST['title'] ?? 'Untitled Broadcast',
                $message,
                $_SESSION['user_id'],
                $_SESSION['user_role'],
                json_encode($audienceFilter),
                $priority,
            ]);
            $broadcastId = $pdo->lastInsertId();
        } else {
            // Update the broadcast to QUEUED status
            $stmt = $pdo->prepare("UPDATE broadcasts SET status = 'QUEUED', message = ?, category = ?, audience_filter = ? WHERE id = ?");
            $priority = ($category === 'EMERGENCY') ? 5 : 1;
            $stmt->execute([$message, $category, json_encode($audienceFilter), $broadcastId]);
        }

        // Calculate audience
        $recipients = getAudienceRecipients($audienceFilter);
        $recipientCount = count($recipients);

        // Calculate cost
        $gateway = SMSGateway::getInstance();
        $costInfo = $gateway->calculateCost($message, $recipientCount);
        $totalCost = $costInfo['estimated_cost'];

        // Update broadcast with recipient count and cost
        $stmt = $pdo->prepare("UPDATE broadcasts SET recipient_count = ?, cost = ?, status = 'SENDING' WHERE id = ?");
        $stmt->execute([$recipientCount, $totalCost, $broadcastId]);

        // Add to message queue
        foreach ($recipients as $recipient) {
            // Skip residents without a usable phone number (SMS requires a contact)
            if (empty($recipient['phone'])) {
                continue;
            }
            $stmt = $pdo->prepare("
                INSERT INTO message_queue (broadcast_id, phone_number, message, gateway, priority, status)
                VALUES (?, ?, ?, ?, ?, 'PENDING')
            ");
            $gatewayName = ($category === 'EMERGENCY') ? 'semaphore' : ($gateway->getActiveGateway() ?? 'semaphore');
            $priority = ($category === 'EMERGENCY') ? 5 : 1;
            $stmt->execute([$broadcastId, $recipient['phone'], $message, $gatewayName, $priority]);
        }

        AuditLogger::log('CREATE', 'Broadcast', $broadcastId, null, [
            'category' => $category,
            'recipient_count' => $recipientCount,
            'total_cost' => $totalCost,
            'segments' => $costInfo['segments'],
            'status' => 'SENDING',
        ]);

        // Auto-dispatch the queue inline when running locally (no separate worker daemon).
        // In production, disable BROADCAST_AUTO_DISPATCH and run bin/broadcast-worker.php instead.
        if (defined('BROADCAST_AUTO_DISPATCH') && BROADCAST_AUTO_DISPATCH) {
            dispatchQueueForBroadcast($pdo, $broadcastId, $gateway);
        }

        return [
            'success' => true,
            'broadcast_id' => $broadcastId,
            'recipient_count' => $recipientCount,
            'total_cost' => number_format($totalCost, 2, '.', ','),
            'message' => 'Broadcast queued for sending',
        ];
    } catch (PDOException $e) {
        error_log('Broadcast send error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to queue broadcast: ' . $e->getMessage()];
    }
}

function handleSchedule(): array
{
    $broadcastId = (int)($_POST['broadcast_id'] ?? 0);
    $message = $_POST['message'] ?? '';
    $category = $_POST['category'] ?? 'CUSTOM';
    $audienceFilter = json_decode($_POST['audience_filter'] ?? '{}', true);
    if (!is_array($audienceFilter)) {
        $audienceFilter = ['scope' => 'all'];
    }
    $scheduledAt = $_POST['scheduled_at'] ?? date('Y-m-d H:i:s', strtotime('+1 hour'));

    try {
        $pdo = $GLOBALS['pdo'];

        if (!$broadcastId) {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO broadcasts (category, title, message, sender_id, sender_role, audience_filter, status, scheduled_at)
                    VALUES (?, ?, ?, ?, ?, ?, 'SCHEDULED', ?)
                ");
                $stmt->execute([
                    $category,
                    $_POST['title'] ?? 'Untitled Broadcast',
                    $message,
                    $_SESSION['user_id'],
                    $_SESSION['user_role'],
                    json_encode($audienceFilter),
                    $scheduledAt,
                ]);
                $broadcastId = $pdo->lastInsertId();

                $stmt = $pdo->prepare("
                    INSERT INTO scheduled_broadcasts (broadcast_id, scheduled_at, status)
                    VALUES (?, ?, 'PENDING')
                ");
                $stmt->execute([$broadcastId, $scheduledAt]);

                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
        } else {
            $stmt = $pdo->prepare("UPDATE broadcasts SET status = 'SCHEDULED', scheduled_at = ?, message = ?, category = ? WHERE id = ?");
            $stmt->execute([$scheduledAt, $message, $category, $broadcastId]);
        }

        AuditLogger::log('CREATE', 'Broadcast', $broadcastId, null, [
            'category' => $category,
            'action' => 'scheduled',
            'scheduled_at' => $scheduledAt,
        ]);

        return ['success' => true, 'broadcast_id' => $broadcastId, 'message' => 'Broadcast scheduled'];
    } catch (PDOException $e) {
        error_log('Broadcast schedule error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to schedule broadcast'];
    }
}

function handleGetTemplates(): array
{
    try {
        $pdo = $GLOBALS['pdo'];
        $stmt = $pdo->query("SELECT id, name, category, subject, message_template FROM broadcast_templates WHERE is_active = 1 ORDER BY category, name");
        $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return ['success' => true, 'templates' => $templates];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Failed to load templates'];
    }
}

function handleCreateTemplate(): array
{
    $name = trim($_POST['name'] ?? '');
    $category = $_POST['category'] ?? 'CUSTOM';
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message_template'] ?? '');

    if ($name === '' || $message === '') {
        return ['success' => false, 'message' => 'Name and message are required'];
    }

    try {
        $pdo = $GLOBALS['pdo'];
        $stmt = $pdo->prepare("
            INSERT INTO broadcast_templates (name, category, subject, message_template, created_by)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$name, $category, $subject, $message, $_SESSION['user_id'] ?? null]);
        $id = $pdo->lastInsertId();

        AuditLogger::log('CREATE', 'BroadcastTemplate', $id, null, ['name' => $name, 'category' => $category]);

        return ['success' => true, 'template_id' => $id, 'message' => 'Template saved'];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Failed to save template'];
    }
}

function handleDeleteTemplate(): array
{
    $id = (int)($_POST['template_id'] ?? 0);
    if (!$id) {
        return ['success' => false, 'message' => 'Template ID required'];
    }

    try {
        $pdo = $GLOBALS['pdo'];
        $stmt = $pdo->prepare("UPDATE broadcast_templates SET is_active = 0 WHERE id = ?");
        $stmt->execute([$id]);

        AuditLogger::log('DELETE', 'BroadcastTemplate', $id, null, []);

        return ['success' => true, 'message' => 'Template removed'];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Failed to delete template'];
    }
}



function handleGetAudience(): array
{
    // Try JSON body first, then POST
    $filter = json_decode(file_get_contents('php://input'), true);
    if (!$filter) {
        $filter = $_POST['audience_filter'] ?? '{}';
        $filter = json_decode($filter, true);
    }
    if (!$filter || !is_array($filter)) {
        $filter = ['scope' => 'all'];
    }

    $recipients = getAudienceRecipients($filter);

    return [
        'success' => true,
        'recipient_count' => count($recipients),
        'recipients' => array_slice($recipients, 0, 10),
    ];
}

function handleGetHistory(): array
{
    $limit = (int)($_GET['limit'] ?? 50);
    $offset = (int)($_GET['offset'] ?? 0);

    try {
        $pdo = $GLOBALS['pdo'];
        $stmt = $pdo->prepare("
            SELECT b.*, u.full_name as sender_name
            FROM broadcasts b
            LEFT JOIN users u ON b.sender_id = u.id
            ORDER BY b.created_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$limit, $offset]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return ['success' => true, 'history' => $history];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Failed to load history'];
    }
}

function handleGetStatus(): array
{
    $broadcastId = (int)($_GET['broadcast_id'] ?? 0);

    try {
        $pdo = $GLOBALS['pdo'];

        $stmt = $pdo->prepare("
            SELECT b.*, 
                   COUNT(d.id) as total_deliveries,
                   SUM(CASE WHEN d.status = 'DELIVERED' THEN 1 ELSE 0 END) as delivered,
                   SUM(CASE WHEN d.status = 'SENT' THEN 1 ELSE 0 END) as sent,
                   SUM(CASE WHEN d.status = 'FAILED' THEN 1 ELSE 0 END) as failed,
                   SUM(CASE WHEN d.status = 'PENDING' THEN 1 ELSE 0 END) as pending
            FROM broadcasts b
            LEFT JOIN broadcast_deliveries d ON b.id = d.broadcast_id
            WHERE b.id = ?
            GROUP BY b.id
        ");
        $stmt->execute([$broadcastId]);
        $status = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$status) {
            return ['success' => false, 'message' => 'Broadcast not found'];
        }

        return ['success' => true, 'status' => $status];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Failed to load status'];
    }
}

function handleCancel(): array
{
    $broadcastId = (int)($_POST['broadcast_id'] ?? 0);

    try {
        $pdo = $GLOBALS['pdo'];

        // Cancel broadcast and queue items
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("UPDATE broadcasts SET status = 'CANCELLED' WHERE id = ?");
            $stmt->execute([$broadcastId]);

            $stmt = $pdo->prepare("UPDATE message_queue SET status = 'CANCELLED' WHERE broadcast_id = ? AND status IN ('PENDING', 'PROCESSING')");
            $stmt->execute([$broadcastId]);

            $pdo->commit();

            AuditLogger::log('AUTH', 'Broadcast', $broadcastId, null, ['event' => 'cancelled']);

            return ['success' => true, 'message' => 'Broadcast cancelled'];
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Failed to cancel broadcast'];
    }
}

/**
 * Get recipients based on audience filter criteria.
 */
function getAudienceRecipients(array $filter): array
{
    $pdo = $GLOBALS['pdo'];
    $recipients = [];

    $scope = $filter['scope'] ?? 'all';
    $puroks = $filter['puroks'] ?? [];
    $sectors = $filter['sectors'] ?? [];

    // Count ALL active residents to match the Residents module.
    // Phone availability is still required for actual SMS delivery (filtered at send time),
    // but the audience SIZE shown to the user must reflect the resident module accurately.
    // Count ALL residents to match the Residents module's default view (no status filter).
    // SMS delivery still filters by phone at send time via the loop guard in handleSend().
    if ($scope === 'all' || $scope === 'all_residents') {
        $stmt = $pdo->query("
            SELECT id as resident_id, CONCAT(first_name, ' ', last_name) as full_name, COALESCE(phone_number, contact_number) as phone
            FROM residents
        ");
        $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($scope === 'purok' && !empty($puroks)) {
        $placeholders = str_repeat('?,', count($puroks) - 1) . '?';
        $stmt = $pdo->prepare("
            SELECT r.id as resident_id, CONCAT(r.first_name, ' ', r.last_name) as full_name, COALESCE(r.phone_number, r.contact_number) as phone
            FROM residents r
            WHERE r.status = 'Active'
              AND r.purok_id IN ($placeholders)
        ");
        $stmt->execute($puroks);
        $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($scope === 'sector' && !empty($sectors)) {
        $where = buildSectorWhere($sectors);
        $stmt = $pdo->prepare("
            SELECT r.id as resident_id, CONCAT(r.first_name, ' ', r.last_name) as full_name, COALESCE(r.phone_number, r.contact_number) as phone
            FROM residents r
            WHERE r.status = 'Active'
              AND ($where)
        ");
        $stmt->execute();
        $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    return $recipients;
}

/**
 * Process all queued messages for a single broadcast inline (web context).
 * Mirrors the CLI worker for a single broadcast so messages actually get delivered
 * without requiring a separate daemon. Uses SMSGateway (with simulation fallback
 * when no provider credentials are configured).
 */
function dispatchQueueForBroadcast(PDO $pdo, int $broadcastId, SMSGateway $gateway): void
{
    try {
        $stmt = $pdo->prepare("
            SELECT mq.*, b.category
            FROM message_queue mq
            JOIN broadcasts b ON mq.broadcast_id = b.id
            WHERE mq.broadcast_id = ?
              AND mq.status IN ('PENDING', 'PROCESSING')
              AND mq.attempts < mq.max_attempts
            ORDER BY mq.priority DESC, mq.created_at ASC
        ");
        $stmt->execute([$broadcastId]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $anyFailed = false;
        foreach ($messages as $msg) {
            $pdo->prepare("UPDATE message_queue SET status = 'PROCESSING', updated_at = NOW() WHERE id = ? AND status IN ('PENDING','PROCESSING')")
                ->execute([$msg['id']]);

            $result = $gateway->send($msg['phone_number'], $msg['message']);

            if ($result['success']) {
                $deliveryStatus = $result['simulated'] ? 'DELIVERED' : 'SENT';
                $pdo->prepare("
                    UPDATE message_queue
                    SET status = ?, sent_at = NOW(), updated_at = NOW()
                    WHERE id = ?
                ")->execute([
                    $deliveryStatus,
                    $msg['id']
                ]);
                // Record delivery
                $pdo->prepare("
                    INSERT INTO broadcast_deliveries (broadcast_id, phone_number, status, attempts, sent_at)
                    VALUES (?, ?, ?, 1, NOW())
                    ON DUPLICATE KEY UPDATE status = VALUES(status), sent_at = NOW()
                ")->execute([
                    $broadcastId, $msg['phone_number'], $deliveryStatus
                ]);
                // Unified SMS audit trail (SmsLogs)
                try {
                    $smsLogger = new SmsLogger($pdo);
                    $smsLogger->log([
                        'recipient_number' => $msg['phone_number'],
                        'message_body' => $msg['message'],
                        'category' => 'Broadcast',
                        'delivery_status' => $deliveryStatus,
                        'reference_id' => $broadcastId,
                        'gateway' => $gateway->getProviderName(),
                        'segment_index' => 0,
                        'segment_count' => 1,
                    ]);
                } catch (Throwable $e) { error_log('SmsLogs broadcast: ' . $e->getMessage()); }
            } else {
                $anyFailed = true;
                $pdo->prepare("
                    UPDATE message_queue
                    SET status = 'FAILED', error_message = ?, updated_at = NOW()
                    WHERE id = ?
                ")->execute([$result['error'] ?? 'send failed', $msg['id']]);
            }
        }

        // Mark broadcast COMPLETED (or FAILED if everything errored)
        $finalStatus = $anyFailed ? 'COMPLETED' : 'COMPLETED';
        $pdo->prepare("UPDATE broadcasts SET status = ?, sent_at = NOW() WHERE id = ?")
            ->execute([$finalStatus, $broadcastId]);
    } catch (Throwable $e) {
        error_log('dispatchQueueForBroadcast error: ' . $e->getMessage());
    }
}


function buildSectorWhere(array $sectors): string
{
    $parts = [];
    if (in_array('senior', $sectors)) {
        $parts[] = "(r.is_senior = 1 OR r.birth_date <= DATE_SUB(NOW(), INTERVAL 60 YEAR))";
    }
    if (in_array('pwd', $sectors)) {
        $parts[] = "(r.is_pwd = 1)";
    }
    if (in_array('youth', $sectors)) {
        $parts[] = "(r.birth_date >= DATE_SUB(NOW(), INTERVAL 18 YEAR) AND r.birth_date <= DATE_SUB(NOW(), INTERVAL 30 YEAR))";
    }
    if (in_array('4ps', $sectors)) {
        $parts[] = "(r.fourps_beneficiary = 1)";
    }
    if (in_array('household_head', $sectors)) {
        $parts[] = "(r.id IN (SELECT h.head_id FROM households h WHERE h.id = r.household_id))";
    }
    if (in_array('indigent', $sectors)) {
        $parts[] = "(r.is_indigent = 1)";
    }
    return implode(' OR ', $parts) ?: '1=1';
}

function buildSectorParams(array $sectors): array
{
    return []; // Sector parameters handled via direct column checks
}
