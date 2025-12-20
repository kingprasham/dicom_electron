<?php
/**
 * Location Management Page
 * DEPRECATED: Location management is now embedded in private-settings.php
 * This file redirects to private-settings.php
 */
define('DICOM_VIEWER', true);
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../auth/session.php';

requireLogin('../login.php');

// Redirect to private-settings.php where location management is now embedded
header('Location: ' . BASE_PATH . '/admin/private-settings.php');
exit;

// The code below is kept for reference but never executes

$userName = $_SESSION['username'] ?? 'Admin';
$db = getDbConnection();

// Check if user has 2FA enabled (for display purposes)
$stmt = $db->prepare("SELECT totp_enabled, totp_secret FROM users WHERE id = ?");
$stmt->bind_param("i", $_SESSION['user_id']);
$stmt->execute();
$result = $stmt->get_result();
$user2FA = $result->fetch_assoc();
$stmt->close();
$has2FAEnabled = ($user2FA && $user2FA['totp_enabled'] && !empty($user2FA['totp_secret']));

// Get locations with stats
$locations = [];
$result = $db->query("
    SELECT l.*,
           COUNT(DISTINCT ml.activation_id) as assigned_machines,
           (SELECT COUNT(*) FROM print_logs pl WHERE pl.location_id = l.id) as total_prints,
           (SELECT SUM(total_pages) FROM print_logs pl WHERE pl.location_id = l.id) as total_pages
    FROM locations l
    LEFT JOIN machine_locations ml ON l.id = ml.location_id AND ml.is_current = 1
    GROUP BY l.id
    ORDER BY l.location_name
");
while ($row = $result->fetch_assoc()) {
    $locations[] = $row;
}

// Get available machines (not yet assigned)
$machines = [];
$result = $db->query("
    SELECT la.id as activation_id, la.machine_id, la.machine_name, la.os_info, la.ip_address,
           ml.location_id as current_location_id,
           loc.location_name as current_location_name
    FROM license_activations la
    LEFT JOIN machine_locations ml ON la.id = ml.activation_id AND ml.is_current = 1
    LEFT JOIN locations loc ON ml.location_id = loc.id
    WHERE la.is_active = 1
    ORDER BY la.machine_name
");
while ($row = $result->fetch_assoc()) {
    $machines[] = $row;
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="base-path" content="<?= BASE_PATH ?>">
    <title>Location Management - DICOM Viewer</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            min-height: 100vh;
            color: #fff;
        }
        .card-custom {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
        }
        .stat-badge {
            background: rgba(13, 110, 253, 0.2);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
        }
        .location-card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 15px;
            transition: all 0.3s;
        }
        .location-card:hover {
            border-color: rgba(13, 110, 253, 0.5);
            transform: translateY(-2px);
        }
        .location-code {
            font-family: monospace;
            background: rgba(13, 110, 253, 0.2);
            padding: 3px 10px;
            border-radius: 6px;
            font-size: 0.9rem;
        }
        .machine-badge {
            background: rgba(25, 135, 84, 0.2);
            color: #198754;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
        }
        .print-stat {
            font-size: 0.85rem;
            color: #6c757d;
        }
        .table-custom {
            --bs-table-bg: transparent;
            --bs-table-color: #fff;
            --bs-table-border-color: rgba(255, 255, 255, 0.1);
        }
        .settings-nav-card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 15px;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
        <div class="container-fluid">
            <a class="navbar-brand" href="<?= BASE_PATH ?>/pages/patients.html">
                <i class="bi bi-heart-pulse-fill text-primary me-2"></i>DICOM Viewer Pro
            </a>
            <div class="d-flex align-items-center gap-3">
                <a href="<?= BASE_PATH ?>/admin/private-settings.php" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-arrow-left"></i> Back to Settings
                </a>
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

    <div class="container-fluid px-4">
        <!-- Settings Navigation -->
        <div class="settings-nav-card mb-4">
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <span class="text-muted me-2">Settings:</span>
                <a href="<?= BASE_PATH ?>/admin/general-settings.php" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-hospital"></i> General
                </a>
                <a href="<?= BASE_PATH ?>/admin/folder-config.php" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-folder-symlink"></i> Folder Monitoring
                </a>
                <a href="<?= BASE_PATH ?>/admin/private-settings.php" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-shield-lock"></i> Private Settings
                </a>
                <a href="<?= BASE_PATH ?>/admin/setup-2fa.php" class="btn btn-outline-success btn-sm">
                    <i class="bi bi-shield-check"></i> 2FA Security
                </a>
                <a href="<?= BASE_PATH ?>/admin/hospital-config.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-database"></i> Backup & Import
                </a>
                <a href="<?= BASE_PATH ?>/admin/location-management.php" class="btn btn-info btn-sm">
                    <i class="bi bi-geo-alt"></i> Location Management
                </a>
            </div>
        </div>

        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-1"><i class="bi bi-geo-alt-fill text-primary me-2"></i>Location Management</h4>
                <p class="text-muted mb-0">Manage rooms and locations where software is installed</p>
            </div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addLocationModal">
                <i class="bi bi-plus-lg me-2"></i>Add Location
            </button>
        </div>

        <div class="row">
            <!-- Locations List -->
            <div class="col-lg-8 mb-4">
                <div class="card-custom p-4">
                    <h5 class="mb-4"><i class="bi bi-building me-2"></i>All Locations</h5>

                    <?php if (empty($locations)): ?>
                        <div class="text-center py-5">
                            <i class="bi bi-geo-alt display-4 text-muted"></i>
                            <p class="text-muted mt-3">No locations created yet. Add your first location!</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($locations as $loc): ?>
                            <div class="location-card">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="d-flex align-items-center gap-3 mb-2">
                                            <span class="location-code"><?= htmlspecialchars($loc['location_code']) ?></span>
                                            <h6 class="mb-0"><?= htmlspecialchars($loc['location_name']) ?></h6>
                                            <?php if (!$loc['is_active']): ?>
                                                <span class="badge bg-danger">Inactive</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="d-flex gap-3 text-muted small">
                                            <?php if ($loc['department']): ?>
                                                <span><i class="bi bi-hospital me-1"></i><?= htmlspecialchars($loc['department']) ?></span>
                                            <?php endif; ?>
                                            <?php if ($loc['floor']): ?>
                                                <span><i class="bi bi-layers me-1"></i><?= htmlspecialchars($loc['floor']) ?></span>
                                            <?php endif; ?>
                                            <?php if ($loc['building']): ?>
                                                <span><i class="bi bi-building me-1"></i><?= htmlspecialchars($loc['building']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <button class="btn btn-sm btn-outline-primary" onclick="editLocation(<?= $loc['id'] ?>)">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-info" onclick="assignMachine(<?= $loc['id'] ?>, '<?= htmlspecialchars($loc['location_name']) ?>')">
                                            <i class="bi bi-pc-display"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger" onclick="deleteLocation(<?= $loc['id'] ?>)">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="d-flex gap-4 mt-3">
                                    <div class="print-stat">
                                        <span class="stat-badge">
                                            <i class="bi bi-pc-display me-1"></i>
                                            <?= $loc['assigned_machines'] ?> Machines
                                        </span>
                                    </div>
                                    <div class="print-stat">
                                        <span class="stat-badge">
                                            <i class="bi bi-printer me-1"></i>
                                            <?= number_format($loc['total_prints'] ?? 0) ?> Prints
                                        </span>
                                    </div>
                                    <div class="print-stat">
                                        <span class="stat-badge">
                                            <i class="bi bi-file-earmark me-1"></i>
                                            <?= number_format($loc['total_pages'] ?? 0) ?> Pages
                                        </span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Machine Assignments -->
            <div class="col-lg-4 mb-4">
                <div class="card-custom p-4">
                    <h5 class="mb-4"><i class="bi bi-pc-display me-2"></i>Machine Assignments</h5>

                    <?php if (empty($machines)): ?>
                        <div class="text-center py-4">
                            <p class="text-muted">No machines activated yet</p>
                        </div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($machines as $machine): ?>
                                <div class="list-group-item bg-transparent border-secondary-subtle px-0">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <div class="fw-medium"><?= htmlspecialchars($machine['machine_name'] ?: 'Unknown') ?></div>
                                            <small class="text-muted"><?= htmlspecialchars($machine['ip_address'] ?: 'No IP') ?></small>
                                        </div>
                                        <?php if ($machine['current_location_name']): ?>
                                            <span class="machine-badge">
                                                <i class="bi bi-geo-alt"></i>
                                                <?= htmlspecialchars($machine['current_location_name']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Unassigned</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Quick Stats -->
                <div class="card-custom p-4 mt-4">
                    <h5 class="mb-4"><i class="bi bi-bar-chart me-2"></i>Quick Stats</h5>
                    <div class="row text-center">
                        <div class="col-6 mb-3">
                            <h3 class="text-primary mb-0"><?= count($locations) ?></h3>
                            <small class="text-muted">Locations</small>
                        </div>
                        <div class="col-6 mb-3">
                            <h3 class="text-success mb-0"><?= count($machines) ?></h3>
                            <small class="text-muted">Machines</small>
                        </div>
                        <div class="col-6">
                            <h3 class="text-info mb-0"><?= count(array_filter($machines, fn($m) => $m['current_location_id'])) ?></h3>
                            <small class="text-muted">Assigned</small>
                        </div>
                        <div class="col-6">
                            <h3 class="text-warning mb-0"><?= count(array_filter($machines, fn($m) => !$m['current_location_id'])) ?></h3>
                            <small class="text-muted">Unassigned</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Location Modal -->
    <div class="modal fade" id="addLocationModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content bg-dark">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="bi bi-plus-lg me-2"></i>Add Location</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="addLocationForm">
                        <div class="mb-3">
                            <label class="form-label">Location Code <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="location_code" placeholder="e.g., SONO1, XRAY1" required>
                            <small class="text-muted">Short identifier for the location</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Location Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="location_name" placeholder="e.g., Sonography Room 1" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Department</label>
                            <input type="text" class="form-control" name="department" placeholder="e.g., Radiology">
                        </div>
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label">Floor</label>
                                <input type="text" class="form-control" name="floor" placeholder="e.g., Ground Floor">
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label">Building</label>
                                <input type="text" class="form-control" name="building" placeholder="e.g., Main Building">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="2" placeholder="Additional notes..."></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="saveLocation()">
                        <i class="bi bi-check-lg me-2"></i>Save Location
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Location Modal -->
    <div class="modal fade" id="editLocationModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content bg-dark">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Location</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="editLocationForm">
                        <input type="hidden" name="id" id="editLocationId">
                        <div class="mb-3">
                            <label class="form-label">Location Code</label>
                            <input type="text" class="form-control" name="location_code" id="editLocationCode" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Location Name</label>
                            <input type="text" class="form-control" name="location_name" id="editLocationName" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Department</label>
                            <input type="text" class="form-control" name="department" id="editDepartment">
                        </div>
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label">Floor</label>
                                <input type="text" class="form-control" name="floor" id="editFloor">
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label">Building</label>
                                <input type="text" class="form-control" name="building" id="editBuilding">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" id="editDescription" rows="2"></textarea>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" name="is_active" id="editIsActive" value="1">
                            <label class="form-check-label" for="editIsActive">Active</label>
                        </div>
                    </form>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="updateLocation()">
                        <i class="bi bi-check-lg me-2"></i>Update Location
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Assign Machine Modal -->
    <div class="modal fade" id="assignMachineModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content bg-dark">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title"><i class="bi bi-pc-display me-2"></i>Assign Machine to <span id="assignLocationName"></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="assignLocationId">
                    <div class="mb-3">
                        <label class="form-label">Select Machine</label>
                        <select class="form-select" id="machineSelect">
                            <?php foreach ($machines as $machine): ?>
                                <option value="<?= $machine['activation_id'] ?>">
                                    <?= htmlspecialchars($machine['machine_name'] ?: 'Unknown') ?>
                                    (<?= htmlspecialchars($machine['ip_address'] ?: 'No IP') ?>)
                                    <?php if ($machine['current_location_name']): ?>
                                        - Currently: <?= htmlspecialchars($machine['current_location_name']) ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" id="assignNotes" rows="2" placeholder="Optional notes about this assignment"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="confirmAssignMachine()">
                        <i class="bi bi-check-lg me-2"></i>Assign Machine
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const basePath = document.querySelector('meta[name="base-path"]')?.content || '';

        // Location data cache
        const locations = <?= json_encode($locations) ?>;

        // Save new location
        async function saveLocation() {
            const form = document.getElementById('addLocationForm');
            const formData = new FormData(form);
            const data = Object.fromEntries(formData);

            try {
                const response = await fetch(`${basePath}/api/locations/`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });

                const result = await response.json();

                if (result.success) {
                    alert('Location created successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + result.error);
                }
            } catch (error) {
                alert('Error creating location: ' + error.message);
            }
        }

        // Edit location
        function editLocation(id) {
            const loc = locations.find(l => l.id == id);
            if (!loc) return;

            document.getElementById('editLocationId').value = loc.id;
            document.getElementById('editLocationCode').value = loc.location_code;
            document.getElementById('editLocationName').value = loc.location_name;
            document.getElementById('editDepartment').value = loc.department || '';
            document.getElementById('editFloor').value = loc.floor || '';
            document.getElementById('editBuilding').value = loc.building || '';
            document.getElementById('editDescription').value = loc.description || '';
            document.getElementById('editIsActive').checked = loc.is_active == 1;

            new bootstrap.Modal(document.getElementById('editLocationModal')).show();
        }

        // Update location
        async function updateLocation() {
            const form = document.getElementById('editLocationForm');
            const formData = new FormData(form);
            const data = Object.fromEntries(formData);
            data.is_active = document.getElementById('editIsActive').checked ? 1 : 0;

            try {
                const response = await fetch(`${basePath}/api/locations/`, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });

                const result = await response.json();

                if (result.success) {
                    alert('Location updated successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + result.error);
                }
            } catch (error) {
                alert('Error updating location: ' + error.message);
            }
        }

        // Delete location
        async function deleteLocation(id) {
            if (!confirm('Are you sure you want to delete this location?')) return;

            try {
                const response = await fetch(`${basePath}/api/locations/?id=${id}`, {
                    method: 'DELETE'
                });

                const result = await response.json();

                if (result.success) {
                    alert(result.message);
                    location.reload();
                } else {
                    alert('Error: ' + result.error);
                }
            } catch (error) {
                alert('Error deleting location: ' + error.message);
            }
        }

        // Assign machine to location
        function assignMachine(locationId, locationName) {
            document.getElementById('assignLocationId').value = locationId;
            document.getElementById('assignLocationName').textContent = locationName;
            new bootstrap.Modal(document.getElementById('assignMachineModal')).show();
        }

        // Confirm machine assignment
        async function confirmAssignMachine() {
            const locationId = document.getElementById('assignLocationId').value;
            const activationId = document.getElementById('machineSelect').value;
            const notes = document.getElementById('assignNotes').value;

            try {
                const response = await fetch(`${basePath}/api/locations/assign-machine.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        location_id: locationId,
                        activation_id: activationId,
                        notes: notes
                    })
                });

                const result = await response.json();

                if (result.success) {
                    alert('Machine assigned successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + result.error);
                }
            } catch (error) {
                alert('Error assigning machine: ' + error.message);
            }
        }
    </script>
</body>
</html>
