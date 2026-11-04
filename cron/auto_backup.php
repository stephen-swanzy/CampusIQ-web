<?php
/**
 * CampusIQ Automated Backup Runner
 * Can be run via:
 * 1. CLI: php cron/auto_backup.php
 * 2. Windows Task Scheduler: php C:\xampp\htdocs\campusIQ\cron\auto_backup.php
 * 3. Linux Cron: php /path/to/campusIQ/cron/auto_backup.php
 * 4. Web Cron (e.g. cron-job.org on free hosting): https://yourdomain.com/cron/auto_backup.php?token=SECRET_TOKEN
 */

require_once __DIR__ . '/../includes/backup_service.php';

$isCli = (php_sapi_name() === 'cli' || empty($_SERVER['REMOTE_ADDR']));
$service = new CampusBackupService();
$settings = $service->getSettings();

if (!$isCli) {
    header('Content-Type: application/json');
    $providedToken = $_GET['token'] ?? '';
    $expectedToken = $settings['cron_token'] ?? '';

    if (empty($expectedToken) || empty($providedToken) || !hash_equals($expectedToken, $providedToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Unauthorized cron token.']);
        exit;
    }
}

// Check force parameter
$isForced = !empty($_GET['force']) || (isset($argv) && in_array('--force', $argv));

// Check if auto backup is enabled
if (empty($settings['auto_backup_enabled']) && !$isForced) {
    $msg = 'Auto-backup is currently disabled in system settings.';
    if ($isCli) {
        echo "[INFO] {$msg}\n";
    } else {
        echo json_encode(['success' => false, 'message' => $msg]);
    }
    exit;
}

// Frequency check: prevent redundant multiple runs in same period unless force
if (!empty($settings['last_backup_at']) && !$isForced) {
    $lastTime = strtotime($settings['last_backup_at']);
    $freq = $settings['frequency'] ?? 'daily';
    $minSeconds = ($freq === 'weekly') ? (6 * 86400) : (20 * 3600); // 20 hours for daily

    if (time() - $lastTime < $minSeconds) {
        $msg = "Backup already completed recently ({$settings['last_backup_at']}). Next scheduled run not due yet.";
        if ($isCli) {
            echo "[SKIPPED] {$msg}\n";
        } else {
            echo json_encode(['success' => true, 'skipped' => true, 'message' => $msg]);
        }
        exit;
    }
}

if ($isCli) {
    echo "[START] Starting CampusIQ Automated Backup (" . date('Y-m-d H:i:s') . ")...\n";
}

$result = $service->createBackup(null, 'database');

if ($isCli) {
    if ($result['success']) {
        echo "[SUCCESS] {$result['message']}\n";
    } else {
        echo "[ERROR] {$result['message']}\n";
    }
} else {
    echo json_encode($result);
}
