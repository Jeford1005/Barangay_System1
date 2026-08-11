<?php
/**
 * broadcast.php - Interactive Broadcast Management GUI
 * Role: admin, staff
 *
 * Features:
 *   - Category & preset selector (Emergency/Assembly/Health/Custom)
 *   - Dynamic message composer with character counter and merge tags
 *   - Template loader
 *   - Audience targeting (All Residents / By Purok / By Sector)
 *   - Live recipient counter
 *   - Two-step confirmation modal
 *   - Real-time transmission status and broadcast history
 */

require_once 'config.php';
require_once 'lib/SMSGateway.php';
require_once 'lib/AuditLogger.php';

require_auth();
require_role(['admin', 'staff']);

$currentUser = current_user();
$message = '';

// Handle flash messages
if (isset($_GET['msg'])) {
    $messages = [
        'sent' => '<div class="toast-alert toast-success no-print"><i class="fas fa-check"></i> Broadcast sent successfully!</div>',
        'scheduled' => '<div class="toast-alert toast-success no-print"><i class="fas fa-check"></i> Broadcast scheduled successfully!</div>',
        'cancelled' => '<div class="toast-alert toast-info no-print"><i class="fas fa-info"></i> Broadcast cancelled.</div>',
        'error' => '<div class="toast-alert toast-danger no-print"><i class="fas fa-exclamation"></i> An error occurred.</div>',
    ];
    $message = $messages[$_GET['msg']] ?? $messages['error'];
}

// Get purok list for targeting
$stmt = $pdo->query("SELECT id, purok_name FROM puroks ORDER BY zone_number");
$puroks = $stmt->fetchAll();

