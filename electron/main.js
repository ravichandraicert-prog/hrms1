const { app, BrowserWindow, Menu, Tray, nativeImage, ipcMain, powerMonitor, Notification, shell, dialog, net } = require('electron');
const path = require('path');
const fs = require('fs');

// Ensure the app is identified as "HRMS" everywhere (display name / notifications).
app.setName('HRMS');

// Windows: match the installer's AppUserModelID (same as appId in package.json:
// "com.hrms.desktop") so desktop/toast notifications show "HRMS" as the source
// name instead of Electron's default "electron.app.HRMS". This must match the
// AUMID that electron-builder writes to the Start Menu shortcut on install.
if (process.platform === 'win32') {
    app.setAppUserModelId('com.hrms.desktop');
}

let mainWindow;
let tray;
let splashWindow;

// ---- Single instance lock ----
// Request the OS-level single-instance lock BEFORE any BrowserWindow is created
// (this call runs before app.whenReady). Whichever process wins becomes the
// primary instance; any later launch sees gotTheLock === false and exits
// immediately instead of opening a duplicate HRMS window/process/tray icon.
const gotTheLock = app.requestSingleInstanceLock();

// Runs inside the PRIMARY (lock-holding) instance whenever the user launches the
// app again (desktop shortcut, .exe, or another shortcut). Instead of creating a
// second window, restore + focus the already-running HRMS window. The existing
// session and app state are left untouched.
app.on('second-instance', () => {
    console.log("HRMS: second instance detected -> focusing existing window.");
    if (mainWindow && !mainWindow.isDestroyed()) {
        if (mainWindow.isMinimized()) {
            mainWindow.restore();
        }
        mainWindow.show();
        mainWindow.focus();
    }
});

// Load server configuration
let serverConfig = {
    url: 'http://192.168.1.131/hrms1/' // Your remote Linux server
};

const configPath = path.join(__dirname, 'server-config.json');
if (fs.existsSync(configPath)) {
    try {
        const configData = fs.readFileSync(configPath, 'utf8');
        serverConfig = JSON.parse(configData);
        console.log('Loaded server configuration:', serverConfig);
    } catch (error) {
        console.error('Error loading server config:', error);
    }
}

// ---- Auto clock-out + logout when the app exits / the OS shuts down ----
// Ask the loaded HRMS page to perform the clock-out + logout from its own
// authenticated session (api/idle.php, action=shutdown). The page request
// writes the CLOCK_OUT punch at the moment of shutdown and destroys the PHP
// session, so attendance net time stops exactly when the machine goes down.
// A 4-second cap guarantees we never block the OS shutdown.
let hrmsExitPending = false;
let hrmsExitSent = false;

// The clock-out is issued from here (the Electron MAIN process) using
// net.fetch(), NOT by running JS in the renderer. Windows gives minimal
// warning during a real shutdown / restart / logoff, and the renderer process
// can be torn down (or its network service taken offline) before a
// renderer-side fetch() can finish a round trip. net.fetch() uses Chromium's
// network stack against the DEFAULT session - the same session the HRMS window
// uses (no partition is set), so the authenticated PHPSESSID cookie is sent
// automatically, including HttpOnly cookies, without depending on the renderer
// being alive. File -> Quit goes through this exact same function, so existing
// behaviour is preserved and no attendance logic is duplicated.
function hrmsClockoutAndLogout() {
    if (hrmsExitSent) return Promise.resolve(false);
    hrmsExitSent = true;

    // Redundant fallback: tell the loaded page a shutdown is starting so its
    // pagehide sendBeacon can fire in parallel while our main-process request
    // runs. If the main request already logged the user out, the beacon is a
    // harmless no-op server-side ("Not logged in").
    try {
        if (mainWindow && !mainWindow.isDestroyed()) {
            mainWindow.webContents.send('hrms-shutdown-requested');
        }
    } catch (e) { /* ignore */ }

    try {
        const baseUrl = String(serverConfig.url || '').replace(/\/+$/, '');
        const shutdownUrl = baseUrl + '/api/idle.php';
        const payload = JSON.stringify({ action: 'shutdown', source: 'desktop' });

        return Promise.race([
            net.fetch(shutdownUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: payload
            })
                .then(function (resp) {
                    return resp.json().catch(function () { return {}; });
                })
                .then(function (data) {
                    console.log('HRMS auto clock-out on exit:', data);
                    return !!(data && data.success);
                }),
            new Promise(function (resolve) { setTimeout(function () { resolve(false); }, 4000); })
        ]).catch(function (err) {
            console.error('HRMS auto clock-out on exit failed:', err && err.message ? err.message : err);
            return false;
        });
    } catch (e) {
        // Never let a network hiccup block the app from quitting.
        console.error('HRMS auto clock-out on exit threw:', e && e.message ? e.message : e);
        return Promise.resolve(false);
    }
}

