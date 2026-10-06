<?php
/**
 * Notices Module
 * Core PHP HRMS Application
 * Section 16 - Notice board module
 */

$db = Database::getInstance();
$user = getCurrentUser();

// Check permissions
if (!hasPermission('notices', 'read')) {
    echo '<div class="alert danger"><i class="bi bi-exclamation-triangle"></i> You do not have permission to view notices.</div>';
    exit;
}

// Get notices (filtered by user's role/department)
$notices = [];
try {
    $notices = $db->fetchAll(
        "SELECT n.*, e.first_name as poster_first, e.last_name as poster_last,
         (SELECT COUNT(*) FROM notice_reads WHERE notice_id = n.id AND employee_id = ?) as is_read
         FROM notices n
         JOIN employees e ON n.posted_by = e.id
         WHERE n.is_active = 1
         AND (n.expiry_date IS NULL OR n.expiry_date >= CURDATE())
         AND (n.target_type = 'all'
              OR n.target_type = 'role' AND FIND_IN_SET(?, n.target_roles) > 0
              OR n.target_type = 'department' AND FIND_IN_SET(?, n.target_departments) > 0)
         ORDER BY n.created_at DESC
         LIMIT 20",
        [$user['id'], $user['role_id'], $user['id']]
    );

    // Fetch read/acknowledged status for each notice for the current user
    foreach ($notices as &$notice) {
        $read = $db->fetchOne(
            "SELECT read_at, acknowledged_at FROM notice_reads WHERE notice_id = ? AND employee_id = ?",
            [$notice['id'], $user['id']]
        );
        $notice['read_at'] = $read ? $read['read_at'] : null;
        $notice['acknowledged_at'] = $read ? $read['acknowledged_at'] : null;
    }
} catch (Exception $e) {
    // Table might not exist or have different structure
}

// Options for the notice form (targeting)
$roleOptions = $db->fetchAll("SELECT id, name FROM roles WHERE is_active = 1 ORDER BY hierarchy_level");
$deptOptions = $db->fetchAll("SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name");
?>

<div class="panel-head"></div>

<div class="toolbar">
    <div class="filter-chip active">All</div>
    <div class="filter-chip">High priority</div>
    <div class="filter-chip">Medium</div>
    <div class="filter-chip">Low</div>
    <div class="spacer"></div>
    <div class="btn primary" onclick="openCreateNoticeModal()"><i class="bi bi-plus-lg"></i> Post Notice</div>
</div>

<?php if (empty($notices)): ?>
    <div class="panel">
        <div class="empty-state">
            <i class="bi bi-megaphone"></i>
            <p>No notices available</p>
        </div>
    </div>
<?php else: ?>
    <div class="row-2">
        <?php foreach ($notices as $notice): ?>
            <div class="panel" id="notice-card-<?php echo $notice['id']; ?>">
                <div class="panel-head">
                    <div class="panel-title"><i class="bi bi-megaphone"></i> <?php echo htmlspecialchars($notice['title']); ?></div>
                    <?php
                    $priorityClass = 'gray';
                    if ($notice['priority'] == 'High') $priorityClass = 'danger';
                    elseif ($notice['priority'] == 'Medium') $priorityClass = 'warning';
                    elseif ($notice['priority'] == 'Low') $priorityClass = 'gray';
                    ?>
                    <span class="badge <?php echo $priorityClass; ?>"><?php echo htmlspecialchars($notice['priority'] ?? 'Low'); ?></span>
                </div>
                <div style="font-size:13px;color:var(--muted);line-height:1.6;margin-bottom:12px;">
                    <?php echo nl2br(htmlspecialchars($notice['body'])); ?>
                </div>
                <?php if (!empty($notice['attachment'])): ?>
                    <div style="margin-bottom:12px;">
                        <a href="api/notices.php?download=<?php echo $notice['id']; ?>" class="btn sm" style="text-decoration:none;display:inline-flex;align-items:center;gap:6px;">
                            <i class="bi bi-paperclip"></i> Download Attachment
                        </a>
                    </div>
                <?php endif; ?>
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <div style="font-size:12px;color:var(--faint);">Posted by <b><?php echo htmlspecialchars($notice['poster_first'] . ' ' . $notice['poster_last']); ?></b> · <?php echo formatDate($notice['created_at']); ?> · <?php echo htmlspecialchars($notice['target_type']); ?></div>
                    <div class="actions">
                        <?php if ($notice['require_acknowledgement'] && empty($notice['acknowledged_at'])): ?>
                            <button class="btn sm success" onclick="acknowledgeNotice(<?php echo $notice['id']; ?>)">Acknowledge</button>
                        <?php elseif (!empty($notice['acknowledged_at'])): ?>
                            <span class="badge approved">Acknowledged</span>
                        <?php endif; ?>
                        <?php if (hasPermission('notices', 'update') || $notice['posted_by'] == $user['id']): ?>
                            <div class="icon-btn" onclick="editNotice(<?php echo $notice['id']; ?>)" title="Edit"><i class="bi bi-pencil"></i></div>
                            <div class="icon-btn" onclick="deleteNotice(<?php echo $notice['id']; ?>)" style="color:var(--danger);" title="Delete"><i class="bi bi-trash"></i></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Notice Modal -->
