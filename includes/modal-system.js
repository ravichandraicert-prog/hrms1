/**
 * Centralized Modal Notification System for HRMS
 * Replaces native browser alerts, confirms, and prompts with professional modals.
 *
 * STACK-SAFE: multiple modals may stack; only the top-most is active. Closing
 * the top element reveals the one below so its buttons keep working. This fixes
 * the "OK button stops working" bug that happens when a new modal is opened
 * while another is still on screen.
 */

const ModalSystem = (function() {
    'use strict';

    // Modal container
    let modalContainer = null;
    // Stack of open modals: [{ overlay, container, modalData, resolve }]
    const modalStack = [];
    let isModalOpen = false;

    // The modal at the top of the stack (the currently active one)
    function topModal() {
        return modalStack.length ? modalStack[modalStack.length - 1] : null;
    }
    function refreshState() {
        isModalOpen = modalStack.length > 0;
    }

    // Initialize the modal system
    function init() {
        if (!modalContainer) {
            modalContainer = document.createElement('div');
            modalContainer.id = 'hrms-modal-container';
            modalContainer.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;z-index:9999;pointer-events:none;';
            document.body.appendChild(modalContainer);
        }
        // Add keyboard event listener for Escape key
        document.addEventListener('keydown', handleGlobalKeydown);
    }

    // Handle global keyboard events
    function handleGlobalKeydown(e) {
        if (e.key === 'Escape' && isModalOpen) {
            const active = topModal();
            if (active && active.modalData && active.modalData.closeOnEscape !== false) {
                closeModal();
            }
        }
    }

    // Create overlay
    function createOverlay() {
        const overlay = document.createElement('div');
        overlay.className = 'modal-overlay';
        overlay.style.cssText = `
            position:fixed;top:0;left:0;right:0;bottom:0;
            background:rgba(0,0,0,0.5);z-index:10000;
            display:flex;align-items:center;justify-content:center;
            animation:fadeIn 0.2s ease-out;pointer-events:auto;
        `;
        return overlay;
    }

    // Create modal container
    function createModalContainer(options) {
        const container = document.createElement('div');
        container.className = 'modal-container';
        container.style.cssText = `
            background:var(--surface);border-radius:var(--radius);
            width:${options.width || '420px'};max-width:95vw;
            padding:${options.padding || '24px'};
            box-shadow:0 4px 20px rgba(0,0,0,0.15);
            animation:slideIn 0.3s ease-out;
            max-height:90vh;overflow-y:auto;
        `;
        return container;
    }

    // Get icon based on type
    function getIcon(type) {
        const icons = {
            success: '<i class="bi bi-check-circle-fill" style="color:var(--success);font-size:24px;"></i>',
            error: '<i class="bi bi-x-circle-fill" style="color:var(--danger);font-size:24px;"></i>',
            warning: '<i class="bi bi-exclamation-triangle-fill" style="color:var(--warning);font-size:24px;"></i>',
            info: '<i class="bi bi-info-circle-fill" style="color:var(--info);font-size:24px;"></i>',
            confirm: '<i class="bi bi-question-circle-fill" style="color:var(--accent);font-size:24px;"></i>'
        };
        return icons[type] || icons.info;
    }

    // Get title based on type
    function getDefaultTitle(type) {
        const titles = {
            success: 'Success',
            error: 'Error',
            warning: 'Warning',
            info: 'Information',
            confirm: 'Confirm'
        };
        return titles[type] || 'Notification';
    }

    // Show notification modal
    function showNotification(options) {
        return new Promise((resolve) => {
            if (!modalContainer) init();

            const type = options.type || 'info';
            const title = options.title || getDefaultTitle(type);
            const message = options.message || '';
            const showIcon = options.showIcon !== false;

            const overlay = createOverlay();
            const container = createModalContainer(options);

            let iconHTML = showIcon ? `<div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
                ${getIcon(type)}
                <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0;color:var(--ink);">${title}</h3>
            </div>` : `<h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0 0 16px 0;color:var(--ink);">${title}</h3>`;

            container.innerHTML = `
                ${iconHTML}
                <div style="font-size:14px;color:var(--ink);line-height:1.5;margin-bottom:24px;">${message}</div>
                <div style="display:flex;justify-content:flex-end;gap:10px;">
                    <button class="modal-btn modal-btn-primary" data-action="ok">OK</button>
                </div>
            `;

            overlay.appendChild(container);
            modalContainer.appendChild(overlay);

            // Push to the stack
            modalStack.push({ overlay, container, modalData: options, resolve });
            refreshState();

            // Add event listeners
            const okBtn = container.querySelector('[data-action="ok"]');
            okBtn.onclick = () => {
                closeModal();
                resolve(true);
            };

            // Close on overlay click
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay && options.closeOnOverlay !== false) {
                    closeModal();
                    resolve(false);
                }
            });

            // Focus management
            setTimeout(() => okBtn.focus(), 100);
        });
    }

    // Show confirmation modal
    function showConfirmation(options) {
        return new Promise((resolve) => {
            if (!modalContainer) init();
            const title = options.title || 'Confirm Action';
            const message = options.message || 'Are you sure you want to proceed?';
            const confirmText = options.confirmText || 'Confirm';
            const cancelText = options.cancelText || 'Cancel';
            const destructive = options.destructive || false;
            const overlay = createOverlay();
            const container = createModalContainer({ width: '480px', ...options });
            const btnClass = destructive ? 'modal-btn-danger' : 'modal-btn-primary';
            container.innerHTML = `
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
                    ${getIcon('confirm')}
                    <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0;color:var(--ink);">${title}</h3>
                </div>
                <div style="font-size:14px;color:var(--ink);line-height:1.5;margin-bottom:24px;">${message}</div>
                ${options.details ? `<div style="font-size:12px;color:var(--muted);margin-bottom:24px;padding:12px;background:var(--bg);border-radius:8px;">${options.details}</div>` : ''}
                <div style="display:flex;justify-content:flex-end;gap:10px;">
                    <button class="modal-btn modal-btn-secondary" data-action="cancel">${cancelText}</button>
                    <button class="modal-btn ${btnClass}" data-action="confirm">${confirmText}</button>
                </div>
            `;
            overlay.appendChild(container);
            modalContainer.appendChild(overlay);
            modalStack.push({ overlay, container, modalData: options, resolve });
            refreshState();
            const confirmBtn = container.querySelector('[data-action="confirm"]');
            const cancelBtn = container.querySelector('[data-action="cancel"]');
            confirmBtn.onclick = () => { closeModal(); resolve(true); };
            cancelBtn.onclick = () => { closeModal(); resolve(false); };
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay && options.closeOnOverlay !== false) { closeModal(); resolve(false); }
            });
            setTimeout(() => { if (destructive) cancelBtn.focus(); else confirmBtn.focus(); }, 100);
        });
    }

    // Show input modal (replaces prompt)
    function showInput(options) {
        return new Promise((resolve) => {
            if (!modalContainer) init();
            const title = options.title || 'Input Required';
            const message = options.message || '';
            const placeholder = options.placeholder || '';
            const defaultValue = options.defaultValue || '';
            const inputType = options.inputType || 'text';
            const required = options.required || false;
            const overlay = createOverlay();
            const container = createModalContainer({ width: '480px', ...options });
            container.innerHTML = `
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
                    ${getIcon('info')}
                    <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0;color:var(--ink);">${title}</h3>
                </div>
                ${message ? `<div style="font-size:14px;color:var(--ink);line-height:1.5;margin-bottom:16px;">${message}</div>` : ''}
                <div class="field" style="margin-bottom:24px;">
                    <input type="${inputType}" class="modal-input" placeholder="${placeholder}" value="${defaultValue}"
                           ${required ? 'required' : ''}
                           style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:8px;font-size:13px;font-family:'Inter';background:var(--bg);color:var(--ink);outline:none;">
                </div>
                <div style="display:flex;justify-content:flex-end;gap:10px;">
                    <button class="modal-btn modal-btn-secondary" data-action="cancel">Cancel</button>
                    <button class="modal-btn modal-btn-primary" data-action="confirm">OK</button>
                </div>
            `;
            overlay.appendChild(container);
            modalContainer.appendChild(overlay);
            modalStack.push({ overlay, container, modalData: options, resolve });
            refreshState();
            const input = container.querySelector('.modal-input');
            const confirmBtn = container.querySelector('[data-action="confirm"]');
            const cancelBtn = container.querySelector('[data-action="cancel"]');
            const handleConfirm = () => {
                const value = input.value.trim();
                if (required && !value) { input.style.borderColor = 'var(--danger)'; input.focus(); return; }
                closeModal();
                resolve(value || null);
            };
            confirmBtn.onclick = handleConfirm;
            input.addEventListener('keypress', (e) => { if (e.key === 'Enter') handleConfirm(); });
            cancelBtn.onclick = () => { closeModal(); resolve(null); };
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay && options.closeOnOverlay !== false) { closeModal(); resolve(null); }
            });
            setTimeout(() => input.focus(), 100);
        });
    }

    // Show a rich HTML modal (used for the payslip viewer).
    function showHtml(bodyHtml, options) {
        return new Promise((resolve) => {
            if (!modalContainer) init();
            const title = options.title || 'Details';
            options.width = options.width || '520px';
            const hasCustomPadding = options.padding === '0px';
            const headerPadding = '14px 20px';
            const bodyPadding = hasCustomPadding ? '18px 20px 20px' : (options.padding || '24px');
            const overlay = createOverlay();
            const container = createModalContainer(options);
            container.innerHTML = `
                <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;border-bottom:1px solid var(--border);padding:${headerPadding};">
                    <h3 style="font-family:'Space Grotesk';font-size:18px;font-weight:600;margin:0;color:var(--ink);">${title}</h3>
                    <button class="modal-btn modal-btn-secondary" data-action="close" title="Close">&times;</button>
                </div>
                <div style="padding:${bodyPadding};">${bodyHtml}</div>
            `;
            overlay.appendChild(container);
            modalContainer.appendChild(overlay);
            modalStack.push({ overlay, container, modalData: options, resolve });
            refreshState();
            const closeBtn = container.querySelector('[data-action="close"]');
            closeBtn.onclick = () => { closeModal(); resolve(true); };
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay && options.closeOnOverlay !== false) { closeModal(); resolve(true); }
            });
            setTimeout(() => closeBtn.focus(), 100);
        });
    }

    // Close the TOP modal (stack-safe)
    function closeModal() {
        if (modalStack.length === 0) return;
        const entry = modalStack.pop();
        const { overlay, container } = entry;
        // Add fade out animation
        overlay.style.animation = 'fadeOut 0.2s ease-out';
        container.style.animation = 'slideOut 0.2s ease-out';
        setTimeout(() => {
            if (overlay && overlay.parentNode) {
                overlay.parentNode.removeChild(overlay);
            }
            refreshState();
        }, 200);
    }

    // Add CSS animations + modal button styles
    function addStyles() {
        if (document.getElementById('modal-system-styles')) return;
        const style = document.createElement('style');
        style.id = 'modal-system-styles';
        style.textContent = `
            @keyframes fadeIn {
                from { opacity: 0; }
                to { opacity: 1; }
            }
            @keyframes fadeOut {
                from { opacity: 1; }
                to { opacity: 0; }
            }
            @keyframes slideIn {
                from { transform: translateY(-20px); opacity: 0; }
                to { transform: translateY(0); opacity: 1; }
            }
            @keyframes slideOut {
                from { transform: translateY(0); opacity: 1; }
                to { transform: translateY(-20px); opacity: 0; }
            }
            .modal-btn {
                padding:8px 16px;border-radius:8px;font-size:13px;font-weight:500;
                cursor:pointer;border:none;font-family:'Inter';transition:background .15s;
            }
            .modal-btn-primary {
                background:var(--accent);color:#fff;
            }
            .modal-btn-primary:hover {
                background:var(--accent-dark);
            }
            .modal-btn-secondary {
                background:var(--bg);color:var(--ink);border:1px solid var(--border);
            }
            .modal-btn-secondary:hover {
                background:var(--accent-ink);
            }
            .modal-btn-danger {
                background:var(--danger);color:#fff;
            }
            .modal-btn-danger:hover {
                background:#C83030;
            }
            .modal-input:focus {
                border-color:var(--accent);background:var(--surface);
            }
            .modal-input.error {
                border-color:var(--danger);background:var(--danger-bg);
            }
            .field input.error, .field select.error, .field textarea.error {
                border-color:var(--danger);background:var(--danger-bg);
            }
        `;
        document.head.appendChild(style);
    }

    // Public API
    return {
        init: function() {
            init();
            addStyles();
        },

        success: function(message, title) {
            return showNotification({ type: 'success', message, title });
        },

        error: function(message, title) {
            return showNotification({ type: 'error', message, title });
        },

        warning: function(message, title) {
            return showNotification({ type: 'warning', message, title });
        },

        info: function(message, title) {
            return showNotification({ type: 'info', message, title });
        },

        html: function(bodyHtml, options) {
            return showHtml(bodyHtml, options || {});
        },

        confirm: function(options) {
            if (typeof options === 'string') options = { message: options };
            return showConfirmation(options);
        },

        prompt: function(options) {
            if (typeof options === 'string') options = { message: options };
            return showInput(options);
        },

        close: closeModal,

        isOpen: function() {
            return isModalOpen;
        }
    };
})();

// Auto-initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => ModalSystem.init());
} else {
    ModalSystem.init();
}

// Make it available globally
window.ModalSystem = ModalSystem;