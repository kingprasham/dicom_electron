<?php
/**
 * Permanent Study Merge API
 * 
 * Merges multiple studies into a single study permanently.
 * All images from merged studies are combined, and original studies are deleted.
 */

header('Content-Type: application/json');
session_start();

// Include database and Orthanc helpers
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/orthanc.php';

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Only POST method allowed']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['study_uids']) || !is_array($input['study_uids'])) {
    echo json_encode(['success' => false, 'error' => 'Missing study_uids array']);
    exit;
}

$studyUIDs = $input['study_uids'];

if (count($studyUIDs) < 2) {
    echo json_encode(['success' => false, 'error' => 'At least 2 studies required for merge']);
    exit;
}

try {
    $pdo = getDbConnection();
    $pdo->beginTransaction();
    
    // Fetch all studies to be merged
    $placeholders = implode(',', array_fill(0, count($studyUIDs), '?'));
    $stmt = $pdo->prepare("SELECT * FROM cached_studies WHERE study_instance_uid IN ($placeholders) ORDER BY study_date DESC, study_time DESC");
    $stmt->execute($studyUIDs);
    $studies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($studies) < 2) {
        throw new Exception('Could not find enough studies to merge');
    }
    
    // Use the LATEST study as the base (first in array due to ORDER BY DESC)
    $primaryStudy = $studies[0];
    $primaryUID = $primaryStudy['study_instance_uid'];
    $primaryOrthancId = $primaryStudy['orthanc_id'];
    
    // Collect all orthanc_ids for the studies being merged (except primary)
    $mergedOrthancIds = [];
    $totalImageCount = intval($primaryStudy['instance_count'] ?? 0);
    
    for ($i = 1; $i < count($studies); $i++) {
        $mergedOrthancIds[] = $studies[$i]['orthanc_id'];
        $totalImageCount += intval($studies[$i]['instance_count'] ?? 0);
    }
    
    // Update the primary study description to indicate merge
    $originalDesc = $primaryStudy['study_description'] ?: 'Study';
    $mergedDesc = $originalDesc . ' (Merged: ' . count($studies) . ' studies)';
    
    // Update primary study with new instance count and description
    $updateStmt = $pdo->prepare("
        UPDATE cached_studies 
        SET study_description = ?,
            instance_count = ?,
            merged_study_ids = ?
        WHERE study_instance_uid = ?
    ");
    $updateStmt->execute([
        $mergedDesc,
        $totalImageCount,
        json_encode(array_column(array_slice($studies, 1), 'study_instance_uid')),
        $primaryUID
    ]);
    
    // Store the merged orthanc IDs for later retrieval when viewing
    // We'll store them in a simple format that the load_study_fast.php can parse
    $mergedIdsStmt = $pdo->prepare("
        UPDATE cached_studies 
        SET merged_orthanc_ids = ?
        WHERE study_instance_uid = ?
    ");
    $mergedIdsStmt->execute([
        implode(',', $mergedOrthancIds),
        $primaryUID
    ]);
    
    // Delete the merged studies from cached_studies (keep only primary)
    $deleteStmt = $pdo->prepare("DELETE FROM cached_studies WHERE study_instance_uid IN ($placeholders) AND study_instance_uid != ?");
    $deleteParams = array_merge($studyUIDs, [$primaryUID]);
    $deleteStmt->execute($deleteParams);
    
    // Update the patient's study count
    $patientId = $primaryStudy['patient_id'];
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM cached_studies WHERE patient_id = ?");
    $countStmt->execute([$patientId]);
    $newCount = $countStmt->fetchColumn();
    
    $updatePatientStmt = $pdo->prepare("UPDATE cached_patients SET study_count = ? WHERE patient_id = ?");
    $updatePatientStmt->execute([$newCount, $patientId]);
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Studies merged successfully',
        'merged_study' => [
            'study_instance_uid' => $primaryUID,
            'orthanc_id' => $primaryOrthancId,
            'study_description' => $mergedDesc,
            'total_images' => $totalImageCount,
            'studies_merged' => count($studies)
        ]
    ]);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'success' => false,
        'error' => 'Merge failed: ' . $e->getMessage()
    ]);
}
