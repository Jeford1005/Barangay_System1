<?php
/**
 * SMS Worker Daemon (CLI)
 *
 * Processes the sms_outbox queue asynchronously (non-blocking to web requests).
 * Run as a daemon / cron / supervisor:
 *   php bin/sms-worker.php [--batch=50] [--once]
 *
 * On Windows (XAMPP) schedule via Task Scheduler calling this script, or run
 * `php bin/sms-worker.php --once` after each broadcast from the web layer.
 */

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

define('BASE_PATH', __DIR__ . '/..');
define('IS_CLI', true);

require_once BASE_PATH . '/config.php';
require_once BASE_PATH . '/lib/sms/SmsService.php';
require_once BASE_PATH . '/lib/sms/SemaphoreSmsProvider.php';
require_once BASE_PATH . '/lib/sms/SmsLogger.php';
require_once BASE_PATH . '/lib/sms/SmsTriggers.php';
require_once BASE_PATH . '/lib/AuditLogger.php';

$options = getopt('', ['batch:', 'once']);
$batchSize = (int)($options['batch'] ?? 50);
$runOnce = isset($options['once']);

$pdo = $GLOBALS['pdo'];
$logger = new SmsLogger($pdo);
$service = SmsService::init(new SemaphoreSmsProvider(), $logger, $pdo);

echo "[" . date('Y-m-d H:i:s') . "] SMS Worker started (batch=$batchSize)\n";

while (true) {
    $pending = SmsService::fetchPending($batchSize);
    if (empty($pending)) {
        echo "[" . date('Y-m-d H:i:s') . "] No pending messages\n";
        break;
    }

    foreach ($pending as $row) {
        echo "[" . date('Y-m-d H:i:s') . "] Sending outbox#{$row['id']} -> {$row['recipient']} ";
        try {
            $ok = SmsService::processOutbox((int)$row['id']);
            echo $ok ? "OK\n" : "FAILED\n";
        } catch (Throwable $e) {
            echo "ERROR: " . $e->getMessage() . "\n";
        }
    }

    if ($runOnce) {
        break;
    }
    sleep(2);
}

echo "[" . date('Y-m-d H:i:s') . "] SMS Worker stopped\n";
