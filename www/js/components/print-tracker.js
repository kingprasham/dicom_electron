/**
 * Print Tracker - Client-side print tracking with offline support
 *
 * Features:
 * - Log prints to server
 * - Offline queue using IndexedDB
 * - Automatic sync when online
 * - Integration with PrintManager
 */

window.DICOM_VIEWER = window.DICOM_VIEWER || {};

window.DICOM_VIEWER.PrintTracker = class PrintTracker {
    constructor() {
        this.basePath = document.querySelector('meta[name="base-path"]')?.content || '';
        this.dbName = 'DicomPrintQueue';
        this.dbVersion = 1;
        this.db = null;
        this.isOnline = navigator.onLine;
        this.syncInterval = 5 * 60 * 1000; // 5 minutes
        this.syncTimer = null;

        // Location info for print tracking
        this.locationInfo = null;
        this.activationId = null;

        this.init();
    }

    async init() {
        await this.openDatabase();
        await this.fetchMachineLocation(); // Fetch machine's assigned location
        this.setupEventListeners();
        this.startSyncTimer();

        // Initial sync check
        if (this.isOnline) {
            this.syncPendingPrints();
        }

        console.log('PrintTracker initialized', this.locationInfo ? `(Location: ${this.locationInfo.location_name})` : '(No location assigned)');
    }

    /**
     * Fetch current machine's assigned location
     */
    async fetchMachineLocation() {
        try {
            const response = await fetch(`${this.basePath}/api/locations/current-machine.php`);
            if (response.ok) {
                const data = await response.json();
                if (data.success && data.location_id) {
                    this.locationInfo = {
                        location_id: data.location_id,
                        location_code: data.location_code,
                        location_name: data.location_name,
                        department: data.department
                    };
                    this.activationId = data.activation_id;
                }
            }
        } catch (error) {
            console.warn('Could not fetch machine location:', error);
        }
    }

    /**
     * Open IndexedDB for offline print queue
     */
    async openDatabase() {
        return new Promise((resolve, reject) => {
            const request = indexedDB.open(this.dbName, this.dbVersion);

            request.onerror = () => {
                console.error('Failed to open IndexedDB:', request.error);
                reject(request.error);
            };

            request.onsuccess = () => {
                this.db = request.result;
                resolve(this.db);
            };

            request.onupgradeneeded = (event) => {
                const db = event.target.result;

                // Print queue store
                if (!db.objectStoreNames.contains('printQueue')) {
                    const store = db.createObjectStore('printQueue', {
                        keyPath: 'id',
                        autoIncrement: true
                    });
                    store.createIndex('print_job_id', 'print_job_id', { unique: true });
                    store.createIndex('status', 'status', { unique: false });
                    store.createIndex('created_at', 'created_at', { unique: false });
                }

                // Sync status store
                if (!db.objectStoreNames.contains('syncStatus')) {
                    db.createObjectStore('syncStatus', { keyPath: 'key' });
                }
            };
        });
    }

    /**
     * Setup network event listeners
     */
    setupEventListeners() {
        window.addEventListener('online', () => {
            this.isOnline = true;
            console.log('Network online - syncing prints');
            this.syncPendingPrints();
            this.updateOfflineIndicator(false);
        });

        window.addEventListener('offline', () => {
            this.isOnline = false;
            console.log('Network offline - prints will be queued');
            this.updateOfflineIndicator(true);
        });

        // Sync when page becomes visible
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden && this.isOnline) {
                this.syncPendingPrints();
            }
        });
    }

    /**
     * Start periodic sync timer
     */
    startSyncTimer() {
        if (this.syncTimer) {
            clearInterval(this.syncTimer);
        }

        this.syncTimer = setInterval(() => {
            if (this.isOnline) {
                this.syncPendingPrints();
            }
        }, this.syncInterval);
    }

    /**
     * Generate unique print job ID
     */
    generatePrintJobId() {
        const timestamp = Date.now().toString(36);
        const random = Math.random().toString(36).substring(2, 10);
        return `PJ-${timestamp}-${random}`;
    }

    /**
     * Log a print job
     * @param {Object} printData Print job details
     * @returns {Promise<Object>} Result with print_job_id
     */
    async logPrint(printData) {
        console.log('[DEBUG PrintTracker] logPrint called with:', printData);
        const printJobId = printData.print_job_id || this.generatePrintJobId();

        const printRecord = {
            print_job_id: printJobId,
            study_uid: printData.study_uid || null,
            patient_id: printData.patient_id || null,
            patient_name: printData.patient_name || null,
            paper_size: printData.paper_size || 'A4',
            orientation: printData.orientation || 'landscape',
            copies: printData.copies || 1,
            pages_per_copy: printData.pages_per_copy || 1,
            total_pages: printData.total_pages || 1,
            color_mode: printData.color_mode || 'grayscale',
            quality: printData.quality || 'high',
            printer_name: printData.printer_name || 'Default',
            printer_type: printData.printer_type || 'local',
            layout_type: printData.layout_type || '1x1',
            print_type: printData.print_type || 'image', // 'image' or 'report'
            include_patient_info: printData.include_patient_info ?? 1,
            include_annotations: printData.include_annotations ?? 1,
            include_measurements: printData.include_measurements ?? 1,
            // Location tracking for billing
            location_id: this.locationInfo?.location_id || null,
            activation_id: this.activationId || null,
            status: 'completed', // Set as completed since browser can't track actual print status
            created_at: new Date().toISOString()
        };

        console.log('[DEBUG PrintTracker] printRecord created:', printRecord);
        console.log('[DEBUG PrintTracker] isOnline:', this.isOnline);

        if (this.isOnline) {
            // Try to log directly to server
            try {
                console.log('[DEBUG PrintTracker] Attempting logToServer...');
                const result = await this.logToServer(printRecord);
                console.log('[DEBUG PrintTracker] logToServer result:', result);
                if (result.success) {
                    return result;
                }
            } catch (error) {
                console.warn('[DEBUG PrintTracker] Server logging failed, queuing offline:', error);
            }
        }

        // Queue for offline sync
        console.log('[DEBUG PrintTracker] Queuing for offline sync');
        await this.queueOfflinePrint(printRecord);
        return {
            success: true,
            print_job_id: printJobId,
            offline: true,
            message: 'Print queued for sync'
        };
    }

    /**
     * Log print to server
     */
    async logToServer(printData) {
        const url = `${this.basePath}/api/print/log.php`;
        console.log('[DEBUG PrintTracker] logToServer URL:', url);
        console.log('[DEBUG PrintTracker] logToServer body:', JSON.stringify(printData));

        const response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(printData)
        });

        console.log('[DEBUG PrintTracker] logToServer response status:', response.status);

        if (!response.ok) {
            throw new Error(`Server error: ${response.status}`);
        }

        const text = await response.text();
        try {
            const json = JSON.parse(text);
            console.log('[DEBUG PrintTracker] logToServer response JSON:', json);
            return json;
        } catch (e) {
            console.error('[DEBUG PrintTracker] Invalid JSON response:', text);
            throw new Error(`Invalid JSON response: ${text.substring(0, 50)}...`);
        }
    }

    /**
     * Update print status
     */
    async updatePrintStatus(printJobId, status, errorMessage = null) {
        if (this.isOnline) {
            try {
                const response = await fetch(`${this.basePath}/api/print/log.php`, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        print_job_id: printJobId,
                        status: status,
                        error_message: errorMessage
                    })
                });
                return await response.json();
            } catch (error) {
                console.warn('Failed to update status:', error);
            }
        }

        // Update in local queue
        await this.updateLocalPrintStatus(printJobId, status);
        return { success: true, offline: true };
    }

    /**
     * Queue print for offline sync
     */
    async queueOfflinePrint(printRecord) {
        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction(['printQueue'], 'readwrite');
            const store = transaction.objectStore('printQueue');

            printRecord.sync_status = 'pending';
            printRecord.sync_attempts = 0;

            const request = store.add(printRecord);

            request.onsuccess = () => {
                this.updatePendingCount();
                resolve({ id: request.result });
            };

            request.onerror = () => {
                reject(request.error);
            };
        });
    }

    /**
     * Update local print status
     */
    async updateLocalPrintStatus(printJobId, status) {
        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction(['printQueue'], 'readwrite');
            const store = transaction.objectStore('printQueue');
            const index = store.index('print_job_id');

            const request = index.get(printJobId);

            request.onsuccess = () => {
                const record = request.result;
                if (record) {
                    record.status = status;
                    record.updated_at = new Date().toISOString();
                    store.put(record);
                }
                resolve();
            };

            request.onerror = () => reject(request.error);
        });
    }

    /**
     * Sync pending prints to server
     */
    async syncPendingPrints() {
        if (!this.isOnline || !this.db) return;

        const pendingPrints = await this.getPendingPrints();

        if (pendingPrints.length === 0) return;

        console.log(`Syncing ${pendingPrints.length} pending prints...`);

        let synced = 0;
        let failed = 0;

        for (const print of pendingPrints) {
            try {
                print.is_offline = 1;
                const result = await this.logToServer(print);

                if (result.success) {
                    await this.markAsSynced(print.id);
                    synced++;
                } else {
                    await this.markSyncFailed(print.id, result.error);
                    failed++;
                }
            } catch (error) {
                await this.markSyncFailed(print.id, error.message);
                failed++;
            }
        }

        console.log(`Sync complete: ${synced} synced, ${failed} failed`);
        this.updatePendingCount();

        // Clean up old synced records
        this.cleanupSyncedRecords();
    }

    /**
     * Get pending prints from IndexedDB
     */
    async getPendingPrints() {
        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction(['printQueue'], 'readonly');
            const store = transaction.objectStore('printQueue');
            const index = store.index('status');

            const request = index.getAll(IDBKeyRange.only('queued'));

            request.onsuccess = () => {
                // Filter to only get pending sync records
                const results = request.result.filter(r => r.sync_status === 'pending');
                resolve(results);
            };

            request.onerror = () => reject(request.error);
        });
    }

    /**
     * Mark record as synced
     */
    async markAsSynced(id) {
        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction(['printQueue'], 'readwrite');
            const store = transaction.objectStore('printQueue');

            const request = store.get(id);

            request.onsuccess = () => {
                const record = request.result;
                if (record) {
                    record.sync_status = 'synced';
                    record.synced_at = new Date().toISOString();
                    store.put(record);
                }
                resolve();
            };

            request.onerror = () => reject(request.error);
        });
    }

    /**
     * Mark sync as failed
     */
    async markSyncFailed(id, error) {
        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction(['printQueue'], 'readwrite');
            const store = transaction.objectStore('printQueue');

            const request = store.get(id);

            request.onsuccess = () => {
                const record = request.result;
                if (record) {
                    record.sync_attempts = (record.sync_attempts || 0) + 1;
                    record.sync_error = error;
                    record.last_sync_attempt = new Date().toISOString();

                    // Mark as failed after 5 attempts
                    if (record.sync_attempts >= 5) {
                        record.sync_status = 'failed';
                    }

                    store.put(record);
                }
                resolve();
            };

            request.onerror = () => reject(request.error);
        });
    }

    /**
     * Clean up old synced records (keep last 7 days)
     */
    async cleanupSyncedRecords() {
        const cutoffDate = new Date();
        cutoffDate.setDate(cutoffDate.getDate() - 7);

        return new Promise((resolve, reject) => {
            const transaction = this.db.transaction(['printQueue'], 'readwrite');
            const store = transaction.objectStore('printQueue');

            const request = store.openCursor();
            let deleted = 0;

            request.onsuccess = (event) => {
                const cursor = event.target.result;
                if (cursor) {
                    const record = cursor.value;
                    if (record.sync_status === 'synced' &&
                        new Date(record.synced_at) < cutoffDate) {
                        cursor.delete();
                        deleted++;
                    }
                    cursor.continue();
                } else {
                    console.log(`Cleaned up ${deleted} old synced records`);
                    resolve(deleted);
                }
            };

            request.onerror = () => reject(request.error);
        });
    }

    /**
     * Get pending print count
     */
    async getPendingCount() {
        const pending = await this.getPendingPrints();
        return pending.length;
    }

    /**
     * Update pending count indicator
     */
    async updatePendingCount() {
        const count = await this.getPendingCount();

        // Update UI indicator if exists
        const indicator = document.getElementById('print-sync-indicator');
        if (indicator) {
            if (count > 0) {
                indicator.textContent = count;
                indicator.style.display = 'inline-block';
            } else {
                indicator.style.display = 'none';
            }
        }

        // Also update offline indicator
        this.updateOfflineIndicator(!this.isOnline);
    }

    /**
     * Update offline indicator
     */
    updateOfflineIndicator(isOffline) {
        const offlineIndicator = document.getElementById('offline-indicator');
        if (offlineIndicator) {
            offlineIndicator.style.display = isOffline ? 'flex' : 'none';
        }
    }

    /**
     * Get sync status
     */
    async getSyncStatus() {
        const pendingPrints = await this.getPendingPrints();

        return {
            isOnline: this.isOnline,
            pendingCount: pendingPrints.length,
            lastSync: localStorage.getItem('lastPrintSync'),
            syncInterval: this.syncInterval
        };
    }

    /**
     * Force sync now
     */
    async forceSyncNow() {
        if (!this.isOnline) {
            return { success: false, error: 'Currently offline' };
        }

        await this.syncPendingPrints();
        localStorage.setItem('lastPrintSync', new Date().toISOString());

        return { success: true };
    }

    /**
     * Get print statistics
     */
    async getStats(dateFrom, dateTo) {
        try {
            const params = new URLSearchParams({
                type: 'summary',
                date_from: dateFrom || new Date(Date.now() - 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0],
                date_to: dateTo || new Date().toISOString().split('T')[0]
            });

            const response = await fetch(`${this.basePath}/api/print/stats.php?${params}`);
            return await response.json();
        } catch (error) {
            console.error('Failed to fetch stats:', error);
            return { success: false, error: error.message };
        }
    }
};

// Auto-initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    if (!window.DICOM_VIEWER.printTracker) {
        window.DICOM_VIEWER.printTracker = new window.DICOM_VIEWER.PrintTracker();
    }
});