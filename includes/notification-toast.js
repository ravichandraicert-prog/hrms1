/**
 * Real-Time Notification Toast System
 * Core PHP HRMS Application
 *
 * Features:
 * - Polls for new notifications every 30 seconds
 * - Shows toast popups in bottom-right corner
 * - Browser notification support
 * - Sound effects using Web Audio API
 * - Priority-based color coding
 * - Syncs with sidebar count
 *
 * BACKWARD COMPATIBLE:
 * - Graceful degradation if API fails
 * - No impact on existing functionality
 * - Optional feature
 */

(function() {
    'use strict';

    // Configuration
    const POLL_INTERVAL = 30000; // 30 seconds
    const TOAST_DURATION = 10000; // 10 seconds
    const MAX_TOASTS = 5; // Maximum visible toasts

    // State
    let lastCheck = null;
    let pollTimer = null;
    let audioContext = null;
    let browserNotificationPermission = 'default';
    // IDs already alerted/popup'd in this browser session (persisted across
    // module switches so notification popups never re-fire on navigation).
    let shownIds = new Set();
    // Maximum number of toasts shown for a single poll response (a large
    // backlog of old notifications must not flood the screen after a reload).
    const MAX_TOASTS_PER_POLL = 3;

    // Per-user storage keys (sessionStorage survives module-to-module navigation)
    function storageKey(name) {
        const uid = (typeof window.HRMS_USER_ID !== 'undefined' && window.HRMS_USER_ID)
            ? window.HRMS_USER_ID : 'anonymous';
        return 'hrms_notif_' + uid + '_' + name;
    }

    // Persist the last-check timestamp + already-alerted ids
    function persistState() {
        try {
            if (lastCheck) sessionStorage.setItem(storageKey('lastCheck'), lastCheck);
            sessionStorage.setItem(storageKey('shownIds'), JSON.stringify(Array.from(shownIds).slice(-500)));
        } catch (e) { /* storage is optional */ }
    }

    // Restore persisted state on page load so module switches don't re-alert
    function restoreState() {
        try {
            const lc = sessionStorage.getItem(storageKey('lastCheck'));
            if (lc) lastCheck = lc;
            const raw = sessionStorage.getItem(storageKey('shownIds'));
            if (raw) {
                const ids = JSON.parse(raw);
                if (Array.isArray(ids)) {
                    shownIds = new Set(ids.map(n => Number(n)).filter(n => n > 0));
                }
            }
        } catch (e) { /* storage is optional */ }
    }

    // Forget all persisted state (called after "mark all read")
    function resetSession() {
        try {
            sessionStorage.removeItem(storageKey('lastCheck'));
            sessionStorage.removeItem(storageKey('shownIds'));
        } catch (e) { /* storage is optional */ }
        lastCheck = null;
        shownIds = new Set();
    }

    // Icon mapping based on notification type
    const typeIcons = {
        'leave': 'bi-calendar-minus',
        'task': 'bi-list-task',
        'attendance': 'bi-clock-history',
        'notice': 'bi-megaphone',
        'payroll': 'bi-cash-stack',
        'approval': 'bi-check-circle',
        'idle': 'bi-hourglass-split',
        'default': 'bi-bell'
    };

    // Priority colors (using existing HRMS colors)
    const priorityColors = {
        'High': 'var(--danger)',
        'Medium': 'var(--warning)',
        'Low': 'var(--success)',
        'default': 'var(--accent)'
    };

    // Type-specific colors
    const typeColors = {
        'leave': 'var(--warning)',
        'task': 'var(--accent)',
        'attendance': 'var(--info)',
        'notice': 'var(--accent)',
        'payroll': 'var(--success)',
        'approval': 'var(--info)',
        'idle': 'var(--warning)',
        'default': 'var(--accent)'
    };

    // Initialize the toast system
    function init() {
        // Restore per-session state so navigation between modules never re-alerts
        restoreState();

        // Check if browser notifications are supported
        if ('Notification' in window) {
            browserNotificationPermission = Notification.permission;
        }

        // Start polling
        startPolling();

        // Request browser notification permission on first user interaction
        document.addEventListener('click', requestBrowserNotificationPermission, { once: true });

        // Set up Electron notification click handler
        if (isElectron() && window.electronAPI && window.electronAPI.onNotificationClick) {
            window.electronAPI.onNotificationClick((data) => {
                if (data.link) {
                    window.location.href = data.link;
                } else {
                    // Navigate to notifications module
                    window.location.href = '?module=notifications';
                }
            });
        }
    }

    // Request browser notification permission
    function requestBrowserNotificationPermission() {
        if ('Notification' in window && Notification.permission === 'default') {
            Notification.requestPermission().then(permission => {
                browserNotificationPermission = permission;
            });
        }
    }

    // Start polling for new notifications
    function startPolling() {
        pollNotifications();
        pollTimer = setInterval(pollNotifications, POLL_INTERVAL);
    }

    // Poll for new notifications
    function pollNotifications() {
        // Use relative path to avoid CORS issues
        let url = 'api/notification_poll.php';

        // Build query parameters
        const query = [];
        if (lastCheck) {
            query.push('last_check=' + encodeURIComponent(lastCheck));
        }
        // Tell the server which notifications have already been alerted for in
        // this session so they are never returned/popped up again.
        if (shownIds.size > 0) {
            query.push('exclude_ids=' + encodeURIComponent(Array.from(shownIds).slice(-200).join(',')));
        }
        if (query.length > 0) {
            url += '?' + query.join('&');
        }

        fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json'
            }
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    lastCheck = data.last_check;

                    // Update sidebar count
                    updateSidebarCount(data.unread_count);

                    // Show toasts only for genuinely new, unread notifications
                    // that have not already been alerted for in this session.
                    if (data.notifications && data.notifications.length > 0) {
                        let toastsShown = 0;
                        data.notifications.forEach(notification => {
                            if (!notification || !notification.id) return;
                            // Remember every returned id (even those skipped by
                            // the cap) so they can never re-alert this session.
                            shownIds.add(notification.id);
                            if (notification.is_read == 1) return;
                            if (toastsShown >= MAX_TOASTS_PER_POLL) return;
                            toastsShown++;
                            showToast(notification);
                        });
                        persistState();
                    }
                }
            })
            .catch(error => {
                console.error('Error polling notifications:', error);
                // Continue polling even if one request fails
            });
    }

    // Update sidebar notification count
    function updateSidebarCount(count) {
        const badge = document.querySelector('.nav-item[href*="notifications"] .nav-badge');
        if (badge) {
            if (count > 0) {
                badge.textContent = count;
                badge.style.display = 'flex';
                // Add animation
                badge.style.animation = 'none';
                badge.offsetHeight; // Trigger reflow
                badge.style.animation = 'pulse 0.3s ease-in-out';
            } else {
                badge.style.display = 'none';
            }
        }
    }

    // Show toast notification
    function showToast(notification) {
        // Create toast container if it doesn't exist
        let container = document.getElementById('notification-toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'notification-toast-container';
            container.style.cssText = `
                position: fixed;
                bottom: 20px;
                right: 20px;
                z-index: 10000;
                display: flex;
                flex-direction: column;
                gap: 10px;
                max-width: 380px;
            `;
            document.body.appendChild(container);
        }

        // Limit number of toasts
        while (container.children.length >= MAX_TOASTS) {
            container.removeChild(container.firstChild);
        }

        // Determine icon and color
        const iconClass = typeIcons[notification.type] || typeIcons.default;
        const priority = notification.priority || 'default';
        const color = priority !== 'default' ? priorityColors[priority] : typeColors[notification.type] || typeColors.default;

        // Format title with priority for tasks
        let displayTitle = notification.title;
        if (notification.type === 'task' && notification.priority) {
            displayTitle = `Task: ${notification.title} (${notification.priority})`;
        }

        // Format timestamp
        const timestamp = formatTimestamp(notification.created_at);

        // Create toast element
        const toast = document.createElement('div');
        toast.className = 'notification-toast';
        toast.style.cssText = `
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            padding: 16px;
            display: flex;
            gap: 12px;
            align-items: flex-start;
            animation: slideIn 0.3s ease-out;
            cursor: pointer;
            position: relative;
        `;

        toast.innerHTML = `
            <div class="toast-icon" style="
                width: 40px;
                height: 40px;
                border-radius: 50%;
                background: ${color}20;
                color: ${color};
                display: flex;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
            ">
                <i class="bi ${iconClass}" style="font-size: 18px;"></i>
            </div>
            <div style="flex: 1; min-width: 0;">
                <div class="toast-title" style="
                    font-weight: 600;
                    font-size: 14px;
                    color: var(--ink);
                    margin-bottom: 4px;
                    line-height: 1.3;
                ">${escapeHtml(displayTitle)}</div>
                <div class="toast-message" style="
                    font-size: 13px;
                    color: var(--muted);
                    margin-bottom: 6px;
                    line-height: 1.4;
                    display: -webkit-box;
                    -webkit-line-clamp: 2;
                    -webkit-box-orient: vertical;
                    overflow: hidden;
                ">${escapeHtml(notification.message)}</div>
                <div class="toast-time" style="
                    font-size: 11px;
                    color: var(--faint);
                ">${timestamp}</div>
            </div>
            <button class="toast-close" style="
                background: none;
                border: none;
                color: var(--faint);
                cursor: pointer;
                padding: 4px;
                font-size: 16px;
                line-height: 1;
                flex-shrink: 0;
            " title="Close">
                <i class="bi bi-x"></i>
            </button>
        `;

        // Add click handler for opening notification
        toast.addEventListener('click', (e) => {
            if (!e.target.closest('.toast-close')) {
                openNotification(notification);
            }
        });

        // Add close button handler
        const closeBtn = toast.querySelector('.toast-close');
        closeBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            closeToast(toast, notification.id, false); // Don't mark as read
        });

        // Add to container
        container.appendChild(toast);

        // Play sound
        playNotificationSound();

        // Show browser notification if tab is hidden or user enabled
        showBrowserNotification(notification, displayTitle);

        // Auto-dismiss after duration
        setTimeout(() => {
            closeToast(toast, notification.id, false);
        }, TOAST_DURATION);
    }

    // Close toast
    function closeToast(toast, notificationId, markAsRead) {
        toast.style.animation = 'slideOut 0.3s ease-in';
        setTimeout(() => {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, 300);

        // Optionally mark as read
        if (markAsRead && notificationId) {
            markNotificationAsRead(notificationId);
        }
    }

    // Open notification (mark as read and navigate)
    function openNotification(notification) {
        // Mark as read
        markNotificationAsRead(notification.id);

        // Navigate if link exists
        if (notification.link) {
            window.location.href = notification.link;
        } else {
            // Navigate to notifications module
            window.location.href = '?module=notifications';
        }
    }

    // Mark notification as read
    function markNotificationAsRead(notificationId) {
        if (notificationId) {
            shownIds.add(notificationId);
            persistState();
        }
        fetch('api/notifications.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'mark_read', id: notificationId })
        }).catch(error => {
            console.error('Error marking notification as read:', error);
        });
    }

    // Show browser notification
    function showBrowserNotification(notification, title) {
        // Check if running in Electron environment
        if (isElectron()) {
            showElectronNotification(notification, title);
            return;
        }

        if ('Notification' in window && browserNotificationPermission === 'granted') {
            // Check if tab is hidden or user has browser notifications enabled
            if (document.hidden || shouldShowBrowserNotification()) {
                const browserNotif = new Notification(title, {
                    body: notification.message,
                    icon: '/favicon.ico', // Add your favicon path
                    tag: notification.id.toString(),
                    requireInteraction: false
                });

                browserNotif.onclick = () => {
                    window.focus();
                    openNotification(notification);
                    browserNotif.close();
                };

                // Auto-close after 5 seconds
                setTimeout(() => {
                    browserNotif.close();
                }, 5000);
            }
        }
    }

    // Check if running in Electron
    function isElectron() {
        // Check for electronAPI exposed by preload script
        if (window.electronAPI && window.electronAPI.isElectron) {
            return true;
        }
        // Check for Electron process
        if (window && window.process && window.process.type === 'renderer') {
            return true;
        }
        // Check for Electron user agent
        if (navigator.userAgent.toLowerCase().indexOf('electron') > -1) {
            return true;
        }
        return false;
    }

    // Show Electron native notification
    function showElectronNotification(notification, title) {
        try {
            // Use Electron's IPC to send notification to main process
            if (window.electronAPI && window.electronAPI.sendNotification) {
                // Format notification for better display
                const formattedTitle = formatNotificationTitle(notification, title);
                const formattedBody = formatNotificationBody(notification);

                window.electronAPI.sendNotification({
                    title: formattedTitle,
                    body: formattedBody,
                    icon: '/favicon.ico',
                    notificationId: notification.id,
                    link: notification.link,
                    type: notification.type,
                    priority: notification.priority
                });
            }
        } catch (error) {
            console.error('Error showing Electron notification:', error);
            // Graceful degradation - fall back to browser notification
            if ('Notification' in window && browserNotificationPermission === 'granted') {
                const browserNotif = new Notification(title, {
                    body: notification.message,
                    icon: '/favicon.ico',
                    tag: notification.id.toString()
                });
                browserNotif.onclick = () => {
                    openNotification(notification);
                    browserNotif.close();
                };
            }
        }
    }

    // Format notification title with bold indication ( Electron will render bold if supported)
    function formatNotificationTitle(notification, defaultTitle) {
        // Return clean title - Electron will bold it automatically
        return defaultTitle || notification.title;
    }

    // Format notification body for better readability
    function formatNotificationBody(notification) {
        let body = notification.message || '';

        // Add priority for tasks if not already in title
        if (notification.type === 'task' && notification.priority && !body.includes('(' + notification.priority + ')')) {
            body = body.trim();
        }

        // Truncate if too long (Windows notifications have length limits)
        if (body.length > 200) {
            body = body.substring(0, 197) + '...';
        }

        return body;
    }

    // Check if user has browser notifications enabled
    function shouldShowBrowserNotification() {
        // This would check user preferences from settings
        // For now, return false - only show when tab is hidden
        return false;
    }

    // Play notification sound using Web Audio API
    function playNotificationSound() {
        try {
            if (!audioContext) {
                audioContext = new (window.AudioContext || window.webkitAudioContext)();
            }

            // Create a simple beep sound
            const oscillator = audioContext.createOscillator();
            const gainNode = audioContext.createGain();

            oscillator.connect(gainNode);
            gainNode.connect(audioContext.destination);

            oscillator.frequency.value = 800; // 800 Hz
            oscillator.type = 'sine';

            gainNode.gain.setValueAtTime(0.1, audioContext.currentTime);
            gainNode.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 0.2);

            oscillator.start(audioContext.currentTime);
            oscillator.stop(audioContext.currentTime + 0.2);
        } catch (error) {
            console.error('Error playing notification sound:', error);
            // Graceful degradation - sound is optional
        }
    }

    // Format timestamp
    function formatTimestamp(timestamp) {
        const date = new Date(timestamp);
        const now = new Date();
        const diff = now - date;

        if (diff < 60000) { // Less than 1 minute
            return 'Just now';
        } else if (diff < 3600000) { // Less than 1 hour
            const minutes = Math.floor(diff / 60000);
            return `${minutes}m ago`;
        } else if (diff < 86400000) { // Less than 1 day
            const hours = Math.floor(diff / 3600000);
            return `${hours}h ago`;
        } else {
            return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        }
    }

    // Escape HTML to prevent XSS
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Add CSS animations
    function addStyles() {
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideIn {
                from {
                    transform: translateX(100%);
                    opacity: 0;
                }
                to {
                    transform: translateX(0);
                    opacity: 1;
                }
            }

            @keyframes slideOut {
                from {
                    transform: translateX(0);
                    opacity: 1;
                }
                to {
                    transform: translateX(100%);
                    opacity: 0;
                }
            }

            @keyframes pulse {
                0%, 100% {
                    transform: scale(1);
                }
                50% {
                    transform: scale(1.2);
                }
            }

            .notification-toast:hover {
                box-shadow: 0 6px 16px rgba(0,0,0,0.2);
            }

            .toast-close:hover {
                color: var(--danger);
            }
        `;
        document.head.appendChild(style);
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            addStyles();
            init();
        });
    } else {
        addStyles();
        init();
    }

    // Expose API for external use
    window.NotificationToast = {
        init: init,
        showToast: showToast,
        updateSidebarCount: updateSidebarCount,
        resetSession: resetSession
    };

})();
