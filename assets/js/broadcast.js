/*
 * Broadcast Manager Frontend JavaScript
 *
 * Features:
 *   - Interactive category selection with dynamic fields
 *   - Real-time character counter and credit calculation
 *   - Merge tag insertion
 *   - Template loading
 *   - Audience targeting with live recipient count
 *   - Two-step confirmation modal
 *   - Real-time delivery status tracking
 *   - JSON diff viewer for broadcast details
 */

let currentCategory = 'CUSTOM';
let currentMessage = '';
let broadcastId = null;
let totalRecipients = 0;

// ============================================================
// Category Selection
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    const chips = document.querySelectorAll('.category-chip');
    chips.forEach(chip => {
        chip.addEventListener('click', function() {
            chips.forEach(c => c.classList.remove('active'));
            this.classList.add('active');
            currentCategory = this.getAttribute('data-category');
            updateDynamicFields();
        });
    });

    // Set default category
    const defaultChip = document.querySelector('.category-chip[data-category="CUSTOM"]');
    if (defaultChip) defaultChip.classList.add('active');

    // Initialize message composer
    const composer = document.getElementById('messageComposer');
    if (composer) {
        composer.addEventListener('input', function() {
            currentMessage = this.textContent || '';
            updateCharCounter();
            updateSummary();
        });

        // Handle paste to strip formatting
        composer.addEventListener('paste', function(e) {
            e.preventDefault();
            document.execCommand('insertText', false, e.detail || e.clipboardData.getData('text/plain'));
        });

        // Placeholder handling
        composer.addEventListener('focus', function() {
            if (this.textContent === '') {
                this.dataset.placeholder = '';
            }
        });
        composer.addEventListener('blur', function() {
            if (this.textContent === '') {
                this.dataset.placeholder = this.getAttribute('placeholder') || 'Type your message here...';
            }
        });

        // Initialize placeholder
        composer.dataset.placeholder = composer.getAttribute('placeholder') || 'Type your message here...';
    }

    // Title input
    const titleInput = document.getElementById('broadcastTitle');
    if (titleInput) {
        titleInput.addEventListener('input', updateSummary);
    }

    // Audience scope change
    const scopeRadios = document.querySelectorAll('input[name="scope"]');
    scopeRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            updateAudienceSections();
            updateRecipientCount();
        });
    });

    // Purok checkboxes
    const purokCheckboxes = document.querySelectorAll('input[name="puroks[]"]');
    purokCheckboxes.forEach(cb => {
        cb.addEventListener('change', updateRecipientCount);
    });

    // Sector checkboxes
    const sectorCheckboxes = document.querySelectorAll('input[name="sectors[]"]');
    sectorCheckboxes.forEach(cb => {
        cb.addEventListener('change', updateRecipientCount);
    });
});

// ============================================================
// Dynamic Fields
// ============================================================
function updateDynamicFields() {
    const fields = document.getElementById('dynamicFields');
    if (!fields) return;

    // Hide all dynamic fields
    const sections = fields.querySelectorAll('div[id$="Fields"]');
    sections.forEach(s => {
        if (currentCategory === 'ASSEMBLY') {
            document.getElementById('assemblyFields')?.style.setProperty('display', 'block');
        } else if (currentCategory === 'HEALTH') {
            document.getElementById('healthFields')?.style.setProperty('display', 'block');
        } else if (currentCategory === 'EMERGENCY') {
            document.getElementById('evacuationFields')?.style.setProperty('display', 'block');
        } else {
            s.style.display = 'none';
        }
    });

    // Auto-generate message based on category
    autoGenerateMessage();
}

function autoGenerateMessage() {
    const composer = document.getElementById('messageComposer');
    if (!composer) return;

    // Don't auto-generate if user has already typed a message
    if (composer.textContent.trim() !== '' && currentMessage.trim() !== '') {
        return;
    }

    const templates = {
        'EMERGENCY': 'ATTENTION [First_Name]: Severe weather warning for Purok [Purok]. Proceed to evacuation center: [Evacuation_Center]. Stay safe. -Brgy. Bidduang',
        'ASSEMBLY': 'Dear [First_Name], you are invited to the Barangay Assembly on [Meeting_Date] at [Meeting_Time] at [Meeting_Venue]. Your participation is important.',
        'HEALTH': 'FREE MEDICAL MISSION on [Meeting_Date] at [Meeting_Time]. Open to [sector] residents of Purok [Purok]. Services: consultation, basic check-up, and free medicines.',
        'CUSTOM': '',
    };

    composer.textContent = templates[currentCategory] || '';
    currentMessage = composer.textContent;

    // Apply saved values to merge tags
    applyMergeTagValues();
    updateCharCounter();
    updateSummary();
}

