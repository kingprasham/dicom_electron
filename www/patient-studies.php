<?php
/**
 * Patient Studies Page
 * Shows all studies for a specific patient
 */
define('DICOM_VIEWER', true);
require_once __DIR__ . '/auth/session.php';
requireLogin();

// Get patient ID
$patientOrthancId = $_GET['patient_id'] ?? '';
if (!$patientOrthancId) {
    header('Location: ' . BASE_PATH . '/patients.php');
    exit;
}

// Get database connection
$mysqli = getDbConnection();

// Get patient info
$stmt = $mysqli->prepare("SELECT * FROM cached_patients WHERE orthanc_id = ?");
$stmt->bind_param("s", $patientOrthancId);
$stmt->execute();
$patient = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$patient) {
    header('Location: ' . BASE_PATH . '/patients.php');
    exit;
}

// Sync studies from Orthanc
try {
    $ch = curl_init(ORTHANC_URL . '/patients/' . $patientOrthancId . '/studies');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, ORTHANC_USER . ':' . ORTHANC_PASS);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Get current clinic location
    $clinicLocationStmt = $mysqli->prepare("SELECT setting_value FROM hospital_settings WHERE setting_key = 'clinic_location_name'");
    $clinicLocationStmt->execute();
    $clinicLocationResult = $clinicLocationStmt->get_result()->fetch_assoc();
    $currentClinicLocation = $clinicLocationResult['setting_value'] ?? 'Main Clinic';
    $clinicLocationStmt->close();

    if ($httpCode === 200) {
        $studyIds = json_decode($response, true);

        foreach ($studyIds as $studyId) {
            // Get study details
            $ch = curl_init(ORTHANC_URL . '/studies/' . $studyId);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERPWD, ORTHANC_USER . ':' . ORTHANC_PASS);
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            $studyData = json_decode(curl_exec($ch), true);
            curl_close($ch);

            if ($studyData) {
                $studyInstanceUID = $studyData['MainDicomTags']['StudyInstanceUID'] ?? '';
                $studyDescription = $studyData['MainDicomTags']['StudyDescription'] ?? 'No Description';
                $studyDate = $studyData['MainDicomTags']['StudyDate'] ?? date('Ymd');
                $studyTime = $studyData['MainDicomTags']['StudyTime'] ?? '';
                $accessionNumber = $studyData['MainDicomTags']['AccessionNumber'] ?? '';

                // Get modalities from series
                $modalities = [];
                if (isset($studyData['Series'])) {
                    foreach ($studyData['Series'] as $seriesId) {
                        $ch = curl_init(ORTHANC_URL . '/series/' . $seriesId);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_USERPWD, ORTHANC_USER . ':' . ORTHANC_PASS);
                        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
                        $seriesData = json_decode(curl_exec($ch), true);
                        curl_close($ch);

                        if (isset($seriesData['MainDicomTags']['Modality'])) {
                            $modalities[] = $seriesData['MainDicomTags']['Modality'];
                        }
                    }
                }
                $modalitiesStr = implode(',', array_unique($modalities));

                // Insert or update study with clinic location
                $stmt = $mysqli->prepare("
                    INSERT INTO cached_studies (
                        orthanc_id, study_instance_uid, patient_id,
                        study_description, study_date, study_time,
                        accession_number, clinic_location, modalities, last_sync
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        study_description = VALUES(study_description),
                        study_date = VALUES(study_date),
                        study_time = VALUES(study_time),
                        accession_number = VALUES(accession_number),
                        clinic_location = VALUES(clinic_location),
                        modalities = VALUES(modalities),
                        last_sync = NOW()
                ");
                $stmt->bind_param(
                    "sssssssss",
                    $studyId,
                    $studyInstanceUID,
                    $patient['patient_id'],
                    $studyDescription,
                    $studyDate,
                    $studyTime,
                    $accessionNumber,
                    $currentClinicLocation,
                    $modalitiesStr
                );
                $stmt->execute();
                $stmt->close();
            }
        }
    }
} catch (Exception $e) {
    // Continue even if sync fails
}

