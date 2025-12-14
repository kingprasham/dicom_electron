/**
 * Hospital DICOM Viewer Pro - Desktop Edition
 * Electron Main Process
 * 
 * This file creates the native desktop window and manages the PHP server
 */

const { app, BrowserWindow, dialog, Menu, shell } = require('electron');
const path = require('path');
const { spawn, exec } = require('child_process');
const http = require('http');

// Configuration
const PHP_PORT = 8080;
const ORTHANC_PORT = 8042;
const APP_URL = `http://localhost:${PHP_PORT}`;

// Global references
let mainWindow = null;
let splashWindow = null;
let phpProcess = null;

// Determine if running in development or production
const isDev = !app.isPackaged;
const appPath = isDev ? __dirname : path.join(process.resourcesPath);

// PHP and WWW paths
const phpPath = isDev
    ? 'C:\\xampp\\php\\php.exe'  // Use XAMPP PHP in development
    : path.join(appPath, 'php', 'php.exe');

const wwwPath = isDev
    ? path.join(__dirname, 'www')
    : path.join(appPath, 'www');

console.log('[Electron] Starting DICOM Viewer Desktop...');
console.log('[Electron] App Path:', appPath);
console.log('[Electron] PHP Path:', phpPath);
console.log('[Electron] WWW Path:', wwwPath);

/**
 * Create splash screen
 */
function createSplashWindow() {
    splashWindow = new BrowserWindow({
        width: 400,
        height: 300,
        transparent: true,
        frame: false,
        alwaysOnTop: true,
        webPreferences: {
            nodeIntegration: false,
            contextIsolation: true
        }
    });

    splashWindow.loadFile(path.join(__dirname, 'splash.html'));
    splashWindow.center();
}

/**
 * Create main application window
 */
function createMainWindow() {
    mainWindow = new BrowserWindow({
        width: 1400,
        height: 900,
        minWidth: 1024,
        minHeight: 768,
        show: false, // Don't show until ready
        icon: path.join(__dirname, 'icon.ico'),
        webPreferences: {
            nodeIntegration: false,
            contextIsolation: true,
            preload: path.join(__dirname, 'preload.js')
        }
    });

    // Create application menu
    const menuTemplate = [
        {
            label: 'File',
            submenu: [
                { label: 'Dashboard', click: () => mainWindow.loadURL(`${APP_URL}/dashboard.php`) },
                { label: 'Patients', click: () => mainWindow.loadURL(`${APP_URL}/patients.php`) },
                { type: 'separator' },
                { label: 'Settings', click: () => mainWindow.loadURL(`${APP_URL}/admin/settings.php`) },
                { type: 'separator' },
                { role: 'quit', label: 'Exit' }
            ]
        },
        {
            label: 'View',
            submenu: [
                { role: 'reload' },
                { role: 'forceReload' },
                { type: 'separator' },
                { role: 'resetZoom' },
                { role: 'zoomIn' },
                { role: 'zoomOut' },
                { type: 'separator' },
                { role: 'togglefullscreen' }
            ]
        },
        {
            label: 'Tools',
            submenu: [
                { role: 'toggleDevTools', label: 'Developer Tools' },
                { type: 'separator' },
                {
                    label: 'Open Orthanc',
                    click: () => shell.openExternal(`http://localhost:${ORTHANC_PORT}`)
                },
                {
                    label: 'View Logs',
                    click: () => shell.openPath(path.join(wwwPath, '..', 'data', 'logs'))
                }
            ]
        },
        {
            label: 'Help',
            submenu: [
                {
                    label: 'About',
                    click: () => {
                        dialog.showMessageBox(mainWindow, {
                            type: 'info',
                            title: 'About DICOM Viewer',
                            message: 'Hospital DICOM Viewer Pro',
                            detail: 'Version 2.0.0 - Desktop Edition\n\nOffline DICOM viewing and analysis for healthcare professionals.'
                        });
                    }
                }
            ]
        }
    ];

    const menu = Menu.buildFromTemplate(menuTemplate);
    Menu.setApplicationMenu(menu);

    // Load the app when ready
    mainWindow.once('ready-to-show', () => {
        if (splashWindow) {
            splashWindow.destroy();
            splashWindow = null;
        }
        mainWindow.show();
        mainWindow.focus();
    });

    // Handle window closed
    mainWindow.on('closed', () => {
        mainWindow = null;
    });

    // Handle external links
    mainWindow.webContents.setWindowOpenHandler(({ url }) => {
        if (url.startsWith('http://localhost')) {
            return { action: 'allow' };
        }
        shell.openExternal(url);
        return { action: 'deny' };
    });
}