function applyMergeTagValues() {
    let msg = currentMessage;

    // Assembly fields
    const meetingDate = document.getElementById('meetingDate')?.value;
    const meetingTime = document.getElementById('meetingTime')?.value;
    const meetingVenue = document.getElementById('meetingVenue')?.value;
    const evacCenter = document.getElementById('evacCenter')?.value;
    const healthSector = document.getElementById('healthSector')?.value;

    if (meetingDate) msg = msg.replace(/\[Meeting_Date\]/g, meetingDate);
    if (meetingTime) msg = msg.replace(/\[Meeting_Time\]/g, meetingTime);
    if (meetingVenue) msg = msg.replace(/\[Meeting_Venue\]/g, meetingVenue || 'Barangay Hall');
    if (evacCenter) msg = msg.replace(/\[Evacuation_Center\]/g, evacCenter || 'Brgy. Multi-Purpose Hall');
    if (healthSector && healthSector !== 'all') {
        const sectorLabels = {
            'senior': 'Senior Citizens',
            'pwd': 'PWDs',
            'youth': 'Youth/SK',
            '4ps': '4Ps Beneficiaries',
        };
        msg = msg.replace(/\[sector\]/g, sectorLabels[healthSector] || 'all');
    }

    document.getElementById('messageComposer').textContent = msg;
    currentMessage = msg;
}

// ============================================================
// Audience Targeting
// ============================================================
function updateAudienceSections() {
    const scope = document.querySelector('input[name="scope"]:checked')?.value;
    const purokSection = document.getElementById('purokSection');
    const sectorSection = document.getElementById('sectorSection');

    if (purokSection) {
        purokSection.style.display = (scope === 'purok') ? 'block' : 'none';
    }
    if (sectorSection) {
        sectorSection.style.display = (scope === 'sector') ? 'block' : 'none';
    }
}


async function updateRecipientCount() {
    const scope = document.querySelector('input[name="scope"]:checked')?.value || 'all';
    let count = 0;
    let filter = { scope: scope };

    if (scope === 'purok') {
        const checked = document.querySelectorAll('input[name="puroks[]"]:checked');
        filter.puroks = Array.from(checked).map(c => parseInt(c.value));
    } else if (scope === 'sector') {
        const checked = document.querySelectorAll('input[name="sectors[]"]:checked');
        filter.sectors = Array.from(checked).map(c => c.value);
    }

    const badge = document.getElementById('recipientBadge');
    if (badge) {
        badge.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Calculating...';
    }

    try {
        const data = new URLSearchParams();
        data.append('action', 'get_audience');
        data.append('audience_filter', JSON.stringify(filter));

        const response = await fetch('api/broadcast-api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: data
        });

        const result = await response.json();

        if (result.success) {
            count = result.recipient_count || 0;
        }
    } catch (error) {
        console.error('Audience count error:', error);
    }

    totalRecipients = count;
    if (badge) {
        badge.textContent = 'Selected Audience: ' + count.toLocaleString() + ' Residents';
        if (count === 0) {
            badge.style.background = '#dc3545';
        } else {
            badge.style.background = '#0d6efd';
        }
    }

    updateSummary();
}

// ============================================================
// Character Counter & Credit Calculation
// ============================================================
function updateCharCounter() {
    const composer = document.getElementById('messageComposer');
    const char = currentMessage || (composer?.textContent || '');
    const length = char.length;
    const maxLen = 1600; // Max for 10 SMS segments
    const segments = Math.max(1, Math.ceil(length / 153));
    const remaining = Math.max(0, 153 - (length % 153 || 153));
    const isOverLimit = length > maxLen;

    // Update counter text
    const charCount = document.getElementById('charCount');
    if (charCount) {
        charCount.textContent = isOverLimit
            ? `${length} / 1600 (MAX EXCEEDED)`
            : `${length} / 160${length > 160 ? ' (Multi-part: ' + segments + ' segments)' : ''}`;
    }

    // Update progress bar
    const progressBar = document.getElementById('charProgressBar');
    if (progressBar) {
        const percent = Math.min(100, (length / 160) * 100);
        progressBar.style.width = percent + '%';
        progressBar.className = 'char-progress-bar ' + (percent < 70 ? 'good' : percent < 90 ? 'warning' : 'danger');
    }

    // Update credit badge
    const creditBadge = document.getElementById('creditBadge');
    if (creditBadge) {
        creditBadge.textContent = `${segments} Credit${segments > 1 ? 's' : ''}`;
    }

    updateSummary();
}

