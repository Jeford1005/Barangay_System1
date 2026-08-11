<?php
/**
 * Print Worker Daemon (CLI)
 *
 * Processes the print_queue asynchronously.
 * Runs as a daemon or once (for cron-based execution).
 *
 * Usage: 
 *   php bin/print-worker.php           (continuous daemon)
 *   php bin/print-worker.php --once    (single batch, then exit)
 *   php bin/print-worker.php --limit=10
 *
 * Features:
 *   - Priority-based print ordering
 *   - Retry logic for failed prints
 *   - Pause/resume support
 *   - SMS pickup notification to residents
 *   - Audit logging of all print actions
 */

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

define('BASE_PATH', __DIR__ . '/..');
define('IS_CLI', true);

require_once BASE_PATH . '/config.php';
require_once BASE_PATH . '/lib/PrintQueue.php';
require_once BASE_PATH . '/lib/SMSGateway.php';
require_once BASE_PATH . '/lib/AuditLogger.php';

$options = getopt('', ['limit:', 'once']);
$limit = (int)($options['limit'] ?? 20);
$runOnce = isset($options['once']);

echo "[" . date('Y-m-d H:i:s') . "] Print Worker started\n";

$printQueue = PrintQueue::getInstance();
$processed = 0;

while (true) {
    if ($printQueue->isPaused()) {
        echo "[" . date('Y-m-d H:i:s') . "] Print queue is paused, waiting...\n";
        sleep(10);
        continue;
    }

    $batch = $printQueue->getQueue($limit, 0);
    if (empty($batch)) {
        echo "[" . date('Y-m-d H:i:s') . "] Queue empty, " . ($runOnce ? "exiting" : "waiting...") . "\n";
        if ($runOnce) break;
        sleep(5);
        continue;
    }

    foreach ($batch as $item) {
        $success = $printQueue->processDocument($item['document_id']);
        if ($success) {
            echo "  ✓ Printed document: {$item['control_number']}\n";
        } else {
            echo "  ✗ Failed to print: {$item['control_number']}\n";
        }
        $processed++;
    }

    if ($runOnce) break;
    sleep(1);
}

echo "[" . date('Y-m-d H:i:s') . "] Print Worker stopped. Processed: {$processed}\n";
