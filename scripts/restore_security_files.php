<?php
/**
 * Standalone CLI Script to Verify and Restore Hashing Backend Files
 * 
 * Usage:
 *   php scripts/restore_security_files.php           (Check and restore if needed)
 *   php scripts/restore_security_files.php --force   (Force overwrite from baseline)
 *   php scripts/restore_security_files.php --tamper  (Simulate tampering for demo)
 */

require_once __DIR__ . '/../includes/security_restore_service.php';

$service = new CampusSecurityRestoreService();
$arg = $argv[1] ?? '';

echo "========================================================\n";
echo "   CampusIQ Security & Hashing Files Integrity Tool     \n";
echo "========================================================\n\n";

if ($arg === '--tamper') {
    echo "[!] Simulating tampering on includes/security.php...\n";
    $res = $service->simulateTampering();
    echo "Result: {$res['message']}\n";
    echo "New SHA-256: {$res['new_hash']}\n";
    exit(0);
}

$status = $service->checkIntegrity();
echo "Integrity Status: " . ($status['all_intact'] ? "[OK - ALL FILES INTACT]" : "[WARNING - INTEGRITY COMPROMISED]") . "\n\n";

foreach ($status['files'] as $file => $info) {
    echo "File: {$file}\n";
    echo "  Status:   " . ($info['is_intact'] ? "MATCH (Pristine)" : "MISMATCH (Altered/Corrupt)") . "\n";
    echo "  Current:  {$info['current_hash']}\n";
    echo "  Baseline: {$info['baseline_hash']}\n";
    echo "  Modified: {$info['last_modified']}\n\n";
}

if (!$status['all_intact'] || $arg === '--force') {
    echo "[!] Restoring files from pristine baseline...\n";
    $restore = $service->restoreFiles();
    echo "{$restore['message']}\n";
    foreach ($restore['restored'] as $file => $rInfo) {
        echo "  - {$file}: Restored & Verified (" . ($rInfo['verified'] ? "OK" : "FAILED") . ")\n";
    }
} else {
    echo "[✓] All hashing backend files are 100% verified and pristine. No restoration needed.\n";
}
echo "\n========================================================\n";