// ============================================================
// Summary Update
// ============================================================
function updateSummary() {
    const title = document.getElementById('broadcastTitle')?.value || '';
    let message = currentMessage || document.getElementById('messageComposer')?.textContent || '';

    // Apply merge tag values
    applyMergeTagValues();
    message = currentMessage || message;

    const segmentCount = Math.max(1, Math.ceil(message.length / 153));
    const costPerSms = segmentCount; // 1 credit per segment
    const totalCredits = costPerSms * totalRecipients;
    const estimatedCost = totalCredits * 1.0; // Assuming 1 credit = 1 peso

    // Update summary fields
    setElementText('sumCategory', getCategoryLabel(currentCategory));
    setElementText('sumRecipients', totalRecipients.toLocaleString());
    setElementText('sumCreditsPerSms', `${segmentCount} credit${segmentCount > 1 ? 's' : ''}`);
    setElementText('sumTotalCredits', totalCredits.toLocaleString());
    setElementText('sumEstimatedCost', `₱${estimatedCost.toFixed(2)}`);

    // Update modal fields
    setElementText('modalCategory', getCategoryLabel(currentCategory));
    setElementText('modalRecipients', totalRecipients.toLocaleString());
    setElementText('modalCredits', `${segmentCount} credit${segmentCount > 1 ? 's' : ''}`);
    setElementText('modalTotalCredits', totalCredits.toLocaleString());
    setElementText('modalCost', `₱${estimatedCost.toFixed(2)}`);
    setElementText('scheduleRecipients', totalRecipients.toLocaleString());
    setElementText('scheduleCost', `₱${estimatedCost.toFixed(2)}`);

    // Update preview
    const preview = document.getElementById('modalPreview');
    if (preview) {
        preview.textContent = message || '(empty message)';
    }
}

function setElementText(id, text) {
    const el = document.getElementById(id);
    if (el) el.textContent = text;
}

function getCategoryLabel(category) {
    const labels = {
        'EMERGENCY': '🚨 Emergency',
        'ASSEMBLY': '📢 Assembly',
        'HEALTH': '💉 Health Mission',
        'CUSTOM': '✏️ Custom',
    };
    return labels[category] || 'Custom';
}

// ============================================================
// Merge Tags
// ============================================================
function insertMergeTag(tag) {
    const composer = document.getElementById('messageComposer');
    if (!composer) return;

    const selection = window.getSelection();
    if (!selection.rangeCount) return;

    const range = selection.getRangeAt(0);
    const span = document.createElement('span');
    span.textContent = tag;
    range.deleteContents();
    range.insertNode(span);

    // Move cursor after the inserted tag
    range.setStartAfter(span);
    range.setEndAfter(span);
    selection.removeAllRanges();
    selection.addRange(range);

    // Trigger input event to update counters
    composer.dispatchEvent(new Event('input'));
}

// ============================================================
// Template Loading
// ============================================================
document.addEventListener('DOMContentLoaded', function() {
    dismissToasts();
    const templateSelect = document.getElementById('templateSelect');
    if (templateSelect) {
        templateSelect.addEventListener('change', function() {
            const option = this.options[this.selectedIndex];
            if (!option.value) return;

            const template = option.getAttribute('data-template');
            if (template) {
                const composer = document.getElementById('messageComposer');
                if (composer) {
                    composer.textContent = template;
                    currentMessage = template;
                    updateCharCounter();
                    updateSummary();
                }
            }

            // Reset to default option
            this.value = '';
        });
    }
});

// ============================================================
// Modal Operations
// ============================================================
function openSendModal() {
    const title = document.getElementById('broadcastTitle')?.value || 'Untitled';
    const message = currentMessage || document.getElementById('messageComposer')?.textContent || '';

    if (!message.trim()) {
        alert('Please enter a message before sending.');
        return;
    }

    applyMergeTagValues(); // Apply merge tag values before showing preview
    updateSummary();

    openModal('sendModal');
}

