<?php
require_once __DIR__ . '/../config/app.php';

try {
    $db = getDb();
    $sql = file_get_contents(__DIR__ . '/deleted_users_archive.sql');
    $db->exec($sql);
    echo "SUCCESS: deleted_users_archive table is ready in database!\n";
    
    // Check if we can also create campusiq_archive database locally
    try {
        $db->exec("CREATE DATABASE IF NOT EXISTS campusiq_archive;");
        $db->exec("CREATE TABLE IF NOT EXISTS campusiq_archive.deleted_users LIKE deleted_users_archive;");
        echo "SUCCESS: Secondary database 'campusiq_archive.deleted_users' created!\n";
    } catch (Exception $eSecondary) {
        echo "NOTE: Secondary standalone database 'campusiq_archive' skipped (shared host environment): " . $eSecondary->getMessage() . "\n";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
