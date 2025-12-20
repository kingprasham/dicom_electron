<?php
/**
 * Check Report Existence
 * Checks if a medical report exists for a given image ID or study UID
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

define('DICOM_VIEWER', true);
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/auth/session.php';

// Check authentication
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated', 'exists' => false]);
    exit;
}

try {
    // Get parameters
    $imageId = $_GET['imageId'] ?? '';
    $studyUID = $_GET['studyUID'] ?? '';

    // Validate input
    if (empty($imageId) && empty($studyUID)) {
        echo json_encode([
            'success' => false,
            'error' => 'Either imageId or studyUID is required',
            'exists' => false
        ]);
        exit;
    }

    // Get database connection (from config.php already included above)
    $db = getDbConnection();

    $reportExists = false;
    $reportData = null;

    // If studyUID is provided, check directly in medical_reports
    if (!empty($studyUID)) {
        $stmt = $db->prepare("
            SELECT id, study_uid, status, created_at, updated_at, finalized_at
            FROM medical_reports
            WHERE study_uid = ?
            LIMIT 1
        ");
        $stmt->bind_param("s", $studyUID);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            $reportExists = true;
            $reportData = $row;
        }
        $stmt->close();
    }

    // If imageId is provided and no report found yet, look up study_uid from dicom_files
    if (!$reportExists && !empty($imageId)) {
        // First get the study_uid from dicom_files table
        $stmt = $db->prepare("
            SELECT study_instance_uid
            FROM dicom_files
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->bind_param("s", $imageId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            $studyUIDFromImage = $row['study_instance_uid'];
            $stmt->close();

            // Now check if report exists for this study
            if (!empty($studyUIDFromImage)) {
                $stmt = $db->prepare("
                    SELECT id, study_uid, status, created_at, updated_at, finalized_at
                    FROM medical_reports
                    WHERE study_uid = ?
                    LIMIT 1
                ");
                $stmt->bind_param("s", $studyUIDFromImage);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($row = $result->fetch_assoc()) {
                    $reportExists = true;
                    $reportData = $row;
                }
                $stmt->close();
            }
        } else {
            $stmt->close();
        }
    }

    $db->close();

    // Return result
    echo json_encode([
        'success' => true,
        'exists' => $reportExists,
        'data' => $reportData
    ]);

} catch (Exception $e) {
    error_log("Error in check_report.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error',
        'exists' => false
    ]);
}