function openScheduleModal() {
    const message = currentMessage || document.getElementById('messageComposer')?.textContent || '';

    if (!message.trim()) {
        alert('Please enter a message before scheduling.');
        return;
    }

    applyMergeTagValues();
    updateSummary();

    openModal('scheduleModal');
}

function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) modal.classList.add('show');
    const overlay = document.getElementById('modalOverlay');
    if (overlay) overlay.classList.add('show');
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) modal.classList.remove('show');
    // Hide overlay only if no other modal is open
    const overlay = document.getElementById('modalOverlay');
    if (overlay && !document.querySelector('.modal.show')) {
        overlay.classList.remove('show');
    }
}

// Close modals when clicking the overlay or outside the modal box
document.addEventListener('click', function(e) {
    if (e.target.id === 'modalOverlay') {
        document.querySelectorAll('.modal.show').forEach(m => m.classList.remove('show'));
        const overlay = document.getElementById('modalOverlay');
        if (overlay) overlay.classList.remove('show');
    } else if (e.target.classList.contains('modal')) {
        closeModal(e.target.id);
    }
});

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ============================================================
// Send & Schedule Actions
// ============================================================
async function executeSend() {
    const message = currentMessage || document.getElementById('messageComposer')?.textContent || '';
    const title = document.getElementById('broadcastTitle')?.value || 'Untitled Broadcast';

    // Build audience filter
    const scope = document.querySelector('input[name="scope"]:checked')?.value || 'all';
    let audienceFilter = { scope: scope };

    if (scope === 'purok') {
        const checked = document.querySelectorAll('input[name="puroks[]"]:checked');
        audienceFilter.puroks = Array.from(checked).map(c => parseInt(c.value));
    } else if (scope === 'sector') {
        const checked = document.querySelectorAll('input[name="sectors[]"]:checked');
        audienceFilter.sectors = Array.from(checked).map(c => c.value);
    }

    const payload = {
        action: 'send',
        category: currentCategory,
        title: title,
        message: message,
        audience_filter: JSON.stringify(audienceFilter),
    };

    const sendBtn = document.getElementById('confirmSendBtn');
    if (sendBtn) sendBtn.disabled = true;
    if (sendBtn) sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';

    try {
        const response = await fetch('api/broadcast-api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(payload),
        });

        const result = await response.json();

        if (result.success) {
            broadcastId = result.broadcast_id;
            closeModal('sendModal');
            showTransmissionStatus(broadcastId);
            window.location.href = 'broadcast.php?msg=sent';
        } else {
            alert('Error: ' + result.message);
            if (sendBtn) sendBtn.disabled = false;
            if (sendBtn) sendBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Confirm & Send';
        }
    } catch (error) {
        alert('Network error: ' + error.message);
        if (sendBtn) sendBtn.disabled = false;
        if (sendBtn) sendBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Confirm & Send';
    }
}

async function executeSchedule() {
    const message = currentMessage || document.getElementById('messageComposer')?.textContent || '';
    const title = document.getElementById('broadcastTitle')?.value || 'Untitled Broadcast';
    const scheduleTime = document.getElementById('scheduleDateTime')?.value;

    if (!scheduleTime) {
        alert('Please select a date and time.');
        return;
    }

    const scope = document.querySelector('input[name="scope"]:checked')?.value || 'all';
    let audienceFilter = { scope: scope };

    if (scope === 'purok') {
        const checked = document.querySelectorAll('input[name="puroks[]"]:checked');
        audienceFilter.puroks = Array.from(checked).map(c => parseInt(c.value));
    } else if (scope === 'sector') {
        const checked = document.querySelectorAll('input[name="sectors[]"]:checked');
        audienceFilter.sectors = Array.from(checked).map(c => c.value);
    }

    const payload = {
        action: 'schedule',
        category: currentCategory,
        title: title,
        message: message,
        audience_filter: JSON.stringify(audienceFilter),
        scheduled_at: scheduleTime.replace('T', ' ') + ':00',
    };

    try {
        const response = await fetch('api/broadcast-api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(payload),
        });

        const result = await response.json();

        if (result.success) {
            closeModal('scheduleModal');
            window.location.href = 'broadcast.php?msg=scheduled';
        } else {
            alert('Error: ' + result.message);
        }
    } catch (error) {
        alert('Network error: ' + error.message);
    }
}

// ============================================================
// Real-time Transmission Status
// ============================================================
async function showTransmissionStatus(bid) {
    const section = document.getElementById('transmissionSection');
    if (section) section.style.display = 'block';

    pollStatus(bid);
}