/**
 * Check if a port is available
 */
function checkPort(port) {
    return new Promise((resolve) => {
        const req = http.request({ host: 'localhost', port, timeout: 2000 }, (res) => {
            resolve(true); // Port is in use (server responding)
        });
        req.on('error', () => resolve(false)); // Port is free or not responding
        req.on('timeout', () => {
            req.destroy();
            resolve(false);
        });
        req.end();
    });
}

/**
 * Wait for PHP server to be ready
 */
async function waitForServer(maxAttempts = 30) {
    for (let i = 0; i < maxAttempts; i++) {
        const isReady = await checkPort(PHP_PORT);
        if (isReady) {
            console.log('[Electron] PHP server is ready!');
            return true;
        }
        console.log(`[Electron] Waiting for PHP server... (${i + 1}/${maxAttempts})`);
        await new Promise(resolve => setTimeout(resolve, 500));
    }
    return false;
}

/**
 * Start PHP built-in server
 */
function startPhpServer() {
    return new Promise((resolve, reject) => {
        console.log('[Electron] Starting PHP server...');
        console.log(`[Electron] Command: ${phpPath} -S localhost:${PHP_PORT} -t "${wwwPath}"`);

        phpProcess = spawn(phpPath, [
            '-S', `localhost:${PHP_PORT}`,
            '-t', wwwPath
        ], {
            cwd: wwwPath,
            stdio: ['ignore', 'pipe', 'pipe']
        });

        phpProcess.stdout.on('data', (data) => {
            console.log(`[PHP] ${data.toString().trim()}`);
        });

        phpProcess.stderr.on('data', (data) => {
            console.log(`[PHP] ${data.toString().trim()}`);
        });

        phpProcess.on('error', (err) => {
            console.error('[Electron] Failed to start PHP server:', err);
            reject(err);
        });

        phpProcess.on('close', (code) => {
            console.log(`[Electron] PHP server exited with code ${code}`);
            phpProcess = null;
        });

        // Give the server a moment to start
        setTimeout(() => resolve(), 1000);
    });
}

/**
 * Stop PHP server
 */
function stopPhpServer() {
    if (phpProcess) {
        console.log('[Electron] Stopping PHP server...');
        phpProcess.kill('SIGTERM');
        phpProcess = null;
    }
}

/**
 * Check if MySQL is running
 */
async function checkMySQL() {
    return new Promise((resolve) => {
        exec('C:\\xampp\\mysql\\bin\\mysql.exe -u root -e "SELECT 1"', (error) => {
            resolve(!error);
        });
    });
}

/**
 * Main application startup
 */
async function startApp() {
    try {
        createSplashWindow();

        // Check MySQL
        const mysqlRunning = await checkMySQL();
        if (!mysqlRunning) {
            const result = await dialog.showMessageBox({
                type: 'warning',
                title: 'MySQL Required',
                message: 'MySQL is not running.',
                detail: 'Please start MySQL from XAMPP Control Panel, then click Retry.',
                buttons: ['Retry', 'Exit']
            });

            if (result.response === 1) {
                app.quit();
                return;
            }

            // Check again
            const retryMysql = await checkMySQL();
            if (!retryMysql) {
                dialog.showErrorBox('Error', 'MySQL is still not running. Please start it and restart the application.');
                app.quit();
                return;
            }
        }

        // Start PHP server
        await startPhpServer();

        // Wait for server to be ready
        const serverReady = await waitForServer();
        if (!serverReady) {
            dialog.showErrorBox('Error', 'Failed to start PHP server. Please check that PHP is installed.');
            app.quit();
            return;
        }

        // Create main window
        createMainWindow();

        // Load the app
        mainWindow.loadURL(`${APP_URL}/login.php`);

    } catch (error) {
        console.error('[Electron] Startup error:', error);
        dialog.showErrorBox('Startup Error', error.message);
        app.quit();
    }
}

// App ready
app.whenReady().then(startApp);

// Quit when all windows are closed
app.on('window-all-closed', () => {
    stopPhpServer();
    app.quit();
});

// Handle app activation (macOS)
app.on('activate', () => {
    if (BrowserWindow.getAllWindows().length === 0) {
        startApp();
    }
});

// Cleanup on quit
app.on('before-quit', () => {
    stopPhpServer();
});

// Handle uncaught exceptions
process.on('uncaughtException', (error) => {
    console.error('[Electron] Uncaught exception:', error);
    dialog.showErrorBox('Error', error.message);
});
