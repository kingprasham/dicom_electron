<?php
/**
 * Patient List Page - Enhanced UI
 * Shows all patients with advanced filtering and responsive design
 */
define('DICOM_VIEWER', true);
require_once __DIR__ . '/auth/session.php';
requireLogin();

// Get user info
$userName = $_SESSION['username'] ?? 'User';
$userRole = $_SESSION['role'] ?? 'viewer';

// Get database connection
$mysqli = getDbConnection();

// Sync from Orthanc first to get latest data
try {
    $ch = curl_init(ORTHANC_URL . '/patients');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, ORTHANC_USER . ':' . ORTHANC_PASS);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $patientIds = json_decode($response, true) ?? [];

        foreach ($patientIds as $patientId) {
            // Get patient details
            $ch = curl_init(ORTHANC_URL . '/patients/' . $patientId);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERPWD, ORTHANC_USER . ':' . ORTHANC_PASS);
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            $patientData = json_decode(curl_exec($ch), true);
            curl_close($ch);

            if ($patientData) {
                $patientName = $patientData['MainDicomTags']['PatientName'] ?? 'Unknown';
                $patientID = $patientData['MainDicomTags']['PatientID'] ?? $patientId;
                $patientBirthDate = $patientData['MainDicomTags']['PatientBirthDate'] ?? null;
                $patientSex = $patientData['MainDicomTags']['PatientSex'] ?? null;

                // Insert or update patient
                $stmt = $mysqli->prepare("
                    INSERT INTO cached_patients (orthanc_id, patient_id, patient_name, birth_date, sex, last_sync)
                    VALUES (?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        patient_name = VALUES(patient_name),
                        birth_date = VALUES(birth_date),
                        sex = VALUES(sex),
                        last_sync = NOW()
                ");
                $stmt->bind_param("sssss", $patientId, $patientID, $patientName, $patientBirthDate, $patientSex);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
} catch (Exception $e) {
    // Continue even if sync fails
}

// Get filter parameters
$searchQuery = $_GET['search'] ?? '';
$sortBy = $_GET['sort'] ?? 'patient_name';
$sortOrder = $_GET['order'] ?? 'ASC';

// Build query
// Build query with aggregation for latest study date and new studies count
$query = "
    SELECT p.*, 
           COUNT(CASE WHEN s.is_new = 1 THEN 1 END) as new_studies_count,
           MAX(CONCAT(s.study_date, ' ', s.study_time)) as latest_study_datetime,
           MAX(s.study_date) as latest_study_date
    FROM cached_patients p
    LEFT JOIN cached_studies s ON p.patient_id = s.patient_id
    WHERE 1=1
";
$params = [];
$types = '';

// Date filter parameters
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';

if ($searchQuery) {
    $query .= " AND (p.patient_name LIKE ? OR p.patient_id LIKE ?)";
    $searchParam = '%' . $searchQuery . '%';
    $params[] = $searchParam;
    $params[] = $searchParam;
    $types .= 'ss';
}

// Group by patient to make aggregates work
$query .= " GROUP BY p.id";

// Apply date filter logic using HAVING clause for aggregate column
if ($startDate && $endDate) {
    $query .= " HAVING latest_study_date BETWEEN ? AND ?";
    $params[] = $startDate . ' 00:00:00';
    $params[] = $endDate . ' 23:59:59';
    $types .= 'ss';
} elseif ($startDate) {
    $query .= " HAVING latest_study_date >= ?";
    $params[] = $startDate . ' 00:00:00';
    $types .= 's';
} elseif ($endDate) {
    $query .= " HAVING latest_study_date <= ?";
    $params[] = $endDate . ' 23:59:59';
    $types .= 's';
}

// Add sorting
$allowedSorts = ['patient_name', 'patient_id', 'birth_date', 'last_sync', 'latest_study_date'];
$sortBy = in_array($sortBy, $allowedSorts) ? $sortBy : 'latest_study_date'; // Default to latest studies
$sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC'; // Default to DESC (newest first) usually better, but keeping logic
if ($sortBy === 'latest_study_date') {
    // Force DESC for dates usually
    $sortOrder = $_GET['order'] ?? 'DESC';
}
$query .= " ORDER BY $sortBy $sortOrder";

// Execute query
$stmt = $mysqli->prepare($query);
if ($types) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$patients = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="base-path" content="<?= BASE_PATH ?>">
    <title>Patients - DICOM Viewer</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root {
            --primary-gradient-start: #0a0e27;
            --primary-gradient-end: #1a1f3a;
            --card-bg: rgba(255, 255, 255, 0.05);
            --card-border: rgba(255, 255, 255, 0.1);
            --hover-bg: rgba(255, 255, 255, 0.08);
            --primary-color: #0d6efd;
        }
        
        body {
            background: linear-gradient(135deg, var(--primary-gradient-start) 0%, var(--primary-gradient-end) 100%);
            min-height: 100vh;
            font-family: 'Inter', -apple-system, system-ui, sans-serif;
        }
        
        .navbar-custom {
            background: var(--card-bg);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--card-border);
            padding: 1rem 0;
        }
        
        .search-section {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            backdrop-filter: blur(10px);
            transition: all 0.3s;
        }
        
        .search-section:hover {
            border-color: var(--primary-color);
            box-shadow: 0 0 20px rgba(13, 110, 253, 0.15);
        }
        
        .search-input {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 25px;
            padding: 12px 20px 12px 45px;
            color: #fff;
            transition: all 0.3s;
        }
        
        .search-input:focus {
            background: rgba(255, 255, 255, 0.08);
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.15);
            color: #fff;
        }
        
        .search-wrapper {
            position: relative;
        }
        
        .search-icon {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: #adb5bd;
            z-index: 10;
        }
        
        .patient-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .patient-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 15px;
            padding: 20px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }
        
        .patient-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--primary-color), #6610f2);
            transform: scaleX(0);
            transition: transform 0.3s;
        }
        
        .patient-card:hover {
            border-color: var(--primary-color);
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(13, 110, 253, 0.2);
        }
        
        .patient-card:hover::before {
            transform: scaleX(1);
        }
        
        .patient-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            font-weight: 600;
            color: #fff;
            margin-bottom: 15px;
        }
        
        .patient-name {
            font-size: 1.1rem;
            font-weight: 600;
            color: #fff;
            margin-bottom: 10px;
        }
        
        .patient-detail {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #adb5bd;
            font-size: 0.9rem;
            margin-bottom: 6px;
        }
        
        .patient-detail i {
            color: var(--primary-color);
        }
        
        .badge-custom {
            background: rgba(13, 110, 253, 0.15);
            border: 1px solid rgba(13, 110, 253, 0.3);
            color: #6ea8fe;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .filter-toggle {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 10px;
            padding: 10px 20px;
            color: #fff;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .filter-toggle:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: var(--primary-color);
        }
        
        .filters-content {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease-out;
        }
        
        .filters-content.show {
            max-height: 500px;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #adb5bd;
        }
        
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            color: rgba(255, 255, 255, 0.1);
        }
        
        .stats-bar {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 10px;
            padding: 15px 20px;
            margin-top: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        @media (max-width: 768px) {
            .patient-grid {
                grid-template-columns: 1fr;
            }
            
            .navbar-custom .d-flex {
                flex-direction: column;
                gap: 10px;
            }
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .patient-card {
            animation: slideIn 0.3s ease-out backwards;
        }
        
        .patient-card:nth-child(1) { animation-delay: 0.05s; }
        .patient-card:nth-child(2) { animation-delay: 0.1s; }
        .patient-card:nth-child(3) { animation-delay: 0.15s; }
        .patient-card:nth-child(4) { animation-delay: 0.2s; }
        .patient-card:nth-child(5) { animation-delay: 0.25s; }
        .patient-card:nth-child(6) { animation-delay: 0.3s; }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-custom mb-4">
        <div class="container-fluid">
            <a class="navbar-brand text-white d-flex align-items-center gap-2" href="<?= BASE_PATH ?>/patients.php">
                <i class="bi bi-heart-pulse-fill text-primary fs-4"></i>
                <span class="fw-bold">DICOM Viewer Pro</span>
            </a>
            <div class="d-flex align-items-center gap-3">
                <?php if ($userRole === 'admin'): ?>
                <a href="<?= BASE_PATH ?>/admin/settings.php" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-gear"></i> Settings
                </a>
                <?php endif; ?>
                <span class="text-light">
                    <i class="bi bi-person-circle"></i>
                    <?= htmlspecialchars($userName) ?>
                </span>
                <a href="<?= BASE_PATH ?>/logout.php" class="btn btn-outline-danger btn-sm">
                    <i class="bi bi-box-arrow-right"></i> Logout
                </a>
            </div>
        </div>
    </nav>

    <div class="container">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="text-white mb-0">
                <i class="bi bi-people-fill text-primary"></i>
                Patient List
            </h2>
            <button class="btn btn-primary" onclick="window.location.reload()">
                <i class="bi bi-arrow-clockwise"></i> Refresh
            </button>
        </div>

        <!-- Search Section -->
        <div class="search-section">
            <form method="GET" action="">
                <div class="row align-items-end g-3">
                    <div class="col-md-4">
                        <label class="form-label text-light mb-2">
                            <i class="bi bi-search"></i> Quick Search
                        </label>
                        <div class="search-wrapper">
                            <i class="bi bi-search search-icon"></i>
                            <input type="text" name="search" class="form-control search-input"
                                   placeholder="Search by patient name or ID..."
                                   value="<?= htmlspecialchars($searchQuery) ?>" autofocus>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label text-light mb-2">From Date</label>
                        <input type="date" name="start_date" class="form-control bg-dark text-white border-secondary" 
                               value="<?= htmlspecialchars($startDate) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label text-light mb-2">To Date</label>
                        <input type="date" name="end_date" class="form-control bg-dark text-white border-secondary" 
                               value="<?= htmlspecialchars($endDate) ?>">
                    </div>
                    
                    <div class="col-md-2 d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-fill">
                            <i class="bi bi-filter"></i> Filter
                        </button>
                        <?php if ($searchQuery || $startDate || $endDate): ?>
                        <a href="<?= BASE_PATH ?>/patients.php" class="btn btn-secondary" title="Clear Filters">
                            <i class="bi bi-x-lg"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>

        <!-- Patient Grid -->
        <?php if (empty($patients)): ?>
            <div class="empty-state">
                <i class="bi bi-inbox"></i>
                <h4 class="text-white mb-3">No Patients Found</h4>
                <p class="text-muted">
                    <?php if ($searchQuery): ?>
                        Try adjusting your search query
                    <?php else: ?>
                        Send DICOM data from your MRI/CT machine to start viewing patients
                    <?php endif; ?>
                </p>
            </div>
        <?php else: ?>
            <div class="patient-grid">
                <?php foreach ($patients as $patient): 
                    $initials = '';
                    $nameParts = explode('^', str_replace('_', ' ', $patient['patient_name']));
                    foreach ($nameParts as $part) {
                        if (!empty($part)) {
                            $initials .= strtoupper(substr($part, 0, 1));
                        }
                    }
                    $initials = substr($initials, 0, 2);
                ?>
                    <div class="patient-card" onclick="window.location.href='<?= BASE_PATH ?>/patient-studies.php?patient_id=<?= urlencode($patient['orthanc_id']) ?>'">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="patient-avatar">
                                <?= $initials ?>
                            </div>
                            <?php if ($patient['new_studies_count'] > 0): ?>
                                <span class="badge bg-danger rounded-pill shadow-sm">
                                    <?= $patient['new_studies_count'] ?> NEW
                                </span>
                            <?php endif; ?>
                        </div>
                        
                        <div class="patient-name text-truncate">
                            <?= htmlspecialchars($patient['patient_name']) ?>
                        </div>
                        
                        <div class="patient-detail">
                            <i class="bi bi-person-badge"></i>
                            <span>ID: <?= htmlspecialchars($patient['patient_id']) ?></span>
                        </div>
                        
                        <?php if ($patient['birth_date']): ?>
                        <div class="patient-detail">
                            <i class="bi bi-calendar"></i>
                            <span>DOB: <?= htmlspecialchars($patient['birth_date']) ?></span>
                        </div>
                        <?php endif; ?>
                        
                        <div class="patient-detail mt-2 pt-2 border-top border-secondary">
                            <i class="bi bi-clock-history text-info"></i>
                            <span class="text-info small">
                                Latest: <?= $patient['latest_study_datetime'] ? date('M d, Y', strtotime($patient['latest_study_datetime'])) : 'No studies' ?>
                            </span>
                        </div>
                        
                        <div class="mt-3">
                            <span class="badge-custom w-100 text-center d-block">
                                <i class="bi bi-arrow-right-circle"></i> View Studies
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Stats Bar -->
            <div class="stats-bar">
                <div class="text-light">
                    <i class="bi bi-people"></i>
                    <strong><?= count($patients) ?></strong> Patient<?= count($patients) !== 1 ? 's' : '' ?> Found
                </div>
                <div class="text-muted small">
                    Last updated: <?= date('g:i A') ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-refresh logic
        let lastStudyCount = -1;
        let lastImportId = -1;
        
        async function checkNewStudies() {
            try {
                // Use the base path from meta tag if available, or infer
                const basePath = document.querySelector('meta[name="base-path"]')?.content || '<?= BASE_PATH ?>';
                const response = await fetch(`${basePath}/api/stats/study-count.php`);
                const data = await response.json();
                
                if (data.success) {
                    // Initialize on first run
                    if (lastStudyCount === -1) {
                        lastStudyCount = data.total;
                        lastImportId = data.last_id;
                        return;
                    }
                    
                    // Check for changes
                    if (data.total > lastStudyCount || data.last_id > lastImportId) {
                        console.log('New studies detected, refreshing...');
                        
                        // Show toast/notification
                        const toast = document.createElement('div');
                        toast.className = 'position-fixed bottom-0 end-0 p-3';
                        toast.style.zIndex = '11';
                        toast.innerHTML = `
                            <div class="toast show align-items-center text-white bg-primary border-0" role="alert">
                                <div class="d-flex">
                                    <div class="toast-body">
                                        <i class="bi bi-arrow-repeat spin me-2"></i> New data detected! Refreshing...
                                    </div>
                                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                                </div>
                            </div>
                        `;
                        document.body.appendChild(toast);
                        
                        // Reload after short delay
                        setTimeout(() => window.location.reload(), 2000);
                        
                        // Update trackers to prevent double-trigger
                        lastStudyCount = data.total;
                        lastImportId = data.last_id;
                    }
                }
            } catch (error) {
                console.error('Auto-refresh check failed:', error);
            }
        }
        
        // Check every 15 seconds
        setInterval(checkNewStudies, 15000);
        
        // Initial check
        checkNewStudies();
        
        // Auto-Backup Scheduler Trigger
        (function() {
            // Check for backup every 60 seconds
            const BACKUP_CHECK_INTERVAL = 60000;
            
            async function triggerBackupCheck() {
                try {
                    const basePath = document.querySelector('meta[name="base-path"]')?.content || '<?= BASE_PATH ?>';
                    const response = await fetch(`${basePath}/api/backup/auto-trigger.php`);
                    const data = await response.json();
                    
                    if (data.triggered) {
                        console.log('Scheduled backup triggered:', data.message);
                    }
                } catch (error) {
                    console.warn('Backup scheduler check failed:', error);
                }
            }
            
            // Initial check after 10 seconds
            setTimeout(triggerBackupCheck, 10000);
            
            // Interval check
            setInterval(triggerBackupCheck, BACKUP_CHECK_INTERVAL);
        })();
    </script>
</body>
</html>