function quitWithAutoClockout() {
    if (hrmsExitPending) return;
    hrmsExitPending = true;
    app.isQuitting = true;
    hrmsClockoutAndLogout().finally(() => app.quit());
}

// ---- Idle Time Tracking (OS-level) ----
// powerMonitor.getSystemIdleTime() returns the number of seconds since the last
// keyboard or mouse input ANYWHERE on the operating system. Minimizing,
// reloading or switching away from the HRMS window does NOT reset it, so it is
// the correct source for "idle" regardless of what the user does with other apps.
ipcMain.handle('hrms-get-system-idle-time', () => powerMonitor.getSystemIdleTime());

// ---- Apps Module IPC Handlers ----
// Open external URL in default browser
ipcMain.handle('open-external', async (event, url) => {
    try {
        await shell.openExternal(url);
        return { success: true };
    } catch (error) {
        console.error('Error opening external URL:', error);
        return { success: false, message: 'Failed to open URL' };
    }
});

// Launch desktop application
ipcMain.handle('launch-app', async (event, appPath) => {
    try {
        // Validate the path is safe (basic validation)
        if (!appPath || typeof appPath !== 'string') {
            return { success: false, message: 'Invalid application path' };
        }

        // Check if file exists
        if (!fs.existsSync(appPath)) {
            return { success: false, message: 'Application not found. Please check the path.' };
        }

        // Use shell.openPath for safe execution (opens with default OS handler)
        await shell.openPath(appPath);
        return { success: true };
    } catch (error) {
        console.error('Error launching application:', error);
        return { success: false, message: 'Failed to launch application: ' + error.message };
    }
});

// Open file/folder in OS
ipcMain.handle('open-path', async (event, filePath) => {
    try {
        if (!filePath || typeof filePath !== 'string') {
            return { success: false, message: 'Invalid file path' };
        }

        if (!fs.existsSync(filePath)) {
            return { success: false, message: 'File or folder not found. Please check the path.' };
        }

        await shell.openPath(filePath);
        return { success: true };
    } catch (error) {
        console.error('Error opening path:', error);
        return { success: false, message: 'Failed to open file/folder: ' + error.message };
    }
});

// Browse for file/folder path
ipcMain.handle('browse-for-path', async (event, appType) => {
    try {
        let options = {
            properties: []
        };

        switch (appType) {
            case 'desktop_app':
                options.properties = ['openFile'];
                options.filters = [
                    { name: 'Applications', extensions: ['exe'] }
                ];
                break;
            case 'file':
                options.properties = ['openFile'];
                break;
            case 'folder':
                options.properties = ['openDirectory'];
                break;
            default:
                return { success: false, message: 'Invalid app type for browsing' };
        }

        const result = await dialog.showOpenDialog(mainWindow, options);

        if (result.canceled || result.filePaths.length === 0) {
            return { success: false, message: 'No file selected' };
        }

        return { success: true, path: result.filePaths[0] };
    } catch (error) {
        console.error('Error browsing for path:', error);
        return { success: false, message: 'Failed to browse: ' + error.message };
    }
});

function createSplashWindow() {
    splashWindow = new BrowserWindow({
        width: 500,
        height: 350,
        frame: false,
        resizable: false,
        movable: true,
        maximizable: false,
        minimizable: false,
        alwaysOnTop: true,
        center: true,
        transparent: false,
        show: false,
        icon: path.join(__dirname, 'assets', 'icon.png')
    });

    splashWindow.loadFile('splash.html');

    splashWindow.once('ready-to-show', () => {
        splashWindow.show();
    });
}

// Create System Tray
function createTray() {
    const trayIconPath = path.join(__dirname, 'assets', 'tray.png');
    const trayIcon = nativeImage.createFromPath(trayIconPath);
    tray = new Tray(trayIcon);
    
    const contextMenu = Menu.buildFromTemplate([
        {
            label: 'Show HRMS',
            click: () => {
                if (mainWindow) {
                    mainWindow.show();
                    mainWindow.focus();
                }
            }
        },
        {
            label: 'Refresh Connection',
            click: () => {
                if (mainWindow) {
                    mainWindow.reload();
                }
            }
        },
        {
            label: 'Quit HRMS',
            click: () => {
                app.quit();
            }
        }
    ]);
    
    tray.setToolTip('HRMS - Human Resource Management System');
    tray.setContextMenu(contextMenu);
    
    // Double-click to show window
    tray.on('double-click', () => {
        if (mainWindow) {
            mainWindow.show();
            mainWindow.focus();
        }
    });
}

