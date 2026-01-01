/**
 * Hospital DICOM Viewer Pro - Desktop Edition
 * Electron Main Process
 * 
 * This file creates the native desktop window and manages the PHP server
 */

const { app, BrowserWindow, dialog, Menu, shell, ipcMain } = require('electron');
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
    const preloadPath = path.join(__dirname, 'preload.js');
    const fs = require('fs');
    console.log('========================================');
    console.log('[Electron] Preload path:', preloadPath);
    console.log('[Electron] Preload exists:', fs.existsSync(preloadPath));
    console.log('========================================');

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
            preload: preloadPath
        }
    });

    console.log('[Electron] Main window created with preload');

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
                {
                    role: 'toggleDevTools',
                    label: 'Developer Tools',
                    accelerator: 'F12'  // Add F12 as keyboard shortcut
                },
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
                    label: 'Test Electron Environment',
                    click: () => mainWindow.loadURL(`${APP_URL}/electron-test.html`)
                },
                { type: 'separator' },
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

    // FIX: Handle window focus to restore input field interactivity
    // This fixes the Electron bug where inputs become unclickable after focus loss
    mainWindow.on('focus', () => {
        // Force webContents to focus, which restores input interactivity
        if (mainWindow && mainWindow.webContents) {
            mainWindow.webContents.focus();
        }
    });

    // Handle external links and popups
    mainWindow.webContents.setWindowOpenHandler(({ url }) => {
        // Allow localhost URLs, about:blank (for popups/print), and blob URLs
        if (url.startsWith('http://localhost') || url.startsWith('about:') || url.startsWith('blob:')) {
            return {
                action: 'allow',
                overrideBrowserWindowOptions: {
                    webPreferences: {
                        nodeIntegration: false,
                        contextIsolation: true,
                        preload: path.join(__dirname, 'preload.js')
                    }
                }
            };
        }
        // Open external links in default browser
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
 * Check if setup wizard has been completed
 */
async function checkSetupStatus() {
    return new Promise((resolve, reject) => {
        const req = http.request({
            host: 'localhost',
            port: PHP_PORT,
            path: '/api/setup/status.php',
            method: 'GET',
            timeout: 5000
        }, (res) => {
            let data = '';
            res.on('data', chunk => data += chunk);
            res.on('end', () => {
                try {
                    const json = JSON.parse(data);
                    resolve(json);
                } catch (e) {
                    reject(new Error('Invalid JSON response'));
                }
            });
        });
        req.on('error', reject);
        req.on('timeout', () => {
            req.destroy();
            reject(new Error('Timeout'));
        });
        req.end();
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

        // Always start with login page
        // Login page has two tabs: Admin Login (for super admin) and License Key (for new activations)
        console.log('[Electron] Loading login page...');
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

// ============================================
// IPC Handlers for Custom Print Dialog
// ============================================

/**
 * Get list of system printers
 * Returns array of printer info objects
 */
ipcMain.handle('get-system-printers', async () => {
    try {
        if (!mainWindow || !mainWindow.webContents) {
            console.error('[Electron] Main window not available for printer query');
            return { success: false, error: 'Main window not available', printers: [] };
        }

        const printers = await mainWindow.webContents.getPrintersAsync();
        console.log(`[Electron] Found ${printers.length} system printers`);

        // Format printer info for renderer
        const formattedPrinters = printers.map(printer => ({
            name: printer.name,
            displayName: printer.displayName || printer.name,
            description: printer.description || '',
            status: printer.status,
            isDefault: printer.isDefault,
            options: printer.options || {}
        }));

        return { success: true, printers: formattedPrinters };
    } catch (error) {
        console.error('[Electron] Error getting printers:', error);
        return { success: false, error: error.message, printers: [] };
    }
});

/**
 * Print content to a specific printer
 * @param {Object} options - Print options
 * @param {string} options.printerName - Name of the printer to use
 * @param {string} options.htmlContent - HTML content to print
 * @param {Object} options.printSettings - Print settings (paperSize, orientation, etc.)
 */
ipcMain.handle('print-to-printer', async (event, options) => {
    return new Promise(async (resolve) => {
        const fs = require('fs');
        const os = require('os');
        let tempFilePath = null;

        try {
            const { printerName, htmlContent, printSettings = {} } = options;

            console.log(`[Electron] Printing to printer: ${printerName || 'default'}`);

            // Write HTML content to a temporary file instead of using data URL
            // This avoids the ERR_INVALID_URL error for large content with base64 images
            tempFilePath = path.join(os.tmpdir(), `dicom_print_${Date.now()}.html`);
            fs.writeFileSync(tempFilePath, htmlContent, 'utf8');
            console.log(`[Electron] Wrote print content to temp file: ${tempFilePath}`);

            // Create a hidden window for printing
            const printWindow = new BrowserWindow({
                show: false,
                width: 1200,
                height: 900,
                webPreferences: {
                    nodeIntegration: false,
                    contextIsolation: true
                }
            });

            // Load the HTML from temp file
            await printWindow.loadFile(tempFilePath);

            // Wait for content to fully load
            printWindow.webContents.on('did-finish-load', async () => {
                // Small delay to ensure rendering is complete
                await new Promise(r => setTimeout(r, 500));

                // Configure print options
                const electronPrintOptions = {
                    silent: true, // Skip system dialog
                    printBackground: true,
                    color: printSettings.colorMode !== 'grayscale',
                    margins: {
                        marginType: printSettings.margins || 'default'
                    },
                    landscape: printSettings.orientation === 'landscape',
                    copies: printSettings.copies || 1
                };

                // Set printer name if specified
                if (printerName && printerName !== 'default') {
                    electronPrintOptions.deviceName = printerName;
                }

                // Set paper size if specified
                if (printSettings.paperSize) {
                    // Map paper size names to dimensions
                    const paperSizes = {
                        'A4': { width: 210000, height: 297000 }, // microns
                        'A3': { width: 297000, height: 420000 },
                        'Letter': { width: 215900, height: 279400 },
                        'Legal': { width: 215900, height: 355600 }
                    };

                    if (paperSizes[printSettings.paperSize]) {
                        electronPrintOptions.pageSize = printSettings.paperSize;
                    }
                }

                console.log('[Electron] Print options:', electronPrintOptions);

                // Execute print
                printWindow.webContents.print(electronPrintOptions, (success, errorType) => {
                    if (success) {
                        console.log('[Electron] Print job sent successfully');
                        resolve({ success: true, message: 'Print job sent to printer' });
                    } else {
                        console.error('[Electron] Print failed:', errorType);
                        resolve({ success: false, error: errorType || 'Print failed' });
                    }

                    // Close the print window
                    printWindow.close();

                    // Delete temp file
                    if (tempFilePath && fs.existsSync(tempFilePath)) {
                        try {
                            fs.unlinkSync(tempFilePath);
                            console.log('[Electron] Deleted temp print file');
                        } catch (e) {
                            console.warn('[Electron] Could not delete temp file:', e.message);
                        }
                    }
                });
            });

            // Handle errors
            printWindow.webContents.on('did-fail-load', (event, errorCode, errorDescription) => {
                console.error('[Electron] Print window failed to load:', errorDescription);
                resolve({ success: false, error: errorDescription });
                printWindow.close();

                // Delete temp file on error
                if (tempFilePath && fs.existsSync(tempFilePath)) {
                    try {
                        fs.unlinkSync(tempFilePath);
                    } catch (e) { }
                }
            });

        } catch (error) {
            console.error('[Electron] Print error:', error);
            resolve({ success: false, error: error.message });

            // Delete temp file on error
            if (tempFilePath && fs.existsSync(tempFilePath)) {
                try {
                    fs.unlinkSync(tempFilePath);
                } catch (e) { }
            }
        }
    });
});

/**
 * Print current window content to a specific printer
 * @param {Object} options - Print options
 * @param {string} options.printerName - Name of the printer to use
 * @param {Object} options.printSettings - Print settings
 */
ipcMain.handle('print-current-to-printer', async (event, options) => {
    return new Promise((resolve) => {
        try {
            const { printerName, printSettings = {} } = options;
            const senderWindow = BrowserWindow.fromWebContents(event.sender);

            if (!senderWindow) {
                resolve({ success: false, error: 'Window not found' });
                return;
            }

            console.log(`[Electron] Printing current content to: ${printerName || 'default'}`);

            const electronPrintOptions = {
                silent: true,
                printBackground: true,
                color: printSettings.colorMode !== 'grayscale',
                landscape: printSettings.orientation === 'landscape',
                copies: printSettings.copies || 1
            };

            if (printerName && printerName !== 'default') {
                electronPrintOptions.deviceName = printerName;
            }

            if (printSettings.paperSize) {
                electronPrintOptions.pageSize = printSettings.paperSize;
            }

            senderWindow.webContents.print(electronPrintOptions, (success, errorType) => {
                if (success) {
                    console.log('[Electron] Print job sent successfully');
                    resolve({ success: true, message: 'Print job sent to printer' });
                } else {
                    console.error('[Electron] Print failed:', errorType);
                    resolve({ success: false, error: errorType || 'Print failed' });
                }
            });

        } catch (error) {
            console.error('[Electron] Print error:', error);
            resolve({ success: false, error: error.message });
        }
    });
});

/**
 * Bring main window to front
 */
ipcMain.handle('focus-main-window', async () => {
    try {
        if (mainWindow && !mainWindow.isDestroyed()) {
            console.log('[Electron] Bringing main window to front...');
            if (mainWindow.isMinimized()) {
                mainWindow.restore();
            }
            mainWindow.focus();
            mainWindow.show();
            mainWindow.moveTop();
            return { success: true };
        }
        return { success: false, error: 'Main window not available' };
    } catch (error) {
        console.error('[Electron] Error focusing main window:', error);
        return { success: false, error: error.message };
    }
});

// Handle uncaught exceptions
process.on('uncaughtException', (error) => {
    console.error('[Electron] Uncaught exception:', error);
    dialog.showErrorBox('Error', error.message);
});