<div id="noticeModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:var(--surface);border-radius:var(--radius);width:720px;max-width:95vw;overflow-y:auto;max-height:92vh;padding:24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
            <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0;" id="noticeModalTitle">Post Notice</h3>
            <button onclick="closeNoticeModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:var(--muted);line-height:1;">&times;</button>
        </div>
        <input type="hidden" id="noticeId">
        <div class="form-grid">
            <div class="field" style="grid-column:1/-1;">
                <label>Title <span class="req">*</span></label>
                <input type="text" id="noticeTitle" placeholder="Notice title">
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label>Body <span class="req">*</span></label>
                <textarea id="noticeBody" rows="4" placeholder="Notice content"></textarea>
            </div>
            <div class="field">
                <label>Priority</label>
                <select id="noticePriority">
                    <option value="Low">Low</option>
                    <option value="Medium">Medium</option>
                    <option value="High">High</option>
                </select>
            </div>
            <div class="field">
                <label>Target</label>
                <select id="noticeTarget">
                    <option value="all">All Employees</option>
                    <option value="role">Specific Roles</option>
                    <option value="department">Specific Departments</option>
                </select>
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label>Target Roles</label>
                <select id="noticeRoles" multiple style="width:100%;min-height:80px;">
                    <?php foreach ($roleOptions as $role): ?>
                        <option value="<?php echo $role['id']; ?>"><?php echo htmlspecialchars($role['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label>Target Departments</label>
                <select id="noticeDepts" multiple style="width:100%;min-height:80px;">
                    <?php foreach ($deptOptions as $dept): ?>
                        <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Expiry Date</label>
                <input type="date" id="noticeExpiry">
            </div>
            <div class="field" style="display:flex;align-items:center;gap:8px;">
                <label style="display:flex;align-items:center;gap:6px;"><input type="checkbox" id="noticeAck"> Require acknowledgement</label>
            </div>
            <div class="field" style="display:flex;align-items:center;gap:8px;">
                <label style="display:flex;align-items:center;gap:6px;"><input type="checkbox" id="noticeActive" checked> Active</label>
            </div>
            <div class="field" style="grid-column:1/-1;">
                <label>Attachment</label>
                <input type="file" id="noticeAttachment">
                <div style="font-size:11px;color:var(--faint);margin-top:4px;">PDF, DOC, DOCX, JPG, PNG (max 5MB)</div>
            </div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:8px;">
            <button class="btn" onclick="closeNoticeModal()">Cancel</button>
            <button class="btn primary" onclick="saveNotice()">Post Notice</button>
        </div>
    </div>
</div>

<script>
function openNoticeModal(notice) {
    const isEdit = !!notice && notice.id;
    document.getElementById('noticeModalTitle').textContent = isEdit ? 'Edit Notice' : 'Post Notice';
    document.getElementById('noticeId').value = notice ? notice.id : '';
    document.getElementById('noticeTitle').value = notice ? (notice.title || '') : '';
    document.getElementById('noticeBody').value = notice ? (notice.body || '') : '';
    document.getElementById('noticePriority').value = notice && notice.priority ? notice.priority : 'Low';
    document.getElementById('noticeTarget').value = notice ? (notice.target_type || 'all') : 'all';
    document.getElementById('noticeExpiry').value = notice && notice.expiry_date ? notice.expiry_date : '';
    document.getElementById('noticeAck').checked = notice ? !!notice.require_acknowledgement : false;
    document.getElementById('noticeActive').checked = notice ? !!notice.is_active : true;
    // Select roles / departments
    const roleSelect = document.getElementById('noticeRoles');
    const roleIds = notice && notice.target_roles ? String(notice.target_roles).split(',').map(s => s.trim()) : [];
    Array.from(roleSelect.options).forEach(o => o.selected = roleIds.includes(o.value));
    const deptSelect = document.getElementById('noticeDepts');
    const deptIds = notice && notice.target_departments ? String(notice.target_departments).split(',').map(s => s.trim()) : [];
    Array.from(deptSelect.options).forEach(o => o.selected = deptIds.includes(o.value));
    document.getElementById('noticeAttachment').value = '';
    document.getElementById('noticeModal').style.display = 'flex';
}

function openCreateNoticeModal() {
    openNoticeModal(null);
}

function editNotice(id) {
    fetch(`api/notices.php?notice_id=${id}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                openNoticeModal(data.notice);
            } else {
                alert('Error: ' + (data.message || 'Could not load notice'));
            }
        })
        .catch(err => {
            console.error(err);
            alert('Error loading notice');
        });
}

function closeNoticeModal() {
    document.getElementById('noticeModal').style.display = 'none';
}

function saveNotice() {
    const title = document.getElementById('noticeTitle').value.trim();
    const body = document.getElementById('noticeBody').value.trim();
    if (!title || !body) {
        alert('Title and body are required');
        return;
    }
    const targetType = document.getElementById('noticeTarget').value;
    const roleIds = Array.from(document.getElementById('noticeRoles').selectedOptions).map(o => o.value);
    const deptIds = Array.from(document.getElementById('noticeDepts').selectedOptions).map(o => o.value);

    const formData = new FormData();
    formData.append('action', 'save');
    formData.append('id', document.getElementById('noticeId').value || '');
    formData.append('title', title);
    formData.append('body', body);
    formData.append('priority', document.getElementById('noticePriority').value);
    formData.append('target_type', targetType);
    formData.append('target_roles', roleIds.join(','));
    formData.append('target_departments', deptIds.join(','));
    formData.append('expiry_date', document.getElementById('noticeExpiry').value);
    formData.append('require_acknowledgement', document.getElementById('noticeAck').checked ? 1 : 0);
    formData.append('is_active', document.getElementById('noticeActive').checked ? 1 : 0);
    const fileInput = document.getElementById('noticeAttachment');
    if (fileInput && fileInput.files && fileInput.files[0]) {
        formData.append('attachment', fileInput.files[0]);
    }

    fetch('api/notices.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            closeNoticeModal();
            location.reload();
        } else {
            ModalSystem.error('Error: ' + (data.message || 'Could not save notice'));
        }
    })
    .catch(err => {
        console.error(err);
        ModalSystem.error('Error saving notice');
    });
}

function deleteNotice(id) {
    if (!ModalSystem.confirm({ 
        message: 'Are you sure you want to delete this notice?',
        destructive: true,
        confirmText: 'Delete' 
    })) return;
    fetch('api/notices.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete', id: id })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            ModalSystem.error('Error: ' + (data.message || 'Could not delete notice'));
        }
    })
    .catch(err => {
        console.error(err);
        ModalSystem.error('Error deleting notice');
    });
}

function acknowledgeNotice(id) {
    fetch('api/notices.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'acknowledge', id: id })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error: ' + (data.message || 'Could not acknowledge'));
        }
    })
    .catch(err => {
        console.error(err);
        alert('Error acknowledging notice');
    });
}

function markNoticeRead(id) {
    fetch(`api/notices.php?mark_read=${id}`).catch(() => {});
}

function downloadAttachment(filename) {
    alert('Use the attachment link on the notice card. Attachment: ' + filename);
}

// Deep-link support: highlight & scroll to a specific notice when the user lands
// here from a notification (link format: ?module=notices&notice={id})
(function () {
    const params = new URLSearchParams(window.location.search);
    const noticeId = params.get('notice');
    if (!noticeId) return;
    const card = document.getElementById('notice-card-' + noticeId);
    if (!card) return;
    card.style.outline = '2px solid var(--accent)';
    card.style.outlineOffset = '3px';
    card.scrollIntoView({ behavior: 'smooth', block: 'center' });
})();
</script>
