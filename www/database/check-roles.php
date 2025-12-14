<?php
define('DICOM_VIEWER', true);
require_once __DIR__ . '/../includes/config.php';

$m = getDbConnection();
$r = $m->query('SELECT username, role FROM users');
while ($u = $r->fetch_assoc()) {
    echo $u['username'] . ': ' . $u['role'] . "\n";
}