// Create Main Window
function createWindow() {
    mainWindow = new BrowserWindow({
        width: 1400,
        height: 900,
        minWidth: 1024,
        minHeight: 768,
        webPreferences: {
            nodeIntegration: false,
            contextIsolation: true,
            enableRemoteModule: false,
            webSecurity: true,
            preload: path.join(__dirname, 'preload.js'),
            backgroundThrottling: false // keep the once-per-minute idle heartbeat firing when minimized
        },
        icon: path.join(__dirname, 'assets', 'tray.png'),
        title: 'HRMS - Human Resource Management System'
    });

    mainWindow.loadURL(serverConfig.url);
    mainWindow.show();

    // Uncomment for debugging
    // mainWindow.webContents.openDevTools();

    // Handle connection errors
    mainWindow.webContents.on('did-fail-load', (event, errorCode, errorDescription, validatedURL) => {
        console.error('Failed to load:', errorDescription);
        console.error('URL:', validatedURL);
        
        // Show error page or reconnect
        mainWindow.loadFile('connection-error.html');
    });

    // Monitor connection status
    mainWindow.webContents.on('did-start-loading', () => {
        console.log('Loading:', serverConfig.url);
    });

    mainWindow.webContents.on('did-stop-loading', () => {
        console.log('Finished loading:', serverConfig.url);
    });

    // Hide window instead of closing when user clicks X
    mainWindow.on('close', (event) => {
        if (!app.isQuitting) {
            event.preventDefault();
            mainWindow.hide();
            // Show notification that app is running in background
            if (process.platform === 'win32') {
                tray.displayBalloon({
                    iconType: 'info',
                    title: 'HRMS',
                    content: 'Application is running in background. Click tray icon to restore.'
                });
            }
        }
    });

    mainWindow.on('closed', () => {
        mainWindow = null;
    });

    // Open external links in browser
    mainWindow.webContents.setWindowOpenHandler(({ url }) => {
        require('electron').shell.openExternal(url);
        return { action: 'deny' };
    });
}

// Create Menu
function createMenu() {
    const template = [
        {
            label: 'File',
            submenu: [
                {
                    label: 'Reload',
                    accelerator: 'CmdOrCtrl+R',
                    click: () => mainWindow.reload()
                },
                {
                    label: 'Toggle Full Screen',
                    accelerator: 'F11',
                    click: () => {
                        mainWindow.setFullScreen(!mainWindow.isFullScreen());
                    }
                },
                {
                    label: 'Quit',
                    accelerator: 'CmdOrCtrl+Q',
                    click: () => app.quit()
                }
            ]
        },
        {
            label: 'Edit',
            submenu: [
                { role: 'undo', label: 'Undo' },
                { role: 'redo', label: 'Redo' },
                { type: 'separator' },
                { role: 'cut', label: 'Cut' },
                { role: 'copy', label: 'Copy' },
                { role: 'paste', label: 'Paste' }
            ]
        },
        {
            label: 'View',
            submenu: [
                { role: 'zoomIn', label: 'Zoom In' },
                { role: 'zoomOut', label: 'Zoom Out' },
                { role: 'resetZoom', label: 'Reset Zoom' }
            ]
        },
        {
            label: 'Help',
            submenu: [
                {
                    label: 'Connection Status',
                    click: () => {
                        console.log('Current server:', serverConfig.url);
                        if (mainWindow) {
                            mainWindow.reload();
                        }
                    }
                },
                {
                    label: 'About',
                    click: () => {
                        console.log("HRMS Desktop");
                    }
                }
            ]
        }
    ];

    const menu = Menu.buildFromTemplate(template);
    Menu.setApplicationMenu(menu);
}

// ---- Notification System ----
// Handle notification requests from renderer process
ipcMain.on('show-notification', (event, data) => {
    try {
        // Play notification sound
        playNotificationSound();

        const notification = new Notification({
            title: data.title || 'HRMS Notification',
            body: data.body || data.message || '',
            icon: getNotificationIcon(),
            silent: false // Play sound (Windows will play default notification sound)
        });

        notification.on('click', () => {
            // Focus the window
            if (mainWindow && !mainWindow.isDestroyed()) {
                mainWindow.show();
                mainWindow.focus();
            }

            // Send click event back to renderer
            if (event && event.sender) {
                event.sender.send('notification-clicked', {
                    notificationId: data.notificationId,
                    link: data.link
                });
            }
        });

        notification.show();
    } catch (error) {
        console.error('Error showing notification:', error);
    }
});