// Get filter parameters
$accessionFilter = $_GET['accession'] ?? '';
$clinicFilter = $_GET['clinic'] ?? '';

// Get multi-clinic mode setting
$multiClinicStmt = $mysqli->prepare("SELECT setting_value FROM hospital_settings WHERE setting_key = 'multi_clinic_mode'");
$multiClinicStmt->execute();
$multiClinicResult = $multiClinicStmt->get_result()->fetch_assoc();
$isMultiClinicMode = ($multiClinicResult['setting_value'] ?? 'false') === 'true';
$multiClinicStmt->close();

// Build query with filters
$whereClauses = ["s.patient_id = ?"];
$params = [$patient['patient_id']];
$types = "s";

if (!empty($accessionFilter)) {
    $whereClauses[] = "s.accession_number LIKE ?";
    $params[] = "%$accessionFilter%";
    $types .= "s";
}

if (!empty($clinicFilter)) {
    $whereClauses[] = "s.clinic_location = ?";
    $params[] = $clinicFilter;
    $types .= "s";
}

$whereSQL = implode(" AND ", $whereClauses);

// Get all studies for this patient with printed status and new status
$stmt = $mysqli->prepare("
    SELECT s.*, 
           (CASE WHEN r.status = 'printed' THEN 1 ELSE 0 END) as is_printed
    FROM cached_studies s
    LEFT JOIN medical_reports r ON s.orthanc_id = r.study_uid
    WHERE $whereSQL
    ORDER BY s.study_date DESC, s.study_time DESC
");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$studies = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get all clinic locations for filter dropdown
$clinicsStmt = $mysqli->query("SELECT DISTINCT clinic_location FROM cached_studies WHERE clinic_location IS NOT NULL ORDER BY clinic_location");
$allClinics = $clinicsStmt->fetch_all(MYSQLI_ASSOC);
$clinicsStmt->close();
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Studies - DICOM Viewer</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #0a0e27 0%, #1a1f3a 100%);
            min-height: 100vh;
        }
        .navbar-custom {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        .patient-info-card {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .study-card {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            transition: all 0.3s;
            cursor: pointer;
        }
        .study-card:hover {
            background: rgba(255, 255, 255, 0.08);
            border-color: #0d6efd;
            transform: translateX(5px);
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-custom mb-4">
        <div class="container-fluid">
            <a class="navbar-brand text-white" href="<?= BASE_PATH ?>/patients.php">
                <i class="bi bi-arrow-left me-2"></i>
                Back to Patients
            </a>
            <div class="d-flex align-items-center">
                <a href="<?= BASE_PATH ?>/logout.php" class="btn btn-outline-danger btn-sm">
                    <i class="bi bi-box-arrow-right"></i> Logout
                </a>
            </div>
        </div>
    </nav>

    <div class="container">
        <!-- Patient Info -->
        <div class="patient-info-card">
            <h3 class="text-white mb-3">
                <i class="bi bi-person-badge text-primary me-2"></i>
                <?= htmlspecialchars($patient['patient_name']) ?>
            </h3>
            <div class="row">
                <div class="col-md-3">
                    <strong class="text-light">Patient ID:</strong>
                    <div class="text-muted"><?= htmlspecialchars($patient['patient_id']) ?></div>
                </div>
                <?php if ($patient['birth_date']): ?>
                <div class="col-md-3">
                    <strong class="text-light">Date of Birth:</strong>
                    <div class="text-muted"><?= htmlspecialchars($patient['birth_date']) ?></div>
                </div>
                <?php endif; ?>
                <?php if ($patient['sex']): ?>
                <div class="col-md-3">
                    <strong class="text-light">Gender:</strong>
                    <div class="text-muted">
                        <?= $patient['sex'] === 'M' ? 'Male' : ($patient['sex'] === 'F' ? 'Female' : 'Other') ?>
                    </div>
                </div>
                <?php endif; ?>
                <div class="col-md-3">
                    <strong class="text-light">Total Studies:</strong>
                    <div class="text-info"><?= count($studies) ?></div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card mb-3" style="background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1);">
            <div class="card-body">
                <form method="GET" action="" class="row g-3">
                    <input type="hidden" name="patient_id" value="<?= htmlspecialchars($patientOrthancId) ?>">
                    
                    <div class="col-md-4">
                        <label class="form-label text-light">
                            <i class="bi bi-funnel me-1"></i> Filter by Accession Number
                        </label>
                        <input type="text" 
                               name="accession" 
                               class="form-control bg-secondary text-light" 
                               placeholder="Enter accession number..."
                               value="<?= htmlspecialchars($accessionFilter) ?>">
                    </div>
                    
                    <?php if ($isMultiClinicMode && count($allClinics) > 1): ?>
                    <div class="col-md-4">
                        <label class="form-label text-light">
                            <i class="bi bi-geo-alt me-1"></i> Filter by Clinic
                        </label>
                        <select name="clinic" class="form-select bg-secondary text-light">
                            <option value="">All Clinics</option>
                            <?php foreach ($allClinics as $clinic): ?>
                                <option value="<?= htmlspecialchars($clinic['clinic_location']) ?>"
                                        <?= $clinicFilter === $clinic['clinic_location'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($clinic['clinic_location']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    
                    <div class="col-md-4 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-search"></i> Apply Filters
                        </button>
                        <?php if (!empty($accessionFilter) || !empty($clinicFilter)): ?>
                        <a href="?patient_id=<?= urlencode($patientOrthancId) ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-x-circle"></i> Clear
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- Studies List -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="text-white">
                <i class="bi bi-file-medical text-primary me-2"></i>
                Studies
            </h4>
            <div class="d-flex gap-2">
                <button class="btn btn-outline-info btn-sm" id="mergeBtn" disabled onclick="mergeSelectedStudies()">
                    <i class="bi bi-collection-play me-1"></i> Merge Selected
                </button>
                <button class="btn btn-primary btn-sm" onclick="window.location.reload()">
                    <i class="bi bi-arrow-clockwise"></i> Refresh
                </button>
            </div>
        </div>

        <?php if (empty($studies)): ?>
            <div class="alert alert-info text-center">
                <i class="bi bi-inbox fs-1 d-block mb-3"></i>
                <h5>No studies found for this patient</h5>
                <p>Send DICOM studies from your imaging equipment</p>
            </div>
        <?php else: ?>
            <!-- Studies Table -->
            <div class="table-responsive">
                <table class="table table-dark table-hover table-striped">
                    <thead>
                        <tr>
                            <th style="width: 40px;">
                                <input type="checkbox" class="form-check-input" id="selectAllCb" onchange="toggleSelectAll()">
                            </th>
                            <th>Study Description</th>
                            <th>Study Date</th>
                            <th>Accession #</th>
                            <?php if ($isMultiClinicMode): ?>
                            <th>Clinic Location</th>
                            <?php endif; ?>
                            <th>Modalities</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($studies as $study): ?>
                            <tr>
                                <td>
                                    <input type="checkbox" class="form-check-input study-cb" value="<?= $study['orthanc_id'] ?>" onchange="updateMergeButton()">
                                </td>
                                <td>
                                    <?php if ($study['is_new']): ?>
                                        <span class="badge bg-danger me-2">NEW</span>
                                    <?php endif; ?>
                                    <strong><?= htmlspecialchars($study['study_description']) ?></strong>
                                    <?php if ($study['is_printed']): ?>
                                        <span class="badge bg-info ms-2"><i class="bi bi-printer-fill"></i> Printed</span>
                                    <?php endif; ?>
                                    <div class="small text-muted mt-1">UID: <?= htmlspecialchars(substr($study['study_instance_uid'], 0, 20)) ?>...</div>
                                </td>
                                <td>
                                    <?= date('Y-m-d', strtotime($study['study_date'])) ?>
                                    <?php if ($study['study_time']): ?>
                                        <br><small class="text-muted"><?= substr($study['study_time'], 0, 2) . ':' . substr($study['study_time'], 2, 2) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($study['accession_number'] ?: '-') ?></td>
                                <?php if ($isMultiClinicMode): ?>
                                <td>
                                    <?php if ($study['clinic_location']): ?>
                                        <span class="badge bg-success">
                                            <i class="bi bi-geo-alt-fill"></i>
                                            <?= htmlspecialchars($study['clinic_location']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                                <td>
                                    <?php if ($study['modalities']): ?>
                                        <?php foreach (explode(',', $study['modalities']) as $modality): ?>
                                            <span class="badge bg-info me-1"><?= htmlspecialchars($modality) ?></span>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <div class="btn-group" role="group">
                                        <button type="button"
                                                class="btn btn-sm btn-primary"
                                                onclick="markAsRead('<?= $study['orthanc_id'] ?>'); viewStudy('<?= $study['orthanc_id'] ?>')"
                                                title="View Images">
                                            <i class="bi bi-eye"></i> View
                                        </button>
                                        <button type="button"
                                                class="btn btn-sm btn-success"
                                                onclick="exportToJPG('<?= $study['study_instance_uid'] ?>', '<?= addslashes($study['study_description']) ?>')"
                                                title="Export all images as JPG">
                                            <i class="bi bi-download"></i> JPA
                                        </button>
                                        <button type="button"
                                                class="btn btn-sm btn-info"
                                                onclick="showSendModal('<?= $study['orthanc_id'] ?>', '<?= addslashes($study['study_description']) ?>')"
                                                title="Send to PACS/Node">
                                            <i class="bi bi-send"></i> Send
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Send Modal -->
    <div class="modal fade" id="sendModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content bg-dark text-light">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-broadcast me-2"></i>
                        Send Study: <span id="sendStudyName"></span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Select Destination Node</label>
                        <select id="destinationNode" class="form-select bg-secondary text-light">
                            <option value="">Loading nodes...</option>
                        </select>
                        <div class="form-text text-muted">
                            The selected node will be temporarily registered in Orthanc if needed.
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="sendStudy()">
                        <i class="bi bi-send"></i> Send Data
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Remark Modal -->
    <div class="modal fade" id="remarkModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content bg-dark text-light">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-chat-square-text me-2"></i>
                        Study Remarks: <span id="remarkStudyName"></span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Add New Remark Form -->
                    <div class="mb-4">
                        <label class="form-label fw-bold">Add New Remark</label>
                        <textarea id="newRemarkText" class="form-control bg-secondary text-light" rows="3" placeholder="Enter your remark here..."></textarea>
                        <button class="btn btn-primary mt-2" onclick="saveRemark()">
                            <i class="bi bi-plus-circle me-1"></i> Add Remark
                        </button>
                    </div>

                    <!-- Existing Remarks -->
                    <div>
                        <label class="form-label fw-bold">Previous Remarks</label>
                        <div id="remarksList">
                            <div class="text-center text-muted py-3">
                                <i class="bi bi-hourglass-split"></i> Loading remarks...
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let currentStudyUID = null;
        let currentOrthancId = null;
        const remarkModal = new bootstrap.Modal(document.getElementById('remarkModal'));
        const sendModal = new bootstrap.Modal(document.getElementById('sendModal'));

        function viewStudy(orthancId) {
            window.location.href = '<?= BASE_PATH ?>/index.php?study_id=' + encodeURIComponent(orthancId);
        }

        // Merge Logic
        function toggleSelectAll() {
            const selectAll = document.getElementById('selectAllCb');
            const checkboxes = document.querySelectorAll('.study-cb');
            checkboxes.forEach(cb => cb.checked = selectAll.checked);
            updateMergeButton();
        }

        function updateMergeButton() {
            const checkboxes = document.querySelectorAll('.study-cb:checked');
            const mergeBtn = document.getElementById('mergeBtn');
            const count = checkboxes.length;
            
            mergeBtn.disabled = count < 2;
            mergeBtn.innerHTML = count > 1 ? `<i class="bi bi-collection-play me-1"></i> Merge ${count} Studies` : '<i class="bi bi-collection-play me-1"></i> Merge Selected';
        }

        function mergeSelectedStudies() {
            const checkboxes = document.querySelectorAll('.study-cb:checked');
            if (checkboxes.length < 2) return;
            
            const ids = Array.from(checkboxes).map(cb => cb.value);
            // Join IDs with comma
            const mergedIds = ids.join(',');
            
            // Mark all as read
            ids.forEach(id => markAsRead(id));
            
            // Navigate to viewer with multiple IDs
            window.location.href = '<?= BASE_PATH ?>/index.php?study_id=' + encodeURIComponent(mergedIds);
        }

        async function showSendModal(orthancId, studyName) {
            currentOrthancId = orthancId;
            document.getElementById('sendStudyName').textContent = studyName;
            
            // Load nodes
            const select = document.getElementById('destinationNode');
            select.innerHTML = '<option value="">Loading...</option>';
            select.disabled = true;
            
            try {
                const response = await fetch('<?= BASE_PATH ?>/api/settings/nodes.php');
                const data = await response.json();
                
                if (data.success && data.nodes) {
                    select.innerHTML = '<option value="">Select a node...</option>' + 
                        data.nodes.map(node => `<option value="${node.id}">${node.name} (${node.ae_title} @ ${node.host_name}:${node.port})</option>`).join('');
                    select.disabled = false;
                } else {
                    select.innerHTML = '<option value="">No nodes configured</option>';
                }
            } catch (error) {
                console.error('Error loading nodes:', error);
                select.innerHTML = '<option value="">Error loading nodes</option>';
            }
            
            sendModal.show();
        }

        async function sendStudy() {
            const nodeId = document.getElementById('destinationNode').value;
            if (!nodeId) {
                alert('Please select a destination node');
                return;
            }
            
            const btn = event.target;
            const originalHTML = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Sending...';
            
            try {
                const response = await fetch('<?= BASE_PATH ?>/api/dicom/send-to-node.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        node_id: nodeId,
                        study_id: currentOrthancId
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('Successfully initiated send to ' + result.node);
                    sendModal.hide();
                } else {
                    throw new Error(result.error || 'Failed to send study');
                }
            } catch (error) {
                alert('Error sending study: ' + error.message);
            } finally {
                btn.disabled = false;
                btn.innerHTML = originalHTML;
            }
        }

        async function exportToJPG(studyUID, studyDescription) {
            const btn = event.target.closest('button');
            const originalHTML = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Exporting...';

            try {
                const response = await fetch(`<?= BASE_PATH ?>/api/studies/export-images.php?study_uid=${encodeURIComponent(studyUID)}`);

                if (!response.ok) {
                    const errorData = await response.json();
                    throw new Error(errorData.error || 'Failed to export images');
                }

                // Create a blob from the response
                const blob = await response.blob();

                // Create a download link
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;

                // Get filename from Content-Disposition header or use default
                const contentDisposition = response.headers.get('Content-Disposition');
                let filename = `Study_${studyDescription.replace(/[^a-zA-Z0-9]/g, '_')}_images.zip`;
                if (contentDisposition) {
                    const matches = /filename="(.+)"/.exec(contentDisposition);
                    if (matches) filename = matches[1];
                }

                a.download = filename;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                window.URL.revokeObjectURL(url);

                alert('Images exported successfully!');
            } catch (error) {
                console.error('Export error:', error);
                alert('Error exporting images: ' + error.message);
            } finally {
                btn.disabled = false;
                btn.innerHTML = originalHTML;
            }
        }

        async function showRemarkModal(studyUID, studyDescription) {
            currentStudyUID = studyUID;
            document.getElementById('remarkStudyName').textContent = studyDescription;
            document.getElementById('newRemarkText').value = '';

            remarkModal.show();
            await loadRemarks();
        }

        async function loadRemarks() {
            const remarksList = document.getElementById('remarksList');
            remarksList.innerHTML = '<div class="text-center text-muted py-3"><i class="bi bi-hourglass-split"></i> Loading remarks...</div>';

            try {
                const response = await fetch(`<?= BASE_PATH ?>/api/studies/remarks.php?study_uid=${encodeURIComponent(currentStudyUID)}`);
                const data = await response.json();

                if (data.success) {
                    if (data.remarks.length === 0) {
                        remarksList.innerHTML = '<div class="text-center text-muted py-3"><i class="bi bi-inbox"></i> No remarks yet</div>';
                    } else {
                        remarksList.innerHTML = data.remarks.map(remark => `
                            <div class="card bg-secondary mb-2">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div class="flex-grow-1">
                                            <p class="mb-2">${escapeHtml(remark.remark)}</p>
                                            <small class="text-muted">
                                                <i class="bi bi-person"></i> ${escapeHtml(remark.created_by_name || 'Unknown')}
                                                (${escapeHtml(remark.created_by_role || 'N/A')})
                                                <i class="bi bi-clock ms-2"></i> ${new Date(remark.created_at).toLocaleString()}
                                                ${remark.created_at !== remark.updated_at ? '<i class="bi bi-pencil ms-2"></i> Updated: ' + new Date(remark.updated_at).toLocaleString() : ''}
                                            </small>
                                        </div>
                                        <button class="btn btn-sm btn-danger ms-2" onclick="deleteRemark(${remark.id})" title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        `).join('');
                    }
                }
            } catch (error) {
                console.error('Error loading remarks:', error);
                remarksList.innerHTML = '<div class="alert alert-danger">Failed to load remarks</div>';
            }
        }

        async function saveRemark() {
            const remarkText = document.getElementById('newRemarkText').value.trim();

            if (!remarkText) {
                alert('Please enter a remark');
                return;
            }

            try {
                const response = await fetch('<?= BASE_PATH ?>/api/studies/remarks.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        study_uid: currentStudyUID,
                        remark: remarkText
                    })
                });

                const data = await response.json();

                if (data.success) {
                    document.getElementById('newRemarkText').value = '';
                    await loadRemarks();
                    alert('Remark added successfully!');
                } else {
                    throw new Error(data.error || 'Failed to save remark');
                }
            } catch (error) {
                console.error('Error saving remark:', error);
                alert('Error saving remark: ' + error.message);
            }
        }

        async function deleteRemark(remarkId) {
            if (!confirm('Are you sure you want to delete this remark?')) {
                return;
            }

            try {
                const response = await fetch(`<?= BASE_PATH ?>/api/studies/remarks.php?id=${remarkId}`, {
                    method: 'DELETE'
                });

                const data = await response.json();

                if (data.success) {
                    await loadRemarks();
                    alert('Remark deleted successfully!');
                } else {
                    throw new Error(data.error || 'Failed to delete remark');
                }
            } catch (error) {
                console.error('Error deleting remark:', error);
                alert('Error deleting remark: ' + error.message);
            }
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    // Send Modal Logic
    // ... existing code ...

    // Mark as Read Logic
    async function markAsRead(orthancId) {
        try {
            // Non-blocking call
            const basePath = document.querySelector('meta[name="base-path"]')?.content || '<?= BASE_PATH ?>';
            fetch(`${basePath}/api/studies/mark-read.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ orthanc_id: orthancId })
            });
            // We don't wait for response, just navigate
        } catch (e) {
            console.warn('Failed to mark read', e);
        }
    }
    
    // Auto-refresh study count
    // ... existing code ...
    </script>
</body>
</html>