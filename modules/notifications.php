<?php
/**
 * Notifications Module
 * Core PHP HRMS Application
 * Section 10 - Notifications module
 */

$db = Database::getInstance();
$user = getCurrentUser();

// Get active filter
$typeFilter = $_GET['type'] ?? '';

// Get notifications for current user
$notifications = [];
try {
    $params = [$user['id']];
    $sql = "SELECT * FROM notifications WHERE user_id = ?";
    if ($typeFilter) {
        $sql .= " AND type = ?";
        $params[] = $typeFilter;
    }
    $sql .= " ORDER BY created_at DESC LIMIT 100";
    $notifications = $db->fetchAll($sql, $params);
} catch (Exception $e) {
    // Table might not exist or have different structure
}
?>

<div class="panel-head"></div>

<div class="toolbar">
    <div class="filter-chip <?php echo $typeFilter == '' ? 'active' : ''; ?>" onclick="filterByType('')">All</div>
    <div class="filter-chip <?php echo $typeFilter == 'leave' ? 'active' : ''; ?>" onclick="filterByType('leave')">Leave requests</div>
    <div class="filter-chip <?php echo $typeFilter == 'task' ? 'active' : ''; ?>" onclick="filterByType('task')">Tasks</div>
    <div class="filter-chip <?php echo $typeFilter == 'attendance' ? 'active' : ''; ?>" onclick="filterByType('attendance')">Attendance</div>
    <div class="filter-chip <?php echo $typeFilter == 'notice' ? 'active' : ''; ?>" onclick="filterByType('notice')">Notices</div>
    <div class="spacer"></div>
    <div class="btn" onclick="markAllAsRead()"><i class="bi bi-check2-all"></i> Mark all read</div>
</div>

<div class="panel">
    <?php if (empty($notifications)): ?>
        <div class="empty-state">
            <i class="bi bi-bell"></i>
            <p>No notifications</p>
        </div>
    <?php else: ?>
        <?php foreach ($notifications as $notif): ?>
            <?php
            $iconClass = 'bi-bell';
            $iconBg = 'var(--accent-ink)';
            $iconColor = 'var(--accent)';
            $badgeClass = 'info';
            $badgeText = 'Info';
            
            if (strpos($notif['type'], 'leave') !== false || strpos($notif['title'], 'leave') !== false) {
                $iconClass = 'bi-calendar-minus';
                $iconBg = 'var(--warning-bg)';
                $iconColor = 'var(--warning)';
                $badgeClass = 'pending';
                $badgeText = 'Leave';
            } elseif (strpos($notif['type'], 'task') !== false || strpos($notif['title'], 'task') !== false) {
                $iconClass = 'bi-list-task';
                $iconBg = 'var(--accent-ink)';
                $iconColor = 'var(--accent)';
                $badgeClass = 'info';
                $badgeText = 'Task';
            } elseif (strpos($notif['type'], 'attendance') !== false || strpos($notif['title'], 'attendance') !== false) {
                $iconClass = 'bi-clock-history';
                $iconBg = 'var(--info-bg)';
                $iconColor = 'var(--info)';
                $badgeClass = 'warning';
                $badgeText = 'Attendance';
            } elseif (strpos($notif['title'], 'approved') !== false) {
                $iconClass = 'bi-check-circle';
                $iconBg = 'var(--success-bg)';
                $iconColor = 'var(--success)';
                $badgeClass = 'approved';
                $badgeText = 'Approved';
            } elseif (strpos($notif['title'], 'rejected') !== false) {
                $iconClass = 'bi-x-circle';
                $iconBg = 'var(--danger-bg)';
                $iconColor = 'var(--danger)';
                $badgeClass = 'rejected';
                $badgeText = 'Rejected';
            } elseif (strpos($notif['type'], 'notice') !== false || strpos($notif['title'], 'notice') !== false || strpos($notif['title'], 'announcement') !== false) {
                $iconClass = 'bi-megaphone';
                $iconBg = 'var(--accent-ink)';
                $iconColor = 'var(--accent)';
                $badgeClass = 'accent';
                $badgeText = 'Notice';
            }
            ?>
            <div class="notif-item <?php echo !$notif['is_read'] ? 'unread' : ''; ?>" style="cursor:pointer;" onclick="<?php echo $notif['link'] ? "openNotification(" . $notif['id'] . ", '" . addslashes($notif['link']) . "')" : "markNotificationRead(" . $notif['id'] . ")"; ?>">
                <div class="notif-icon" style="background:<?php echo $iconBg; ?>;color:<?php echo $iconColor; ?>;"><i class="bi <?php echo $iconClass; ?>"></i></div>
                <div style="flex:1;">
                    <div class="notif-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                    <div class="notif-msg"><?php echo htmlspecialchars($notif['message']); ?></div>
                    <div class="notif-time"><?php echo formatDateTime($notif['created_at'], 'M j, Y g:i A'); ?></div>
                </div>
                <div class="badge <?php echo $badgeClass; ?>"><?php echo $badgeText; ?></div>
                <div class="actions" onclick="event.stopPropagation();">
                    <?php if (!$notif['is_read']): ?>
                        <div class="icon-btn" onclick="markNotificationRead(<?php echo $notif['id']; ?>)" title="Mark as read"><i class="bi bi-check2"></i></div>
                    <?php endif; ?>
                    <div class="icon-btn" onclick="deleteNotification(<?php echo $notif['id']; ?>)" style="color:var(--danger);" title="Delete"><i class="bi bi-trash"></i></div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
function filterByType(type) {
    const url = new URL(window.location);
    if (type) {
        url.searchParams.set('type', type);
    } else {
        url.searchParams.delete('type');
    }
    window.location = url.toString();
}

function markAllAsRead() {
    fetch('api/notifications.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'mark_all_read' })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            // Clear the toast/popup session so these notifications never re-alert
            if (window.NotificationToast && typeof NotificationToast.resetSession === 'function') {
                try { NotificationToast.resetSession(); } catch (e) { /* non-fatal */ }
            }
            location.reload();
        } else {
            ModalSystem.error('Error: ' + (data.message || 'Could not mark as read'));
        }
    })
    .catch(err => {
        console.error(err);
        ModalSystem.error('Error marking notifications as read');
    });
}

function markNotificationRead(id, reload = true) {
    fetch('api/notifications.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'mark_read', id: id })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success && reload) {
            location.reload();
        }
    })
    .catch(err => {
        console.error(err);
        ModalSystem.error('Error marking notification as read');
    });
}

function deleteNotification(id) {
    if (!ModalSystem.confirm({ 
        message: 'Delete this notification?',
        destructive: true,
        confirmText: 'Delete' 
    })) return;
    fetch('api/notifications.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete', id: id })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            ModalSystem.error('Error: ' + (data.message || 'Could not delete notification'));
        }
    })
    .catch(err => {
        console.error(err);
        ModalSystem.error('Error deleting notification');
    });
}

function openNotification(id, link) {
    markNotificationRead(id, false); // mark read without reloading
    if (link) {
        window.location.href = link;
    }
}
</script>
