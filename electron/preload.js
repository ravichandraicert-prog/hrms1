/**
 * Electron Preload Script for HRMS
 * Provides secure IPC communication between renderer and main process
 */

const { contextBridge, ipcRenderer } = require('electron');

// Expose protected methods that allow the renderer process to use
// the ipcRenderer without exposing the entire object
contextBridge.exposeInMainWorld('electronAPI', {
    // Send notification to main process
    sendNotification: (notificationData) => {
        ipcRenderer.send('show-notification', notificationData);
    },

    // Listen for notification clicks
    onNotificationClick: (callback) => {
        ipcRenderer.on('notification-clicked', (event, data) => callback(data));
    },

    // Remove notification click listener
    removeNotificationClickListener: () => {
        ipcRenderer.removeAllListeners('notification-clicked');
    },

    // Check if running in Electron
    isElectron: () => true,

    // Get app version
    getVersion: () => ipcRenderer.invoke('get-app-version'),

    // Get notification permission status
    getNotificationPermission: () => ipcRenderer.invoke('get-notification-permission'),

    // Request notification permission
    requestNotificationPermission: () => ipcRenderer.invoke('request-notification-permission'),

    // Open external URL in default browser
    openExternal: (url) => ipcRenderer.invoke('open-external', url),

    // Launch desktop application
    launchApp: (path) => ipcRenderer.invoke('launch-app', path),

    // Open file/folder in OS
    openPath: (path) => ipcRenderer.invoke('open-path', path),

    // Browse for file/folder path
    browseForPath: (appType) => ipcRenderer.invoke('browse-for-path', appType)
});

/**
 * HRMS Desktop Bridge - exposes OS-level system idle time and shutdown / quit
 * notifications to the web application. Required by the idle-time tracker
 * (OS-level inactivity) and the automatic clock-out + logout on shutdown /
 * restart / logoff / app quit (api/idle.php, action=shutdown).
 */
contextBridge.exposeInMainWorld('hrmsDesktop', {
    /**
     * @returns {Promise<number>} seconds since the last OS-level user input
     */
    getSystemIdleTime: () => ipcRenderer.invoke('hrms-get-system-idle-time'),
    /**
     * Registers a callback invoked by the Electron main process when the OS is
     * shutting down / restarting / logging off or the user quits the app.
     * @param {Function} callback
     */
    onShutdownRequested: (callback) => {
        if (typeof callback === 'function') {
            ipcRenderer.on('hrms-shutdown-requested', () => callback());
        }
    }
});
