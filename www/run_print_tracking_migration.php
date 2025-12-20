<?php
/**
 * Run Print Tracking System Migration
 * This is a simplified, robust version that creates all required tables
 */
define('DICOM_VIEWER', true);
require_once __DIR__ . '/includes/config.php';

echo "<h2>Print Tracking System Migration</h2>";
echo "<pre>";

$db = getDbConnection();

$success = 0;
$skipped = 0;
$failed = 0;

function runQuery($db, $name, $sql) {
    global $success, $skipped, $failed;
    
    echo "Creating $name... ";
    
    if ($db->query($sql) === false) {
        $error = $db->error;
        if (strpos($error, 'already exists') !== false || strpos($error, 'Duplicate') !== false) {
            echo "SKIPPED (already exists)\n";
            $skipped++;
        } else {
            echo "FAILED: $error\n";
            $failed++;
        }
    } else {
        echo "SUCCESS\n";
        $success++;
    }
}

// 1. Locations table
runQuery($db, "locations", "CREATE TABLE IF NOT EXISTS locations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    license_id INT NULL,
    location_code VARCHAR(50) NOT NULL,
    location_name VARCHAR(100) NOT NULL,
    department VARCHAR(100),
    floor VARCHAR(20),
    building VARCHAR(100),
    description TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_license (license_id),
    INDEX idx_location_code (location_code),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// 2. Machine locations
runQuery($db, "machine_locations", "CREATE TABLE IF NOT EXISTS machine_locations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    activation_id INT UNSIGNED NOT NULL,
    location_id INT NOT NULL,
    assigned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    assigned_by INT,
    is_current TINYINT(1) DEFAULT 1,
    notes TEXT,
    INDEX idx_activation (activation_id),
    INDEX idx_location (location_id),
    INDEX idx_current (is_current)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// 3. Print logs
runQuery($db, "print_logs", "CREATE TABLE IF NOT EXISTS print_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    license_key VARCHAR(32),
    machine_id VARCHAR(64) NOT NULL,
    activation_id INT UNSIGNED,
    location_id INT,
    user_id INT,
    user_name VARCHAR(100),
    print_job_id VARCHAR(64) NOT NULL,
    study_uid VARCHAR(128),
    patient_id VARCHAR(64),
    patient_name VARCHAR(255),
    paper_size VARCHAR(20) NOT NULL DEFAULT 'A4',
    orientation VARCHAR(20) DEFAULT 'landscape',
    copies INT DEFAULT 1,
    pages_per_copy INT DEFAULT 1,
    total_pages INT NOT NULL DEFAULT 1,
    color_mode VARCHAR(20) DEFAULT 'grayscale',
    quality VARCHAR(20) DEFAULT 'high',
    printer_name VARCHAR(100),
    printer_type VARCHAR(20) DEFAULT 'local',
    layout_type VARCHAR(50),
    include_patient_info TINYINT(1) DEFAULT 1,
    include_annotations TINYINT(1) DEFAULT 1,
    include_measurements TINYINT(1) DEFAULT 1,
    status ENUM('queued', 'printing', 'completed', 'failed', 'cancelled') DEFAULT 'queued',
    error_message TEXT,
    queued_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME,
    completed_at DATETIME,
    is_offline_print TINYINT(1) DEFAULT 0,
    offline_queue_id VARCHAR(64),
    synced_at DATETIME,
    billable TINYINT(1) DEFAULT 1,
    billed TINYINT(1) DEFAULT 0,
    invoice_id INT,
    cost_per_page DECIMAL(10,4),
    total_cost DECIMAL(10,2),
    INDEX idx_license_date (license_key, queued_at),
    INDEX idx_machine_date (machine_id, queued_at),
    INDEX idx_location_date (location_id, queued_at),
    INDEX idx_user_date (user_id, queued_at),
    INDEX idx_status (status),
    INDEX idx_billable (billable, billed),
    INDEX idx_offline (is_offline_print, synced_at),
    INDEX idx_print_job (print_job_id),
    INDEX idx_invoice (invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// 4. Print pricing
runQuery($db, "print_pricing", "CREATE TABLE IF NOT EXISTS print_pricing (
    id INT AUTO_INCREMENT PRIMARY KEY,
    license_id INT,
    paper_size VARCHAR(20) NOT NULL,
    color_mode VARCHAR(20) NOT NULL DEFAULT 'any',
    cost_per_page DECIMAL(10,4) NOT NULL,
    currency VARCHAR(3) DEFAULT 'INR',
    effective_from DATE NOT NULL,
    effective_until DATE,
    description VARCHAR(255),
    created_by INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_license (license_id),
    INDEX idx_paper_color (paper_size, color_mode),
    INDEX idx_effective (effective_from, effective_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// 5. Invoices
runQuery($db, "invoices", "CREATE TABLE IF NOT EXISTS invoices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    license_id INT NOT NULL,
    invoice_number VARCHAR(50) NOT NULL UNIQUE,
    billing_period_start DATE NOT NULL,
    billing_period_end DATE NOT NULL,
    total_prints INT NOT NULL DEFAULT 0,
    total_pages INT NOT NULL DEFAULT 0,
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    discount_percentage DECIMAL(5,2) DEFAULT 0.00,
    discount_amount DECIMAL(12,2) DEFAULT 0.00,
    tax_percentage DECIMAL(5,2) DEFAULT 0.00,
    tax_amount DECIMAL(12,2) DEFAULT 0.00,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    currency VARCHAR(3) DEFAULT 'INR',
    status ENUM('draft', 'generated', 'sent', 'paid', 'overdue', 'cancelled') DEFAULT 'draft',
    due_date DATE,
    paid_date DATE,
    payment_reference VARCHAR(100),
    breakdown_by_location JSON,
    breakdown_by_paper JSON,
    breakdown_by_user JSON,
    breakdown_by_day JSON,
    notes TEXT,
    internal_notes TEXT,
    generated_by INT,
    generated_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_license (license_id),
    INDEX idx_status (status),
    INDEX idx_period (billing_period_start, billing_period_end),
    INDEX idx_invoice_number (invoice_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// 6. Invoice items
runQuery($db, "invoice_items", "CREATE TABLE IF NOT EXISTS invoice_items (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    item_type ENUM('print', 'adjustment', 'discount', 'tax', 'other') DEFAULT 'print',
    description VARCHAR(255) NOT NULL,
    location_id INT,
    location_name VARCHAR(100),
    paper_size VARCHAR(20),
    color_mode VARCHAR(20),
    quantity INT NOT NULL DEFAULT 0,
    unit_price DECIMAL(10,4) NOT NULL DEFAULT 0,
    total_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    sort_order INT DEFAULT 0,
    INDEX idx_invoice (invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// 7. Offline sync queue
runQuery($db, "offline_sync_queue", "CREATE TABLE IF NOT EXISTS offline_sync_queue (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    machine_id VARCHAR(64) NOT NULL,
    data_type VARCHAR(50) NOT NULL,
    payload JSON NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    sync_attempts INT DEFAULT 0,
    last_sync_attempt DATETIME,
    synced_at DATETIME,
    sync_status ENUM('pending', 'syncing', 'synced', 'failed') DEFAULT 'pending',
    sync_error TEXT,
    INDEX idx_machine_status (machine_id, sync_status),
    INDEX idx_status (sync_status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// 8. Daily print stats
runQuery($db, "daily_print_stats", "CREATE TABLE IF NOT EXISTS daily_print_stats (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    stat_date DATE NOT NULL,
    license_key VARCHAR(32),
    machine_id VARCHAR(64),
    location_id INT,
    user_id INT,
    total_prints INT DEFAULT 0,
    total_pages INT DEFAULT 0,
    successful_prints INT DEFAULT 0,
    failed_prints INT DEFAULT 0,
    cancelled_prints INT DEFAULT 0,
    a4_pages INT DEFAULT 0,
    a3_pages INT DEFAULT 0,
    letter_pages INT DEFAULT 0,
    other_pages INT DEFAULT 0,
    grayscale_pages INT DEFAULT 0,
    color_pages INT DEFAULT 0,
    total_cost DECIMAL(12,2) DEFAULT 0.00,
    billed_cost DECIMAL(12,2) DEFAULT 0.00,
    unbilled_cost DECIMAL(12,2) DEFAULT 0.00,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_daily_stat (stat_date, license_key, machine_id, location_id, user_id),
    INDEX idx_date (stat_date),
    INDEX idx_date_license (stat_date, license_key),
    INDEX idx_location (location_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Insert default pricing
echo "\nInserting default pricing... ";
$check = $db->query("SELECT COUNT(*) as cnt FROM print_pricing");
$row = $check->fetch_assoc();
if ($row['cnt'] == 0) {
    $db->query("INSERT INTO print_pricing (license_id, paper_size, color_mode, cost_per_page, currency, effective_from, description) VALUES
        (NULL, 'A4', 'grayscale', 5.00, 'INR', CURDATE(), 'Default A4 grayscale'),
        (NULL, 'A4', 'color', 10.00, 'INR', CURDATE(), 'Default A4 color'),
        (NULL, 'A3', 'grayscale', 10.00, 'INR', CURDATE(), 'Default A3 grayscale'),
        (NULL, 'A3', 'color', 20.00, 'INR', CURDATE(), 'Default A3 color'),
        (NULL, 'Letter', 'grayscale', 5.00, 'INR', CURDATE(), 'Default Letter grayscale'),
        (NULL, 'Letter', 'color', 10.00, 'INR', CURDATE(), 'Default Letter color'),
        (NULL, 'default', 'any', 5.00, 'INR', CURDATE(), 'Fallback default')");
    echo "SUCCESS\n";
    $success++;
} else {
    echo "SKIPPED (already has data)\n";
    $skipped++;
}

// Insert system settings
echo "Inserting system settings... ";
$settings = [
    ['print_tracking_enabled', 'true', 'printing'],
    ['print_tracking_offline_enabled', 'true', 'printing'],
    ['print_billing_enabled', 'true', 'billing'],
    ['print_auto_invoice_day', '1', 'billing'],
    ['print_invoice_prefix', 'INV', 'billing'],
    ['print_default_currency', 'INR', 'billing'],
    ['print_sync_interval_minutes', '5', 'printing']
];

$settingsAdded = 0;
foreach ($settings as $s) {
    $check = $db->query("SELECT id FROM system_settings WHERE setting_key = '{$s[0]}'");
    if ($check->num_rows == 0) {
        $db->query("INSERT INTO system_settings (setting_key, setting_value, category) VALUES ('{$s[0]}', '{$s[1]}', '{$s[2]}')");
        $settingsAdded++;
    }
}
echo ($settingsAdded > 0 ? "SUCCESS ($settingsAdded added)\n" : "SKIPPED (already exist)\n");
if ($settingsAdded > 0) $success++; else $skipped++;

echo "\n";
echo "=================================\n";
echo "Migration Complete!\n";
echo "=================================\n";
echo "Success: $success\n";
echo "Skipped: $skipped\n";
echo "Failed:  $failed\n";
echo "\n";

if ($failed === 0) {
    echo "<strong style='color: green;'>Migration completed successfully!</strong>\n";
} else {
    echo "<strong style='color: orange;'>Migration completed with some failures.</strong>\n";
}

echo "</pre>";
echo "<p><a href='admin/location-management.php'>Go to Location Management</a></p>";
?>