let pollInterval = null;

function pollStatus(bid) {
    if (pollInterval) clearInterval(pollInterval);

    pollInterval = setInterval(async function() {
        try {
            const response = await fetch(`api/broadcast-api.php?action=get_status&broadcast_id=${bid}`);
            const result = await response.json();

            if (result.success && result.status) {
                const s = result.status;
                const total = parseInt(s.total_deliveries) || 0;
                const sent = parseInt(s.sent) || 0;
                const delivered = parseInt(s.delivered) || 0;
                const failed = parseInt(s.failed) || 0;
                const pending = total - sent - failed;

                document.getElementById('statPending').textContent = pending;
                document.getElementById('statSent').textContent = sent;
                document.getElementById('statDelivered').textContent = delivered;
                document.getElementById('statFailed').textContent = failed;

                const progressPercent = total > 0 ? ((sent + delivered + failed) / total) * 100 : 0;
                const progressBar = document.getElementById('statusProgressBar');
                if (progressBar) {
                    progressBar.style.width = progressPercent + '%';
                }
                document.getElementById('progressText').textContent = `${sent + delivered} / ${total} messages processed`;
            }
        } catch (error) {
            console.error('Status poll error:', error);
        }
    }, 3000); // Poll every 3 seconds
}

// ============================================================
// Initialize audience on page load
// ============================================================
setTimeout(function() {
    updateRecipientCount();
    updateSummary();
}, 100);

// Also update recipient count when scope changes
document.addEventListener('change', function(e) {
    if (e.target.name === 'scope' || e.target.name === 'puroks[]' || e.target.name === 'sectors[]') {
        updateRecipientCount();
    }
});

// Update merge tag values when dynamic fields change
document.addEventListener('input', function(e) {
    if (e.target.id === 'meetingDate' || e.target.id === 'meetingTime' ||
        e.target.id === 'meetingVenue' || e.target.id === 'evacCenter' ||
        e.target.id === 'healthSector') {
        applyMergeTagValues();
        updateCharCounter();
        updateSummary();
    }
});

// ============================================================
// Template Manager
// ============================================================
function openTemplateManager() {
    openModal('templateManagerModal');
    loadTemplateList();
}

async function loadTemplateList() {
    const list = document.getElementById('tplList');
    if (!list) return;
    list.innerHTML = '<div style="padding:10px;color:var(--text-muted);"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
    try {
        const response = await fetch('api/broadcast-api.php?action=get_templates');
        const result = await response.json();
        if (!result.success) {
            list.innerHTML = '<div style="padding:10px;color:var(--danger);">Failed to load templates.</div>';
            return;
        }
        if (!result.templates.length) {
            list.innerHTML = '<div style="padding:10px;color:var(--text-muted);">No templates yet. Add one above.</div>';
            return;
        }
        list.innerHTML = '';
        result.templates.forEach(t => {
            const row = document.createElement('div');
            row.style.cssText = 'padding:10px;border-bottom:1px solid var(--border,#e5e7eb);display:flex;justify-content:space-between;align-items:flex-start;gap:10px;';
            const left = document.createElement('div');
            left.style.cssText = 'flex:1;min-width:0;';
            left.innerHTML = `<strong>${escapeHtml(t.name)}</strong>
                <span class="badge" style="margin-left:6px;font-size:11px;color:var(--secondary);">${escapeHtml(t.category)}</span>
                <div style="font-size:12px;color:var(--text-muted);margin-top:4px;white-space:pre-wrap;">${escapeHtml(t.message_template)}</div>`;
            const right = document.createElement('div');
            right.style.cssText = 'display:flex;gap:6px;flex-shrink:0;';
            right.innerHTML = `
                <button type="button" class="btn btn-sm btn-outline" onclick="useTemplate(${t.id})"><i class="fas fa-download"></i> Load</button>
                <button type="button" class="btn btn-sm btn-outline" style="color:var(--danger);" onclick="deleteTemplate(${t.id})"><i class="fas fa-trash"></i></button>`;
            row.appendChild(left);
            row.appendChild(right);
            list.appendChild(row);
        });
    } catch (err) {
        list.innerHTML = '<div style="padding:10px;color:var(--danger);">Error loading templates.</div>';
    }
}