// Handle notification permission requests
ipcMain.handle('get-notification-permission', () => {
    return Notification.isSupported() ? 'granted' : 'denied';
});

ipcMain.handle('request-notification-permission', () => {
    // Electron doesn't require permission like browsers
    return 'granted';
});

// Get notification icon path (works in both dev and packaged)
function getNotificationIcon() {
    try {
        // Try to find icon.png, fallback to tray.png
        let iconPath;
        
        if (app.isPackaged) {
            // In packaged app, use resources path
            iconPath = path.join(process.resourcesPath, 'assets', 'icon.png');
            if (!fs.existsSync(iconPath)) {
                iconPath = path.join(process.resourcesPath, 'assets', 'tray.png');
            }
        } else {
            // In development, use local path
            iconPath = path.join(__dirname, 'assets', 'icon.png');
            if (!fs.existsSync(iconPath)) {
                iconPath = path.join(__dirname, 'assets', 'tray.png');
            }
        }

        if (fs.existsSync(iconPath)) {
            return iconPath;
        }
        
        return null;
    } catch (error) {
        console.error('Error getting notification icon:', error);
        return null;
    }
}

// Play notification sound (Windows system beep as fallback)
function playNotificationSound() {
    try {
        // Windows-specific system beep (works without external file)
        if (process.platform === 'win32') {
            // Use Windows API through a simple beep with proper escaping
            const { exec } = require('child_process');
            exec('powershell -Command "[Console]::Beep(800, 200)"', (error) => {
                if (error) {
                    console.error('Error playing Windows sound:', error);
                }
            });
        }
    } catch (error) {
        console.error('Error playing notification sound:', error);
    }
}

// App Ready
app.whenReady().then(() => {
    // If another HRMS instance already holds the single-instance lock, this
    // process is just a duplicate launch -> exit immediately WITHOUT creating a
    // window, tray icon, menu, or any notification/handlers. We intentionally use
    // app.exit() (not app.quit()) so the 'before-quit' event is NOT emitted, which
    // means the auto clock-out / logout path is skipped too -- the already-running
    // primary instance owns the user's session and must not be disrupted.
    if (!gotTheLock) {
        console.log("HRMS already running -> exiting this second instance.");
        app.exit(0);
        return;
    }

    console.log("Starting HRMS Desktop Application...");
    console.log("Connecting to server:", serverConfig.url);
    console.log("Platform:", process.platform);
    
    createTray();
    createWindow();
    createMenu();

    // Hide splash window after main window loads
    setTimeout(() => {
        if (splashWindow) {
            splashWindow.close();
        }
    }, 3000);

    app.on('activate', () => {
        if (BrowserWindow.getAllWindows().length === 0) {
            createWindow();
        }
    });
});

// Quit App
app.on('window-all-closed', () => {
    // Don't quit on Windows when window is closed - keep running in tray
    if (process.platform !== 'darwin') {
        // app.quit(); // Commented out to allow background running
    }
});

// OS is shutting down / restarting / logging off (Windows only). The app still
// has a short window to flush the final clock-out request.
app.on('session-end', () => {
    console.log('HRMS: OS session ending (shutdown/restart/logoff) -> auto clock-out');
    quitWithAutoClockout();
});

// Linux / macOS: system is about to reboot or shut down. preventDefault()
// signals Electron to delay shutdown so the app can exit cleanly (per Electron
// docs), letting the clock-out request finish before app.quit() closes us.
powerMonitor.on('shutdown', (event) => {
    console.log('HRMS: OS shutdown requested -> auto clock-out');
    if (event && typeof event.preventDefault === 'function') {
        try { event.preventDefault(); } catch (e) {}
    }
    quitWithAutoClockout();
});

// Handle quit properly - quitting the app (tray -> Quit HRMS, Ctrl+Q) also
// means leaving work, so clock out + logout first. Guarded so the OS-shutdown
// paths above never trigger a duplicate request.
app.on('before-quit', (event) => {
    app.isQuitting = true;
    if (hrmsExitPending) return; // already handled (session-end / shutdown path)
    event.preventDefault();
    quitWithAutoClockout();
});

// Handle certificate errors for internal servers
app.on('certificate-error', (event, webContents, url, error, certificate, callback) => {
    // Allow certificate errors for configured server URL
    if (url.startsWith(serverConfig.url.replace('http://', 'https://').replace('http://', '')) || 
        url.startsWith(serverConfig.url)) {
        event.preventDefault();
        callback(true);
    } else {
        callback(false);
    }
});
