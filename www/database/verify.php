<?php
define('DICOM_VIEWER', true);
require_once __DIR__ . '/../includes/config.php';

$m = getDbConnection();

echo "=== VERIFICATION ===\n";

// Check hospitals
$r = $m->query("SELECT id, hospital_name FROM hospitals");
echo "\nHospitals:\n";
while ($h = $r->fetch_assoc()) {
    echo "  - ID {$h['id']}: {$h['hospital_name']}\n";
}

// Check access mappings
$r = $m->query("SELECT COUNT(*) as c FROM user_hospital_access");
echo "\nUser-Hospital Access Mappings: " . $r->fetch_assoc()['c'] . "\n";

// Check columns
$r = $m->query("SHOW COLUMNS FROM cached_studies LIKE 'clinic_location'");
echo "\nClinic Location Column: " . ($r->num_rows > 0 ? "EXISTS" : "MISSING") . "\n";

$r = $m->query("SHOW COLUMNS FROM cached_studies LIKE 'hospital_id'");
echo "Hospital ID Column (studies): " . ($r->num_rows > 0 ? "EXISTS" : "MISSING") . "\n";

$r = $m->query("SHOW COLUMNS FROM cached_patients LIKE 'hospital_id'");
echo "Hospital ID Column (patients): " . ($r->num_rows > 0 ? "EXISTS" : "MISSING") . "\n";

// Check data
$r = $m->query("SELECT COUNT(*) as c FROM cached_studies WHERE clinic_location IS NOT NULL");
echo "\nStudies with clinic_location: " . $r->fetch_assoc()['c'] . "\n";

$r = $m->query("SELECT COUNT(*) as c FROM cached_studies WHERE accession_number IS NOT NULL AND accession_number != ''");
echo "Studies with accession_number: " . $r->fetch_assoc()['c'] . "\n";

echo "\n=== DONE ===\n";
