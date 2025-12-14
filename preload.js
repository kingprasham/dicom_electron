/**
 * Preload script - runs before web page loads
 * Exposes safe APIs to the renderer process
 */

const { contextBridge, ipcRenderer } = require('electron');

// Expose safe APIs to the renderer
contextBridge.exposeInMainWorld('electronAPI', {
    // App info
    isElectron: true,
    isDesktop: true,

    // Platform info
    platform: process.platform,

    // Version info
    versions: {
        electron: process.versions.electron,
        node: process.versions.node,
        chrome: process.versions.chrome
    }
});

console.log('[Preload] DICOM Viewer Desktop APIs loaded');