async function saveTemplate() {
    const name = document.getElementById('tplName').value.trim();
    const category = document.getElementById('tplCategory').value;
    const subject = document.getElementById('tplSubject').value.trim();
    const message = document.getElementById('tplMessage').value.trim();
    const msg = document.getElementById('tplSaveMsg');
    msg.textContent = '';

    if (!name || !message) {
        msg.style.color = 'var(--danger)';
        msg.textContent = 'Name and message are required.';
        return;
    }

    const data = new URLSearchParams();
    data.append('action', 'create_template');
    data.append('name', name);
    data.append('category', category);
    data.append('subject', subject);
    data.append('message_template', message);

    try {
        const response = await fetch('api/broadcast-api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: data
        });
        const result = await response.json();
        if (result.success) {
            msg.style.color = 'var(--success)';
            msg.textContent = 'Saved!';
            document.getElementById('tplName').value = '';
            document.getElementById('tplSubject').value = '';
            document.getElementById('tplMessage').value = '';
            loadTemplateList();
            refreshMainTemplateDropdown();
        } else {
            msg.style.color = 'var(--danger)';
            msg.textContent = result.message || 'Save failed.';
        }
    } catch (err) {
        msg.style.color = 'var(--danger)';
        msg.textContent = 'Network error.';
    }
}

async function useTemplate(id) {
    try {
        const response = await fetch('api/broadcast-api.php?action=get_templates');
        const result = await response.json();
        if (!result.success) return;
        const t = result.templates.find(x => x.id == id);
        if (!t) return;
        const composer = document.getElementById('messageComposer');
        if (composer) {
            composer.textContent = t.message_template;
            currentMessage = t.message_template;
        }
        const title = document.getElementById('broadcastTitle');
        if (title && t.subject) title.value = t.subject;
        // set category chip if present
        const chip = document.querySelector(`.category-chip[data-category="${t.category}"]`);
        if (chip) chip.click();
        updateCharCounter();
        updateSummary();
        closeModal('templateManagerModal');
    } catch (err) { /* ignore */ }
}

async function deleteTemplate(id) {
    if (!confirm('Delete this template?')) return;
    const data = new URLSearchParams();
    data.append('action', 'delete_template');
    data.append('template_id', id);
    try {
        const response = await fetch('api/broadcast-api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: data
        });
        const result = await response.json();
        if (result.success) {
            loadTemplateList();
        } else {
            alert(result.message || 'Delete failed.');
        }
    } catch (err) {
        alert('Network error.');
    }
}


function refreshMainTemplateDropdown() {
    const sel = document.getElementById('templateSelect');
    if (!sel) return;
    fetch('api/broadcast-api.php?action=get_templates')
        .then(r => r.json())
        .then(result => {
            if (!result.success) return;
            const current = sel.value;
            sel.innerHTML = '<option value=""><i class="fas fa-folder-open"></i> Load Template...</option>';
            result.templates.forEach(t => {
                const opt = document.createElement('option');
                opt.value = t.id;
                opt.setAttribute('data-category', t.category);
                opt.setAttribute('data-template', t.message_template);
                opt.textContent = t.name;
                sel.appendChild(opt);
            });
            sel.value = current;
        })
        .catch(() => {});
}

function dismissToasts() {
    document.querySelectorAll('.toast-alert').forEach(function(el) {
        setTimeout(function() {
            el.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
            el.style.opacity = '0';
            el.style.transform = 'translateY(-20px)';
            setTimeout(function() { el.remove(); }, 400);
        }, 3000);
    });
}

window.showToast = function(message, type) {
    type = type || 'success';
    const el = document.createElement('div');
    el.className = 'toast-alert toast-' + type + ' no-print';
    const icon = type === 'success' ? 'fa-check' : (type === 'danger' ? 'fa-exclamation' : 'fa-info-circle');
    el.innerHTML = '<i class="fas ' + icon + '"></i> <span>' + message + '</span>';
    document.body.appendChild(el);
    setTimeout(function() {
        el.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
        el.style.opacity = '0';
        el.style.transform = 'translateY(-20px)';
        setTimeout(function() { el.remove(); }, 400);
    }, 3000);
};

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// Ensure toasts are always direct children of <body> so that
// position:fixed pins them to the viewport (an ancestor with
// transform/filter would otherwise break fixed positioning).
(function bootstrapToasts() {
    function reparentToasts() {
        document.querySelectorAll('.toast-alert').forEach(function (el) {
            if (el.parentNode !== document.body) {
                document.body.appendChild(el);
            }
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', reparentToasts);
    } else {
        reparentToasts();
    }
})();
