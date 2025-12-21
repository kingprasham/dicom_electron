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
            overflow: visible; /* Allow dropdowns to overflow */
            position: relative;
            z-index: 1100; /* Higher than search section to allow dropdowns to overlap */
        }
        
        /* Ensure navbar container doesn't clip dropdowns */
        .navbar-custom .container-fluid {
            overflow: visible;
        }
        
        .navbar-custom .d-flex {
            overflow: visible;
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
        
        /* Grid View Styles */
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

        /* List View (Table) Styles */
        .patient-table-container {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: 15px;
            overflow: hidden;
            margin-top: 20px;
            backdrop-filter: blur(10px);
        }

        .table-custom {
            width: 100%;
            margin-bottom: 0;
            color: #fff;
        }

        .table-custom th {
            background: rgba(0, 0, 0, 0.2);
            border-bottom: 1px solid var(--card-border);
            padding: 15px 20px;
            font-weight: 600;
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #adb5bd;
        }

        .table-custom td {
            padding: 15px 20px;
            border-bottom: 1px solid var(--card-border);
            vertical-align: middle;
        }

        .table-custom tr:last-child td {
            border-bottom: none;
        }

        .table-custom tr:hover td {
            background: var(--hover-bg);
            cursor: pointer;
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
        
        /* View Switcher */
        .view-switcher .btn {
            background: rgba(255, 255, 255, 0.05);
            border-color: var(--card-border);
            color: #adb5bd;
        }
        
        .view-switcher .btn.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
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

        /* Edit button styling */
        .patient-edit-btn {
            opacity: 0.6;
            transition: opacity 0.2s;
            padding: 2px 6px;
        }
        .patient-card:hover .patient-edit-btn {
            opacity: 1;
        }

        /* Dropdown menu styling */
        .dropdown-menu-dark {
            background: rgba(30, 35, 60, 0.98);
            border: 1px solid rgba(255, 255, 255, 0.15);
        }
        .dropdown-menu-dark .dropdown-item {
            color: #adb5bd;
        }
        .dropdown-menu-dark .dropdown-item:hover {
            background: rgba(13, 110, 253, 0.2);
            color: #fff;
        }
        .dropdown-header {
            color: #6ea8fe !important;
            font-weight: 600;
        }
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
            <div class="d-flex gap-2 flex-wrap">
                <!-- Upload Study Button -->
                <button class="btn btn-success" onclick="showUploadModal()" title="Upload DICOM/JPG files">
                    <i class="bi bi-cloud-upload"></i> Upload
                </button>

                <!-- Storage Management Dropdown -->
                <div class="dropdown">
                    <button class="btn btn-warning dropdown-toggle" type="button" data-bs-toggle="dropdown" title="Storage Management">
                        <i class="bi bi-hdd"></i> Storage
                    </button>
                    <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end">
                        <li><h6 class="dropdown-header"><i class="bi bi-trash3 me-2"></i>Delete Old Studies</h6></li>
                        <li><a class="dropdown-item" href="#" onclick="showDeleteModal(3)"><i class="bi bi-calendar-x me-2"></i>Older than 3 months</a></li>
                        <li><a class="dropdown-item" href="#" onclick="showDeleteModal(6)"><i class="bi bi-calendar-x me-2"></i>Older than 6 months</a></li>
                        <li><a class="dropdown-item" href="#" onclick="showDeleteModal(12)"><i class="bi bi-calendar-x me-2"></i>Older than 1 year</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><h6 class="dropdown-header"><i class="bi bi-download me-2"></i>Backup Studies</h6></li>
                        <li><a class="dropdown-item" href="#" onclick="showBackupModal(3)"><i class="bi bi-archive me-2"></i>Backup older than 3 months</a></li>
                        <li><a class="dropdown-item" href="#" onclick="showBackupModal(6)"><i class="bi bi-archive me-2"></i>Backup older than 6 months</a></li>
                        <li><a class="dropdown-item" href="#" onclick="showBackupModal(12)"><i class="bi bi-archive me-2"></i>Backup older than 1 year</a></li>
                    </ul>
                </div>

                <div class="btn-group view-switcher" role="group">
                    <button type="button" class="btn btn-outline-secondary" id="gridViewBtn" title="Grid View">
                        <i class="bi bi-grid-fill"></i>
                    </button>
                    <button type="button" class="btn btn-outline-secondary" id="listViewBtn" title="List View">
                        <i class="bi bi-list-ul"></i>
                    </button>
                </div>
                <button class="btn btn-primary" onclick="window.location.reload()">
                    <i class="bi bi-arrow-clockwise"></i> Refresh
                </button>
            </div>
        </div>

        <!-- Search Section -->
        <div class="search-section">
            <form method="GET" action="">
                <div class="row g-3 align-items-end">
                    <div class="col-md-5">
                        <label class="form-label text-light mb-2">Search</label>
                        <div class="search-wrapper">
                            <i class="bi bi-search search-icon"></i>
                            <input type="text" name="search" class="form-control search-input"
                                   placeholder="Search by patient name or ID..."
                                   value="<?= htmlspecialchars($searchQuery) ?>">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label text-light mb-2">Sort By</label>
                        <select name="sort" class="form-select bg-dark text-white border-secondary">
                            <option value="latest_study_date" <?= $sortBy === 'latest_study_date' ? 'selected' : '' ?>>Recent Study</option>
                            <option value="patient_name" <?= $sortBy === 'patient_name' ? 'selected' : '' ?>>Name</option>
                            <option value="patient_id" <?= $sortBy === 'patient_id' ? 'selected' : '' ?>>ID</option>
                            <option value="birth_date" <?= $sortBy === 'birth_date' ? 'selected' : '' ?>>Date of Birth</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label text-light mb-2">From</label>
                        <input type="date" name="start_date" class="form-control bg-dark text-white border-secondary" 
                               value="<?= htmlspecialchars($startDate) ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label text-light mb-2">To</label>
                        <input type="date" name="end_date" class="form-control bg-dark text-white border-secondary" 
                               value="<?= htmlspecialchars($endDate) ?>">
                    </div>
                    <div class="col-md-1">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-filter"></i>
                        </button>
                    </div>
                </div>
            </form>
            <?php if ($searchQuery || $startDate || $endDate): ?>
            <div class="mt-2 text-end">
                <a href="<?= BASE_PATH ?>/patients.php" class="text-secondary small text-decoration-none">
                    <i class="bi bi-x-circle"></i> Clear filters
                </a>
            </div>
            <?php endif; ?>
        </div>

        <!-- Patient Content -->
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
            <!-- Grid View -->
            <div id="patientGridView" class="patient-grid">
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
                    <div class="patient-card">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="patient-avatar" onclick="window.location.href='<?= BASE_PATH ?>/patient-studies.php?patient_id=<?= urlencode($patient['orthanc_id']) ?>'">
                                <?= $initials ?>
                            </div>
                            <div class="d-flex gap-1 align-items-center">
                                <?php if ($patient['new_studies_count'] > 0): ?>
                                    <span class="badge bg-danger rounded-pill shadow-sm">
                                        <?= $patient['new_studies_count'] ?> NEW
                                    </span>
                                <?php endif; ?>
                                <button class="btn btn-sm btn-outline-secondary patient-edit-btn"
                                        onclick="event.stopPropagation(); showEditPatientModal('<?= htmlspecialchars($patient['orthanc_id']) ?>')"
                                        title="Edit Patient">
                                    <i class="bi bi-pencil"></i>
                                </button>
                            </div>
                        </div>

                        <div class="patient-name text-truncate" onclick="window.location.href='<?= BASE_PATH ?>/patient-studies.php?patient_id=<?= urlencode($patient['orthanc_id']) ?>'">
                            <?= htmlspecialchars($patient['patient_name']) ?>
                        </div>

                        <div class="patient-detail" onclick="window.location.href='<?= BASE_PATH ?>/patient-studies.php?patient_id=<?= urlencode($patient['orthanc_id']) ?>'">
                            <i class="bi bi-person-badge"></i>
                            <span>ID: <?= htmlspecialchars($patient['patient_id']) ?></span>
                        </div>

                        <?php if ($patient['birth_date']): ?>
                        <div class="patient-detail" onclick="window.location.href='<?= BASE_PATH ?>/patient-studies.php?patient_id=<?= urlencode($patient['orthanc_id']) ?>'">
                            <i class="bi bi-calendar"></i>
                            <span>DOB: <?= htmlspecialchars($patient['birth_date']) ?></span>
                        </div>
                        <?php endif; ?>

                        <div class="patient-detail mt-2 pt-2 border-top border-secondary" onclick="window.location.href='<?= BASE_PATH ?>/patient-studies.php?patient_id=<?= urlencode($patient['orthanc_id']) ?>'">
                            <i class="bi bi-clock-history text-info"></i>
                            <span class="text-info small">
                                Latest: <?= $patient['latest_study_datetime'] ? date('M d, Y', strtotime($patient['latest_study_datetime'])) : 'No studies' ?>
                            </span>
                        </div>

                        <div class="mt-3 d-flex gap-2">
                            <span class="badge-custom flex-grow-1 text-center" onclick="window.location.href='<?= BASE_PATH ?>/patient-studies.php?patient_id=<?= urlencode($patient['orthanc_id']) ?>'">
                                <i class="bi bi-arrow-right-circle"></i> View Studies
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- List View (Hidden by default) -->
            <div id="patientListView" class="patient-table-container" style="display: none;">
                <table class="table table-custom table-hover">
                    <thead>
                        <tr>
                            <th>Patient Name</th>
                            <th>Patient ID</th>
                            <th>Date of Birth</th>
                            <th>Sex</th>
                            <th>Last Study Date</th>
                            <th>Status</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($patients as $patient): ?>
                        <tr>
                            <td onclick="window.location.href='<?= BASE_PATH ?>/patient-studies.php?patient_id=<?= urlencode($patient['orthanc_id']) ?>'" style="cursor:pointer">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="rounded-circle bg-primary d-flex align-items-center justify-content-center text-white" style="width: 30px; height: 30px; font-size: 0.8rem;">
                                        <?= substr($patient['patient_name'], 0, 1) ?>
                                    </div>
                                    <span class="fw-semibold"><?= htmlspecialchars($patient['patient_name']) ?></span>
                                </div>
                            </td>
                            <td onclick="window.location.href='<?= BASE_PATH ?>/patient-studies.php?patient_id=<?= urlencode($patient['orthanc_id']) ?>'" style="cursor:pointer"><?= htmlspecialchars($patient['patient_id']) ?></td>
                            <td onclick="window.location.href='<?= BASE_PATH ?>/patient-studies.php?patient_id=<?= urlencode($patient['orthanc_id']) ?>'" style="cursor:pointer"><?= $patient['birth_date'] ? htmlspecialchars($patient['birth_date']) : '-' ?></td>
                            <td onclick="window.location.href='<?= BASE_PATH ?>/patient-studies.php?patient_id=<?= urlencode($patient['orthanc_id']) ?>'" style="cursor:pointer"><?= htmlspecialchars($patient['sex'] ?? '-') ?></td>
                            <td onclick="window.location.href='<?= BASE_PATH ?>/patient-studies.php?patient_id=<?= urlencode($patient['orthanc_id']) ?>'" style="cursor:pointer">
                                <?php if ($patient['latest_study_datetime']): ?>
                                    <div class="d-flex align-items-center gap-1 text-info">
                                        <i class="bi bi-calendar-check"></i>
                                        <?= date('M d, Y', strtotime($patient['latest_study_datetime'])) ?>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td onclick="window.location.href='<?= BASE_PATH ?>/patient-studies.php?patient_id=<?= urlencode($patient['orthanc_id']) ?>'" style="cursor:pointer">
                                <?php if ($patient['new_studies_count'] > 0): ?>
                                    <span class="badge bg-danger rounded-pill"><?= $patient['new_studies_count'] ?> New Studies</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary rounded-pill">Viewed</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-outline-secondary" onclick="showEditPatientModal('<?= htmlspecialchars($patient['orthanc_id']) ?>')" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn btn-outline-primary" onclick="window.location.href='<?= BASE_PATH ?>/patient-studies.php?patient_id=<?= urlencode($patient['orthanc_id']) ?>'">
                                        View
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
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
    <!-- Print Counter Badge Component -->
    <script src="<?= BASE_PATH ?>/js/components/print-counter-badge.js"></script>
    <script>
        // View Toggle Logic
        document.addEventListener('DOMContentLoaded', () => {
            const gridViewBtn = document.getElementById('gridViewBtn');
            const listViewBtn = document.getElementById('listViewBtn');
            const gridView = document.getElementById('patientGridView');
            const listView = document.getElementById('patientListView');
            
            // Check localStorage
            const currentView = localStorage.getItem('patientsViewMode') || 'grid';
            
            function setView(mode) {
                if (mode === 'grid') {
                    gridView.style.display = 'grid';
                    listView.style.display = 'none';
                    gridViewBtn.classList.add('active');
                    listViewBtn.classList.remove('active');
                } else {
                    gridView.style.display = 'none';
                    listView.style.display = 'block';
                    gridViewBtn.classList.remove('active');
                    listViewBtn.classList.add('active');
                }
                localStorage.setItem('patientsViewMode', mode);
            }
            
            // Initialize
            setView(currentView);
            
            // Event listeners
            gridViewBtn.addEventListener('click', () => setView('grid'));
            listViewBtn.addEventListener('click', () => setView('list'));
        });

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

        // ===== UPLOAD MODAL FUNCTIONALITY =====
        const uploadModal = new bootstrap.Modal(document.getElementById('uploadModal'));
        let uploadFiles = [];

        function showUploadModal() {
            document.getElementById('uploadFiles').value = '';
            document.getElementById('uploadPatientId').value = '';
            document.getElementById('uploadPatientName').value = '';
            document.getElementById('uploadStudyDescription').value = '';
            document.getElementById('uploadFileList').innerHTML = '';
            document.getElementById('uploadProgress').style.display = 'none';
            uploadFiles = [];
            uploadModal.show();
        }

        document.getElementById('uploadFiles')?.addEventListener('change', function(e) {
            uploadFiles = Array.from(e.target.files);
            const listHtml = uploadFiles.map(f => `
                <div class="d-flex justify-content-between align-items-center mb-1 p-2 bg-dark rounded">
                    <span><i class="bi bi-file-earmark me-2"></i>${f.name}</span>
                    <span class="text-muted small">${formatBytes(f.size)}</span>
                </div>
            `).join('');
            document.getElementById('uploadFileList').innerHTML = listHtml || '<p class="text-muted">No files selected</p>';
        });

        async function uploadStudyFiles() {
            if (uploadFiles.length === 0) {
                alert('Please select files to upload');
                return;
            }

            const patientId = document.getElementById('uploadPatientId').value.trim();
            const patientName = document.getElementById('uploadPatientName').value.trim();
            const studyDescription = document.getElementById('uploadStudyDescription').value.trim() || 'Uploaded Study';

            const progressDiv = document.getElementById('uploadProgress');
            const progressBar = progressDiv.querySelector('.progress-bar');
            const progressText = document.getElementById('uploadProgressText');
            progressDiv.style.display = 'block';

            let uploaded = 0;
            let errors = [];

            for (const file of uploadFiles) {
                progressText.textContent = `Uploading ${file.name}...`;

                const formData = new FormData();
                formData.append('file', file);
                formData.append('patient_id', patientId);
                formData.append('patient_name', patientName);
                formData.append('study_description', studyDescription);

                try {
                    const response = await fetch('<?= BASE_PATH ?>/api/patient/upload-study.php', {
                        method: 'POST',
                        body: formData
                    });
                    const result = await response.json();

                    if (result.success) {
                        uploaded++;
                    } else {
                        errors.push(`${file.name}: ${result.error}`);
                    }
                } catch (err) {
                    errors.push(`${file.name}: ${err.message}`);
                }

                const percent = Math.round((uploaded / uploadFiles.length) * 100);
                progressBar.style.width = percent + '%';
                progressBar.textContent = percent + '%';
            }

            progressText.textContent = `Completed: ${uploaded}/${uploadFiles.length} files`;

            if (errors.length > 0) {
                alert('Some files failed to upload:\n' + errors.join('\n'));
            }

            if (uploaded > 0) {
                setTimeout(() => {
                    uploadModal.hide();
                    window.location.reload();
                }, 1500);
            }
        }

        // ===== EDIT PATIENT MODAL FUNCTIONALITY =====
        const editPatientModal = new bootstrap.Modal(document.getElementById('editPatientModal'));
        let currentEditPatientId = null;

        async function showEditPatientModal(orthancId) {
            currentEditPatientId = orthancId;
            document.getElementById('editPatientForm').reset();

            try {
                const response = await fetch(`<?= BASE_PATH ?>/api/patient/edit-patient.php?orthanc_id=${encodeURIComponent(orthancId)}`);
                const data = await response.json();

                if (data.success && data.patient) {
                    document.getElementById('editOrthancId').value = data.patient.orthanc_id;
                    document.getElementById('editPatientId').value = data.patient.patient_id || '';
                    document.getElementById('editPatientName').value = data.patient.patient_name || '';
                    document.getElementById('editBirthDate').value = data.patient.birth_date || '';
                    document.getElementById('editSex').value = data.patient.sex || '';
                    document.getElementById('editStudyCount').textContent = data.patient.study_count || 0;
                    editPatientModal.show();
                } else {
                    alert('Failed to load patient data');
                }
            } catch (err) {
                console.error('Error loading patient:', err);
                alert('Failed to load patient data');
            }
        }

        async function savePatientEdit() {
            const data = {
                orthanc_id: document.getElementById('editOrthancId').value,
                patient_id: document.getElementById('editPatientId').value,
                patient_name: document.getElementById('editPatientName').value,
                birth_date: document.getElementById('editBirthDate').value,
                sex: document.getElementById('editSex').value
            };

            try {
                const response = await fetch('<?= BASE_PATH ?>/api/patient/edit-patient.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                const result = await response.json();

                if (result.success) {
                    editPatientModal.hide();
                    window.location.reload();
                } else {
                    alert('Failed to save: ' + result.error);
                }
            } catch (err) {
                alert('Error saving patient: ' + err.message);
            }
        }

        // ===== DELETE STUDIES MODAL FUNCTIONALITY =====
        const deleteModal = new bootstrap.Modal(document.getElementById('deleteStudiesModal'));
        let deleteMonths = 0;

        async function showDeleteModal(months) {
            deleteMonths = months;
            document.getElementById('deleteMonthsText').textContent = months;
            document.getElementById('deletePreviewContent').innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary"></div><p class="mt-2">Loading preview...</p></div>';
            document.getElementById('confirmDeleteBtn').disabled = true;
            deleteModal.show();

            try {
                const response = await fetch(`<?= BASE_PATH ?>/api/patient/delete-studies.php?months=${months}&direction=older`);
                const data = await response.json();

                if (data.success) {
                    if (data.study_count === 0) {
                        document.getElementById('deletePreviewContent').innerHTML = '<div class="alert alert-info"><i class="bi bi-info-circle me-2"></i>No studies found older than ' + months + ' months</div>';
                    } else {
                        document.getElementById('deletePreviewContent').innerHTML = `
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                <strong>${data.study_count} studies</strong> will be permanently deleted (${data.total_size_formatted})
                            </div>
                            <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                                <table class="table table-sm table-dark table-striped">
                                    <thead><tr><th>Patient</th><th>Study</th><th>Date</th><th>Size</th></tr></thead>
                                    <tbody>
                                        ${data.studies.map(s => `
                                            <tr>
                                                <td>${s.patient_name || 'Unknown'}</td>
                                                <td>${s.study_description || '-'}</td>
                                                <td>${s.study_date || '-'}</td>
                                                <td>${s.size_formatted}</td>
                                            </tr>
                                        `).join('')}
                                    </tbody>
                                </table>
                            </div>
                        `;
                        document.getElementById('confirmDeleteBtn').disabled = false;
                    }
                } else {
                    document.getElementById('deletePreviewContent').innerHTML = '<div class="alert alert-danger">Error: ' + data.error + '</div>';
                }
            } catch (err) {
                document.getElementById('deletePreviewContent').innerHTML = '<div class="alert alert-danger">Error loading preview: ' + err.message + '</div>';
            }
        }

        async function confirmDeleteStudies() {
            if (!confirm('Are you ABSOLUTELY sure? This action cannot be undone!')) return;

            document.getElementById('confirmDeleteBtn').disabled = true;
            document.getElementById('confirmDeleteBtn').innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Deleting...';

            try {
                const response = await fetch('<?= BASE_PATH ?>/api/patient/delete-studies.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ months: deleteMonths, direction: 'older' })
                });
                const result = await response.json();

                if (result.success) {
                    alert(`Successfully deleted ${result.deleted_count} studies.\nFreed space: ${result.freed_space_formatted}`);
                    deleteModal.hide();
                    window.location.reload();
                } else {
                    alert('Delete failed: ' + result.error);
                }
            } catch (err) {
                alert('Error: ' + err.message);
            }

            document.getElementById('confirmDeleteBtn').disabled = false;
            document.getElementById('confirmDeleteBtn').innerHTML = '<i class="bi bi-trash3 me-2"></i>Delete Studies';
        }

        // ===== BACKUP STUDIES MODAL FUNCTIONALITY =====
        const backupModal = new bootstrap.Modal(document.getElementById('backupStudiesModal'));
        let backupMonths = 0;

        async function showBackupModal(months) {
            backupMonths = months;
            document.getElementById('backupMonthsText').textContent = months;
            document.getElementById('backupPreviewContent').innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary"></div><p class="mt-2">Loading preview...</p></div>';
            document.getElementById('confirmBackupBtn').disabled = true;
            backupModal.show();

            try {
                const response = await fetch(`<?= BASE_PATH ?>/api/patient/backup-studies.php?action=preview&months=${months}&direction=older`);
                const data = await response.json();

                if (data.success) {
                    if (data.study_count === 0) {
                        document.getElementById('backupPreviewContent').innerHTML = '<div class="alert alert-info"><i class="bi bi-info-circle me-2"></i>No studies found older than ' + months + ' months</div>';
                    } else {
                        document.getElementById('backupPreviewContent').innerHTML = `
                            <div class="alert alert-info">
                                <i class="bi bi-archive me-2"></i>
                                <strong>${data.study_count} studies</strong> will be included in backup (${data.total_size_formatted})
                            </div>
                            <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                                <table class="table table-sm table-dark table-striped">
                                    <thead><tr><th>Patient</th><th>Study</th><th>Date</th><th>Size</th></tr></thead>
                                    <tbody>
                                        ${data.studies.map(s => `
                                            <tr>
                                                <td>${s.patient_name || 'Unknown'}</td>
                                                <td>${s.study_description || '-'}</td>
                                                <td>${s.study_date || '-'}</td>
                                                <td>${s.size_formatted}</td>
                                            </tr>
                                        `).join('')}
                                    </tbody>
                                </table>
                            </div>
                        `;
                        document.getElementById('confirmBackupBtn').disabled = false;
                    }
                } else {
                    document.getElementById('backupPreviewContent').innerHTML = '<div class="alert alert-danger">Error: ' + data.error + '</div>';
                }
            } catch (err) {
                document.getElementById('backupPreviewContent').innerHTML = '<div class="alert alert-danger">Error loading preview: ' + err.message + '</div>';
            }
        }

        async function confirmBackupStudies() {
            document.getElementById('confirmBackupBtn').disabled = true;
            document.getElementById('confirmBackupBtn').innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Preparing...';

            try {
                const response = await fetch('<?= BASE_PATH ?>/api/patient/backup-studies.php?action=prepare', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ months: backupMonths, direction: 'older' })
                });
                const result = await response.json();

                if (result.success && result.download_url) {
                    // Trigger download
                    window.location.href = '<?= BASE_PATH ?>/api/patient/' + result.download_url;
                    setTimeout(() => {
                        backupModal.hide();
                        document.getElementById('confirmBackupBtn').disabled = false;
                        document.getElementById('confirmBackupBtn').innerHTML = '<i class="bi bi-download me-2"></i>Download Backup';
                    }, 2000);
                } else {
                    alert('Backup failed: ' + result.error);
                    document.getElementById('confirmBackupBtn').disabled = false;
                    document.getElementById('confirmBackupBtn').innerHTML = '<i class="bi bi-download me-2"></i>Download Backup';
                }
            } catch (err) {
                alert('Error: ' + err.message);
                document.getElementById('confirmBackupBtn').disabled = false;
                document.getElementById('confirmBackupBtn').innerHTML = '<i class="bi bi-download me-2"></i>Download Backup';
            }
        }

        // Utility function
        function formatBytes(bytes, precision = 2) {
            const units = ['B', 'KB', 'MB', 'GB', 'TB'];
            let pow = Math.floor(Math.log(bytes) / Math.log(1024));
            pow = Math.min(pow, units.length - 1);
            return (bytes / Math.pow(1024, pow)).toFixed(precision) + ' ' + units[pow];
        }
    </script>

    <!-- Upload Modal -->
    <div class="modal fade" id="uploadModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content bg-dark text-light">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="bi bi-cloud-upload me-2 text-success"></i>Upload Study Files</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Select Files (DICOM, JPG, PNG, or ZIP)</label>
                        <input type="file" id="uploadFiles" class="form-control bg-secondary text-light" multiple accept=".dcm,.jpg,.jpeg,.png,.zip">
                        <div class="form-text text-muted">You can select multiple files at once</div>
                    </div>
                    <div id="uploadFileList" class="mb-3"></div>

                    <hr class="border-secondary">
                    <p class="text-muted small mb-2">Optional: For JPG/PNG images only (DICOM files use embedded patient info)</p>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Patient ID</label>
                            <input type="text" id="uploadPatientId" class="form-control bg-secondary text-light" placeholder="e.g., PAT001">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Patient Name</label>
                            <input type="text" id="uploadPatientName" class="form-control bg-secondary text-light" placeholder="e.g., John Doe">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Study Description</label>
                            <input type="text" id="uploadStudyDescription" class="form-control bg-secondary text-light" placeholder="e.g., Chest X-Ray">
                        </div>
                    </div>

                    <div id="uploadProgress" class="mt-4" style="display: none;">
                        <div class="progress" style="height: 25px;">
                            <div class="progress-bar progress-bar-striped progress-bar-animated" style="width: 0%">0%</div>
                        </div>
                        <p id="uploadProgressText" class="text-center mt-2 text-muted">Uploading...</p>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" onclick="uploadStudyFiles()">
                        <i class="bi bi-cloud-upload me-2"></i>Upload Files
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Patient Modal -->
    <div class="modal fade" id="editPatientModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content bg-dark text-light">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2 text-primary"></i>Edit Patient Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="editPatientForm">
                        <input type="hidden" id="editOrthancId">
                        <div class="mb-3">
                            <label class="form-label">Patient ID</label>
                            <input type="text" id="editPatientId" class="form-control bg-secondary text-light">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Patient Name</label>
                            <input type="text" id="editPatientName" class="form-control bg-secondary text-light">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Date of Birth</label>
                            <input type="date" id="editBirthDate" class="form-control bg-secondary text-light">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Sex</label>
                            <select id="editSex" class="form-select bg-secondary text-light">
                                <option value="">Not specified</option>
                                <option value="M">Male</option>
                                <option value="F">Female</option>
                                <option value="O">Other</option>
                            </select>
                        </div>
                        <div class="alert alert-secondary">
                            <small><i class="bi bi-info-circle me-1"></i>Studies: <strong id="editStudyCount">0</strong></small>
                            <br><small class="text-muted">Note: Changes only update local cache, not DICOM files</small>
                        </div>
                    </form>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="savePatientEdit()">
                        <i class="bi bi-check2 me-2"></i>Save Changes
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Studies Modal -->
    <div class="modal fade" id="deleteStudiesModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content bg-dark text-light">
                <div class="modal-header border-secondary bg-danger bg-opacity-25">
                    <h5 class="modal-title"><i class="bi bi-trash3 me-2 text-danger"></i>Delete Studies Older Than <span id="deleteMonthsText">0</span> Months</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="deletePreviewContent"></div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmDeleteBtn" onclick="confirmDeleteStudies()" disabled>
                        <i class="bi bi-trash3 me-2"></i>Delete Studies
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Backup Studies Modal -->
    <div class="modal fade" id="backupStudiesModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content bg-dark text-light">
                <div class="modal-header border-secondary bg-info bg-opacity-25">
                    <h5 class="modal-title"><i class="bi bi-archive me-2 text-info"></i>Backup Studies Older Than <span id="backupMonthsText">0</span> Months</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="backupPreviewContent"></div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-info" id="confirmBackupBtn" onclick="confirmBackupStudies()" disabled>
                        <i class="bi bi-download me-2"></i>Download Backup
                    </button>
                </div>
            </div>
        </div>
    </div>
</body>
</html>