// Get templates
$stmt = $pdo->query("SELECT id, name, category, subject, message_template FROM broadcast_templates WHERE is_active = 1 ORDER BY category, name");
$templates = $stmt->fetchAll();
?>
<?php
// Detect SMS simulation mode (no real provider credentials configured)
$smsSimulation = false;
try {
    if (class_exists('SMSGateway')) {
        $gw = SMSGateway::getInstance();
        $smsSimulation = $gw->isSimulationMode();
    }
} catch (Throwable $e) { $smsSimulation = true; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="assets/img/Brgy_Bidduang.png">
    <link rel="shortcut icon" type="image/png" href="assets/img/Brgy_Bidduang.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Broadcast Manager - Barangay Bidduang Portal</title>
    <link rel="stylesheet" href="assets/css/dashboard.css?v=<?= filemtime(__DIR__ . '/assets/css/dashboard.css') ?>">
    <link rel="stylesheet" href="assets/css/fontawesome.min.css?v=<?= filemtime(__DIR__ . '/assets/css/fontawesome.min.css') ?>">
    <style>
        .broadcast-manager { display: grid; grid-template-columns: 350px 1fr; gap: 20px; }
        .category-chips { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 15px; }
        .category-chip {
            padding: 15px; text-align: center; border-radius: 10px; cursor: pointer;
            border: 2px solid transparent; transition: all 0.2s; border-radius: 12px;
            background: #f8f9fa; font-weight: 600; display: flex; flex-direction: column; gap: 5px;
        }
        .category-chip:hover { transform: translateY(-2px); }
        .category-chip.active { border-color: #0d6efd; box-shadow: 0 0 15px rgba(13,110,253,0.25); }
        .category-chip .icon { font-size: 24px; margin-bottom: 5px; }
        .category-emergency { background: linear-gradient(135deg, #fff5f5, #ffe0e0); border-color: #dc3545; }
        .category-emergency.active { background: linear-gradient(135deg, #f8d7da, #f5c2c7); }
        .category-emergency .icon { color: #dc3545; }
        .category-assembly { background: linear-gradient(135deg, #e7f1ff, #cce4ff); border-color: #0d6efd; }
        .category-assembly.active { background: linear-gradient(135deg, #cce4ff, #99d0ff); }
        .category-assembly .icon { color: #0d6efd; }
        .category-health { background: linear-gradient(135deg, #e6f9ec, #c6f0d6); border-color: #198754; }
        .category-health.active { background: linear-gradient(135deg, #c6f0d6, #9ce8af); }
        .category-health .icon { color: #198754; }
        .category-custom { background: linear-gradient(135deg, #f3f4f6, #e5e7eb); border-color: #6c757d; }
        .category-custom.active { background: linear-gradient(135deg, #e5e7eb, #d1d5db); }
        .category-custom .icon { color: #6c757d; }

        .message-composer { position: relative; }
        .composer-toolbar {
            display: flex; gap: 8px; padding: 8px; background: #f8f9fa; border-radius: 8px; margin-bottom: 8px;
            flex-wrap: wrap; align-items: center;
        }
        .composer-toolbar select { padding: 5px 10px; border: 1px solid #ddd; border-radius: 5px; }
        .merge-tag-btn {
            padding: 5px 10px; background: #e9ecef; border: 1px solid #ddd; border-radius: 5px;
            cursor: pointer; font-size: 12px; white-space: nowrap;
        }
        .merge-tag-btn:hover { background: #dee2e6; }
        #messageComposer {
            width: 100%; min-height: 180px; padding: 12px; border: 2px dashed #ddd;
            border-radius: 8px; font-size: 14px; resize: vertical;
        }
        .char-counter {
            display: flex; justify-content: space-between; align-items: center;
            margin-top: 8px; font-size: 13px;
        }
        .char-progress { flex: 1; height: 6px; background: #e9ecef; border-radius: 3px; overflow: hidden; margin: 0 10px; }
        .char-progress-bar { height: 100%; border-radius: 3px; transition: width 0.3s; }
        .char-progress-bar.good { background: #28a745; }
        .char-progress-bar.warning { background: #ffc107; }
        .char-progress-bar.danger { background: #dc3545; }
        .credit-badge { background: #e9ecef; padding: 3px 10px; border-radius: 12px; font-weight: 600; }

        .audience-panel, .dynamic-fields { margin-top: 15px; }
        .audience-section {
            border: 1px solid #e0e0e0; border-radius: 8px; padding: 12px; margin-bottom: 12px;
        }
        .audience-section h4 { margin: 0 0 10px 0; font-size: 14px; color: #495057; }
        .audience-options { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 8px; }
        .audience-options label { display: flex; align-items: center; gap: 6px; cursor: pointer; }
        .recipient-badge {
            display: inline-block; background: #0d6efd; color: white; padding: 5px 15px;
            border-radius: 20px; font-weight: 600; margin-top: 8px;
        }
        .dynamic-field-group { margin-bottom: 10px; }
        .dynamic-field-group label { font-size: 12px; color: #6c757d; margin-bottom: 2px; display: block; }
        .dynamic-field-group input[type="text"], .dynamic-field-group input[type="date"], .dynamic-field-group input[type="time"] {
            width: 100%; padding: 6px 10px; border: 1px solid #ddd; border-radius: 5px; font-size: 13px;
        }

        .send-confirmation { margin-top: 15px; padding-top: 15px; border-top: 1px solid #e0e0e0; }
        .confirm-summary { background: #f8f9fa; border-radius: 8px; padding: 15px; margin-bottom: 15px; }
        .confirm-summary table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .confirm-summary td { padding: 5px 0; border-bottom: 1px solid #e9ecef; }
        .confirm-summary td:first-child { color: #6c757d; }
        .confirm-summary td:last-child { font-weight: 600; }

        .transmission-status { margin-top: 20px; }
        .status-progress { height: 8px; background: #e9ecef; border-radius: 4px; overflow: hidden; margin: 10px 0; }
        .status-progress-bar { height: 100%; border-radius: 4px; transition: width 0.5s ease; }
        .stats-bar { display: flex; gap: 20px; margin: 10px 0; flex-wrap: wrap; }
        .stat-item { flex: 1; text-align: center; padding: 12px; background: #f8f9fa; border-radius: 8px; }
        .stat-number { font-size: 24px; font-weight: 700; }
        .stat-label { font-size: 12px; color: #6c757d; margin-top: 4px; }
        .stat-sent { .stat-number { color: #198754; } }
        .stat-delivered { .stat-number { color: #0d6efd; } }
        .stat-failed { .stat-number { color: #dc3545; } }
        .stat-pending { .stat-number { color: #ffc107; } }


        .status-badge {
            display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 600;
        }
        .badge-queued { background: #e2e3e5; color: #495057; }
        .badge-sending { background: #cce5ff; color: #0d6efd; }
        .badge-completed { background: #d1e7dd; color: #198754; }
        .badge-failed { background: #f8d7da; color: #dc3545; }
        .badge-cancelled { background: #f8d7da; color: #dc3545; }
        .badge-scheduled { background: #fff3cd; color: #856404; }

        .modal {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.5); display: none; align-items: center;
            justify-content: center; z-index: 9999;
        }
        .modal.show { display: flex; }
        .modal-content {
            background: white; border-radius: 12px; max-width: 700px; width: 90%;
            max-height: 90vh; overflow-y: auto; box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        .modal-header { padding: 20px 25px; border-bottom: 1px solid #e0e0e0; }
        .modal-body { padding: 20px 25px; }
        .modal-footer { padding: 15px 25px; border-top: 1px solid #e0e0e0; }
        .json-diff { background: #f8f9fa; border: 1px solid #e0e0e0; border-radius: 6px; padding: 12px; font-family: monospace; font-size: 12px; max-height: 200px; overflow-y: auto; margin: 10px 0; white-space: pre-wrap; word-break: break-all; }

        @media (max-width: 768px) {
            .broadcast-manager { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="app">
    <!-- Sidebar -->
    <?php include __DIR__ . '/views/sidebar.php'; ?>

    <main class="main-content">
        <div class="page-header">
            <div>
                <h1><i class="fas fa-tower-broadcast"></i> Broadcast Manager</h1>
                <p>Create and send SMS broadcasts to residents</p>
            </div>
        </div>

        <?= $message ?>

        <div class="card">
            <div class="card-header">
                <h2>Broadcast Creator</h2>
            <?php if (!empty($smsSimulation)): ?>
            <div class="alert alert-warning" style="margin-bottom: 16px; padding: 10px 14px; border-radius: 8px; background: #fff7e6; border: 1px solid #ffd591; color: #874d00; font-size: 13px;">
                <i class="fas fa-info-circle"></i> <strong>Demo mode:</strong> no live SMS gateway is configured, so messages are marked <em>delivered</em> in the system but are <strong>not sent to real phones</strong>. To send real SMS, add Semaphore / ItexMo / Twilio credentials in <code>gateway_credentials</code> (or set <code>SEMAPHORE_API_KEY</code>) and run <code>php bin/broadcast-worker.php</code> as a daemon.
            </div>
            <?php endif; ?>
            </div>
            <div class="card-body">
                <div class="broadcast-manager">
                    <!-- Left Panel: Creator -->
                    <div>
                        <!-- Category Selector -->
                        <div class="category-chips">
                        <div class="category-chip category-emergency" data-category="EMERGENCY">
                            <div class="icon"><i class="fas fa-warning"></i></div>
                            <div>Emergency</div>
                        </div>
                        <div class="category-chip category-assembly" data-category="ASSEMBLY">
                            <div class="icon"><i class="fas fa-users"></i></div>
                            <div>Assembly</div>
                        </div>
                        <div class="category-chip category-health" data-category="HEALTH">
                            <div class="icon"><i class="fas fa-heartbeat"></i></div>
                            <div>Health Mission</div>
                        </div>
                        <div class="category-chip category-custom" data-category="CUSTOM">
                            <div class="icon"><i class="fas fa-pen-to-square"></i></div>
                            <div>Custom</div>
                        </div>
                        </div>

                        <!-- Dynamic Fields -->
                        <div id="dynamicFields" class="dynamic-fields">
                            <div id="assemblyFields" style="display:none;">
                                <div class="dynamic-field-group">
                                    <label>Meeting Date</label>
                                    <input type="date" id="meetingDate" class="form-control">
                                </div>
                                <div class="dynamic-field-group">
                                    <label>Meeting Time</label>
                                    <input type="time" id="meetingTime" class="form-control">
                                </div>
                                <div class="dynamic-field-group">
                                    <label>Venue</label>
                                    <input type="text" id="meetingVenue" class="form-control" placeholder="Barangay Hall">
                                </div>
                            </div>
                            <div id="healthFields" style="display:none;">
                                <div class="dynamic-field-group">
                                    <label>Target Sector</label>
                                    <select id="healthSector" class="form-control">
                                        <option value="all">All Residents</option>
                                        <option value="senior">Senior Citizens</option>
                                        <option value="pwd">PWDs</option>
                                        <option value="youth">Youth/SK</option>
                                        <option value="4ps">4Ps Beneficiaries</option>
                                    </select>
                                </div>
                            </div>
                            <div id="evacuationFields" style="display:none;">
                                <div class="dynamic-field-group">
                                    <label>Evacuation Center</label>
                                    <input type="text" id="evacCenter" class="form-control" placeholder="Barangay Multi-Purpose Hall">
                                </div>
                            </div>
                        </div>

                        <!-- Message Composer -->
                        <div class="message-composer">
                            <div class="composer-toolbar">
                                <select id="templateSelect" class="form-control" style="max-width: 200px;">
                                    <option value=""><i class="fas fa-folder-open"></i> Load Template...</option>
                                    <?php foreach ($templates as $t): ?>
                                    <option value="<?= $t['id'] ?>" data-category="<?= $t['category'] ?>" data-template="<?= htmlspecialchars($t['message_template']) ?>">
                                        <?= esc($t['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="openTemplateManager()" style="margin-left:8px;">
                                    <i class="fas fa-cog"></i> Manage Templates
                                </button>

                                <button type="button" class="merge-tag-btn" onclick="insertMergeTag('[First_Name]')">FN</button>
                                <button type="button" class="merge-tag-btn" onclick="insertMergeTag('[Purok]')">PK</button>
                                <button type="button" class="merge-tag-btn" onclick="insertMergeTag('[Evacuation_Center]')">EV</button>
                                <button type="button" class="merge-tag-btn" onclick="insertMergeTag('[Meeting_Date]')">MD</button>
                                <button type="button" class="merge-tag-btn" onclick="insertMergeTag('[Meeting_Time]')">MT</button>
                            </div>

                            <textarea id="broadcastTitle" class="form-control" placeholder="Broadcast Title" style="margin-bottom: 10px;"></textarea>

                            <div contenteditable="true" id="messageComposer" placeholder="Type your message here..."></div>

                            <div class="char-counter">
                                <span id="charCount">0 / 160</span>
                                <div class="char-progress">
                                    <div class="char-progress-bar good" id="charProgressBar" style="width: 0%;"></div>
                                </div>
                                <span class="credit-badge" id="creditBadge">1 Credit</span>
                            </div>
                        </div>
                    </div>

                    <!-- Right Panel: Targeting & Send -->
                    <div>
                        <!-- Audience Targeting -->
                        <div class="audience-panel">
                            <h3><i class="fas fa-bullseye"></i> Audience Targeting</h3>

                            <div class="audience-section">
                                <h4>Targeting Options</h4>
                                <div class="audience-options">
                                    <label><input type="radio" name="scope" value="all" checked> <i class="fas fa-globe"></i> All Registered Residents</label>
                                    <label><input type="radio" name="scope" value="purok"> <i class="fas fa-map-marker-alt"></i> By Purok / Zone</label>
                                    <label><input type="radio" name="scope" value="sector"> <i class="fas fa-users"></i> By Sector / Category</label>
                                </div>
                            </div>

                            <div id="purokSection" class="audience-section" style="display:none;">
                                <h4>Purok / Zone Selection</h4>
                                <div class="audience-options">
                                    <?php foreach ($puroks as $p): ?>
                                    <label>
                                        <input type="checkbox" name="puroks[]" value="<?= $p['id'] ?>">
                                        Purok <?= esc($p['purok_name']) ?>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div id="sectorSection" class="audience-section" style="display:none;">
                                <h4>Sector Selection</h4>
                                <div class="audience-options">
                                    <label><input type="checkbox" name="sectors[]" value="senior"><i class="fas fa-user-clock"></i> Senior Citizens</label>
                                    <label><input type="checkbox" name="sectors[]" value="pwd"><i class="fas fa-wheelchair"></i> PWDs</label>
                                    <label><input type="checkbox" name="sectors[]" value="4ps"><i class="fas fa-house-user"></i> 4Ps Beneficiaries</label>
                                    <label><input type="checkbox" name="sectors[]" value="household_head"><i class="fas fa-users"></i> Household Heads</label>
                                    <label><input type="checkbox" name="sectors[]" value="youth"><i class="fas fa-graduation-cap"></i> Youth / SK</label>
                                    <label><input type="checkbox" name="sectors[]" value="indigent"><i class="fas fa-hand-holding-dollar"></i> Indigent</label>
                                </div>
                            </div>

                            <div style="margin-top: 15px;">
                                <span class="recipient-badge" id="recipientBadge">Selected Audience: 0 Residents</span>
                            </div>
                        </div>

                        <!-- Confirmation & Send -->
                        <div class="send-confirmation">
                            <h3><i class="fas fa-shield-alt"></i> Confirmation</h3>

                            <div class="confirm-summary">
                                <table>
                                    <tr><td>Category:</td><td id="sumCategory">Custom</td></tr>
                                    <tr><td>Total Recipients:</td><td id="sumRecipients">0</td></tr>
                                    <tr><td>Credits per SMS:</td><td id="sumCreditsPerSms">1</td></tr>
                                    <tr><td>Total SMS Credits:</td><td id="sumTotalCredits">0</td></tr>
                                    <tr><td>Estimated Cost:</td><td id="sumEstimatedCost">₱0.00</td></tr>
                                </table>
                            </div>

                            <div>
                                <button type="button" class="btn btn-block" style="background:#198754;" onclick="openSendModal()" id="sendNowBtn">
                                    <i class="fas fa-paper-plane"></i> Send Now
                                </button>
                                <button type="button" class="btn btn-outline" onclick="openScheduleModal()">
                                    <i class="fas fa-calendar"></i> Schedule for Later
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Transmission Status -->
        <div class="card transmission-status" id="transmissionSection" style="display:none;">
            <div class="card-header">
                <h2>Live Transmission Status</h2>
            </div>
            <div class="card-body">
                <div class="stats-bar">
                    <div class="stat-item stat-pending">
                        <div id="statPending">0</div>
                        <div class="stat-label">Pending</div>
                    </div>
                    <div class="stat-item stat-sent">
                        <div id="statSent">0</div>
                        <div class="stat-label">Sent</div>
                    </div>
                    <div class="stat-item stat-delivered">
                        <div id="statDelivered">0</div>
                        <div class="stat-label">Delivered</div>
                    </div>
                    <div class="stat-item stat-failed">
                        <div id="statFailed">0</div>
                        <div class="stat-label">Failed</div>
                    </div>
                </div>
                <div class="status-progress">
                    <div class="status-progress-bar bg-primary" id="statusProgressBar" style="width: 0%;"></div>
                </div>
                <p style="text-align: center; font-size: 13px; color: #6c757d;">
                    <span id="progressText">0 / 0</span> messages processed
                </p>
            </div>
        </div>

        
</div>

<!-- Send Confirmation Modal -->
<div class="modal" id="sendModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Confirm Broadcast</h3>
        </div>
        <div class="modal-body">
            <div class="confirm-summary">
                <table>
                    <tr><td>Category:</td><td id="modalCategory">Custom</td></tr>
                    <tr><td>Total Recipients:</td><td id="modalRecipients">0</td></tr>
                    <tr><td>Message:</td><td><div id="modalPreview" style="margin-top: 10px; padding: 10px; background: #f8f9fa; border-radius: 5px; border: 1px solid #e0e0e0; max-height: 150px; overflow-y: auto; font-size: 13px;"></div></td></tr>
                    <tr><td>Credits per SMS:</td><td id="modalCredits">1</td></tr>
                    <tr><td>Total SMS Credits:</td><td id="modalTotalCredits">0</td></tr>
                    <tr><td>Estimated Cost:</td><td id="modalCost">₱0.00</td></tr>
                </table>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('sendModal')">Cancel</button>
            <button class="btn btn-primary" onclick="executeSend()" id="confirmSendBtn">
                <i class="fas fa-paper-plane"></i> Confirm & Send
            </button>
        </div>
    </div>
</div>

<!-- Schedule Modal -->
<div class="modal" id="scheduleModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Schedule Broadcast</h3>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>Schedule Date & Time</label>
                <input type="datetime-local" id="scheduleDateTime" class="form-control" style="width: 100%;">
            </div>
            <div class="confirm-summary">
                <table>
                    <tr><td>Total Recipients:</td><td id="scheduleRecipients">0</td></tr>
                    <tr><td>Estimated Cost:</td><td id="scheduleCost">₱0.00</td></tr>
                </table>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('scheduleModal')">Cancel</button>
            <button class="btn btn-primary" onclick="executeSchedule()">
                <i class="fas fa-calendar"></i> Schedule Broadcast
            </button>
        </div>
    </div>
</div>

<!-- Shared modal backdrop dimmer -->
<div class="modal-overlay" id="modalOverlay"></div>

<!-- JSON Diff Modal -->
<div class="modal" id="jsonDiffModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Details</h3>
        </div>
        <div class="modal-body">
            <div class="json-diff" id="jsonDiffContent"></div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('jsonDiffModal')">Close</button>
        </div>
    </div>
</div>

<!-- Template Manager Modal -->
<div class="modal" id="templateManagerModal">
    <div class="modal-content" style="max-width: 640px;">
        <div class="modal-header">
            <h3><i class="fas fa-cog"></i> Manage Broadcast Templates</h3>
        </div>
        <div class="modal-body">
            <!-- Add Template Form -->
            <div class="card" style="margin-bottom: 18px;">
                <div class="card-header"><strong>Add New Template</strong></div>
                <div class="card-body">
                    <div class="form-group">
                        <label>Template Name</label>
                        <input type="text" id="tplName" class="form-control" placeholder="e.g. Fire Drill Reminder">
                    </div>
                    <div class="form-group">
                        <label>Category</label>
                        <select id="tplCategory" class="form-control">
                            <option value="EMERGENCY">Emergency</option>
                            <option value="ASSEMBLY">Assembly</option>
                            <option value="HEALTH">Health</option>
                            <option value="CUSTOM">Custom</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Subject (optional)</label>
                        <input type="text" id="tplSubject" class="form-control" placeholder="Short subject line">
                    </div>
                    <div class="form-group">
                        <label>Message</label>
                        <textarea id="tplMessage" class="form-control" rows="4" placeholder="Type your message. Use merge tags like [First_Name], [Purok], [Meeting_Date]"></textarea>
                    </div>
                    <button type="button" class="btn btn-primary" onclick="saveTemplate()">
                        <i class="fas fa-save"></i> Save Template
                    </button>
                    <span id="tplSaveMsg" style="margin-left:10px;color:var(--success);"></span>
                </div>
            </div>

            <!-- Existing Templates List -->
            <div class="card">
                <div class="card-header"><strong>Existing Templates</strong></div>
                <div class="card-body">
                    <div id="tplList" style="max-height: 280px; overflow-y: auto;"></div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="closeModal('templateManagerModal')">Close</button>
        </div>
    </div>
</div>

<script src="assets/js/broadcast.js"></script>
<script>
// Initialize with default category
document.addEventListener('DOMContentLoaded', function() {
    const defaultDate = new Date();
    defaultDate.setHours(defaultDate.getHours() + 1);
    const scheduleInput = document.getElementById('scheduleDateTime');
    if (scheduleInput) {
        const pad = (n) => n.toString().pad(0);
        const year = defaultDate.getFullYear();
        const month = pad(defaultDate.getMonth() + 1);
        const day = pad(defaultDate.getDate());
        const hours = pad(defaultDate.getHours());
        const minutes = pad(defaultDate.getMinutes());
        scheduleInput.value = `${year}-${month}-${day}T${hours}:${minutes}`;
    }
});
</script>
<script src="assets/js/main.js"></script>
</body>
</html>
