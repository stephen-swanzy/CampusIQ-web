<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/backup_service.php';

requireLogin(['super_admin', 'school_admin']);

$db = getDb();
$currentUser = currentUser();
$service = new CampusBackupService();
$backupSettings = $service->getSettings();
$success = '';
$error = '';

// 1. Download action
if (isset($_GET['download'])) {
    $downloadId = (int) $_GET['download'];
    $stmt = $db->prepare('SELECT * FROM backups WHERE id = :id');
    $stmt->execute(['id' => $downloadId]);
    $backup = $stmt->fetch();

    if ($backup && file_exists($backup['file_path'])) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($backup['filename']) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($backup['file_path']));
        readfile($backup['file_path']);
        exit;
    } else {
        $error = 'Backup file not found on disk.';
    }
}

// 2. POST Actions (Backup Now, Save Settings, Sync Cloud, Delete, Restore)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();
    $action = $_POST['action'] ?? '';

    if ($action === 'backup_now') {
        $result = $service->createBackup($currentUser['id'], 'database');
        if ($result['success']) {
            $success = $result['message'];
        } else {
            $error = $result['message'];
        }
    } elseif ($action === 'save_settings') {
        $updated = $service->updateSettings($_POST);
        if ($updated) {
            $backupSettings = $service->getSettings();
            $success = 'Backup & cloud configuration updated successfully.';
        } else {
            $error = 'Failed to update backup settings.';
        }
    } elseif ($action === 'sync_cloud') {
        $backupId = (int) ($_POST['backup_id'] ?? 0);
        $stmt = $db->prepare('SELECT * FROM backups WHERE id = :id');
        $stmt->execute(['id' => $backupId]);
        $backup = $stmt->fetch();

        if ($backup && file_exists($backup['file_path'])) {
            $cloudType = $backupSettings['cloud_storage_type'] ?? 'none';
            if ($cloudType === 'cloudflare_r2' || $cloudType === 'aws_s3') {
                $cloudRes = $service->uploadToS3Cloud($backup['file_path'], $backup['filename'], $backupSettings);
                if ($cloudRes['synced']) {
                    $db->prepare('UPDATE backups SET status = "synced_to_cloud", cloud_provider = :provider, cloud_key = :key WHERE id = :id')
                        ->execute(['provider' => $cloudType, 'key' => $backup['filename'], 'id' => $backupId]);
                    $success = 'Backup synced to cloud successfully.';
                } else {
                    $error = $cloudRes['message'];
                }
            } elseif ($cloudType === 'email') {
                $recipient = !empty($backupSettings['email_backup_recipient']) ? $backupSettings['email_backup_recipient'] : 'swanzyak47@gmail.com';
                $emailRes = $service->dispatchBackupEmail($backup['file_path'], $backup['filename'], $recipient);
                if ($emailRes['synced']) {
                    $db->prepare('UPDATE backups SET status = "synced_to_cloud", cloud_provider = "email", cloud_key = :key WHERE id = :id')
                        ->execute(['key' => $recipient, 'id' => $backupId]);
                    $success = "Backup dispatched to {$recipient} via email.";
                } else {
                    $error = $emailRes['message'];
                }
            } else {
                $error = 'No cloud storage provider configured. Please configure Cloudflare R2, AWS S3, or Email in settings.';
            }
        } else {
            $error = 'Backup file not found.';
        }
    } elseif ($action === 'restore') {
        $backupId = (int) ($_POST['backup_id'] ?? 0);
        $stmt = $db->prepare('SELECT * FROM backups WHERE id = :id');
        $stmt->execute(['id' => $backupId]);
        $backup = $stmt->fetch();

        if ($backup && file_exists($backup['file_path'])) {
            $restoreRes = $service->restoreBackup($backup['file_path']);
            if ($restoreRes['success']) {
                $success = 'Database restored successfully from ' . htmlspecialchars($backup['filename']) . '!';
            } else {
                $error = $restoreRes['message'];
            }
        } else {
            $error = 'Backup file not found on disk.';
        }
    } elseif ($action === 'upload_restore') {
        if (!empty($_FILES['backup_file']['name'])) {
            $uploadRes = $service->uploadAndRestore($_FILES['backup_file'], $currentUser['id']);
            if ($uploadRes['success']) {
                $success = $uploadRes['message'];
            } else {
                $error = $uploadRes['message'];
            }
        } else {
            $error = 'Please select a valid .sql or .sql.gz backup file to upload.';
        }
    } elseif ($action === 'delete') {
        $backupId = (int) ($_POST['backup_id'] ?? 0);
        if ($service->deleteBackup($backupId)) {
            $success = 'Backup deleted successfully.';
        } else {
            $error = 'Could not delete backup.';
        }
    }
}

$backups = $service->getBackupList();
$totalSize = array_sum(array_column($backups, 'file_size'));

// Web-cron URL helper
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$webCronUrl = "{$protocol}://{$host}" . baseUrl() . '/cron/auto_backup.php?token=' . urlencode($backupSettings['cron_token'] ?? '');

require_once __DIR__ . '/../includes/header.php';

// Re-fetch backup settings so header.php ($settings = getSchoolSettings()) doesn't clobber backup settings
$backupSettings = $service->getSettings();
?>

<div class="page-header d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <p class="text-primary fw-semibold mb-1">System Administration</p>
        <h2 class="fw-bold mb-0">Database &amp; Cloud Backups</h2>
        <small class="text-muted">Automated institutional data protection, encryption &amp; cloud synchronization</small>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <button type="button" class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#uploadModal">
            <i class="fa-solid fa-file-arrow-up me-2"></i>Upload &amp; Restore Offline File
        </button>
        <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#settingsModal">
            <i class="fa-solid fa-sliders me-2"></i>Configure Schedule &amp; Storage
        </button>
        <form method="POST" class="d-inline">
            <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
            <input type="hidden" name="action" value="backup_now">
            <button type="submit" class="btn btn-primary shadow-sm">
                <i class="fa-solid fa-bolt me-2"></i>Backup Now
            </button>
        </form>
    </div>
</div>

<?php if ($success): ?>
    <div class="alert alert-success d-flex align-items-center gap-2 mb-4" role="alert">
        <i class="fa-solid fa-circle-check fs-5"></i>
        <div><?php echo e($success); ?></div>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger d-flex align-items-center gap-2 mb-4" role="alert">
        <i class="fa-solid fa-circle-exclamation fs-5"></i>
        <div><?php echo e($error); ?></div>
    </div>
<?php endif; ?>

<!-- Quick Stats Row -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 h-100 border-0 shadow-sm">
            <div class="d-flex align-items-center gap-3">
                <div class="bg-primary bg-opacity-10 text-primary p-3 rounded-3 fs-4">
                    <i class="fa-solid fa-database"></i>
                </div>
                <div>
                    <span class="text-muted small d-block">Total Backups</span>
                    <h5 class="fw-bold mb-0"><?php echo count($backups); ?></h5>
                    <small class="text-muted"><?php echo CampusBackupService::formatBytes($totalSize); ?> used</small>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 h-100 border-0 shadow-sm">
            <div class="d-flex align-items-center gap-3">
                <div class="bg-success bg-opacity-10 text-success p-3 rounded-3 fs-4">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                </div>
                <div>
                    <span class="text-muted small d-block">Last Backup</span>
                    <h5 class="fw-bold mb-0">
                        <?php echo !empty($backupSettings['last_backup_at']) ? date('M d, H:i', strtotime($backupSettings['last_backup_at'])) : 'Never'; ?>
                    </h5>
                    <small class="text-success fw-semibold">
                        <?php echo e($backupSettings['last_backup_status'] ?? 'Ready'); ?>
                    </small>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 h-100 border-0 shadow-sm">
            <div class="d-flex align-items-center gap-3">
                <div class="bg-info bg-opacity-10 text-info p-3 rounded-3 fs-4">
                    <i class="fa-solid fa-server"></i>
                </div>
                <div>
                    <span class="text-muted small d-block">Storage Mode</span>
                    <h5 class="fw-bold mb-0">
                        <?php 
                            $cType = $backupSettings['cloud_storage_type'] ?? 'none';
                            if ($cType === 'cloudflare_r2') echo 'Cloudflare R2';
                            elseif ($cType === 'aws_s3') echo 'Amazon AWS S3';
                            elseif ($cType === 'email') echo 'Admin Email';
                            else echo 'Offline / Local';
                        ?>
                    </h5>
                    <small class="text-muted text-truncate d-block" style="max-width: 160px;" title="<?php echo !empty($backupSettings['local_secondary_path']) ? e($backupSettings['local_secondary_path']) : 'Local Disk'; ?>">
                        <?php 
                            if (!empty($backupSettings['local_secondary_path'])) {
                                echo 'Mirror: ' . e(basename($backupSettings['local_secondary_path']));
                            } elseif ($cType === 'email') {
                                echo e($backupSettings['email_backup_recipient']);
                            } elseif ($cType !== 'none') {
                                echo e($backupSettings['cloud_bucket'] ?: 'S3 Bucket');
                            } else {
                                echo 'storage/backups';
                            }
                        ?>
                    </small>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card p-3 h-100 border-0 shadow-sm">
            <div class="d-flex align-items-center gap-3">
                <div class="bg-warning bg-opacity-10 text-warning p-3 rounded-3 fs-4">
                    <i class="fa-solid fa-shield-halved"></i>
                </div>
                <div>
                    <span class="text-muted small d-block">Auto-Schedule</span>
                    <h5 class="fw-bold mb-0 text-success">
                        <?php 
                            if (!empty($backupSettings['auto_backup_enabled'])) {
                                echo '<i class="fa-solid fa-circle-check text-success me-1 fs-6"></i>' . ucfirst($backupSettings['frequency'] ?? 'daily');
                            } else {
                                echo '<span class="text-danger"><i class="fa-solid fa-circle-xmark me-1 fs-6"></i>Disabled</span>';
                            }
                        ?>
                    </h5>
                    <small class="text-muted">Retention: <?php echo (int) ($backupSettings['retention_days'] ?? 14); ?> days</small>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Backups History Table -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h5 class="fw-bold mb-0">
            <i class="fa-solid fa-clock-rotate-left me-2 text-primary"></i>Backup Archive
        </h5>
        <span class="badge bg-light text-dark border"><?php echo count($backups); ?> Archives Available</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>File Name</th>
                    <th>Size</th>
                    <th>Date &amp; Time</th>
                    <th>Cloud Status</th>
                    <th>Created By</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($backups)): ?>
                    <tr>
                        <td colspan="6" class="text-center py-5">
                            <div class="text-muted">
                                <i class="fa-solid fa-box-archive fs-1 mb-3 d-block text-secondary opacity-50"></i>
                                <h6 class="fw-bold">No Backups Generated Yet</h6>
                                <p class="small">Click the <strong>Backup Now</strong> button above to create your first secure database archive.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($backups as $b): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <i class="fa-solid fa-file-zipper text-primary fs-5"></i>
                                    <div>
                                        <div class="fw-bold text-dark font-monospace small"><?php echo e($b['filename']); ?></div>
                                        <small class="text-muted"><?php echo e($b['backup_type'] === 'database' ? 'Full Database SQL' : 'Complete System'); ?></small>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge bg-light text-secondary border">
                                    <?php echo CampusBackupService::formatBytes((int) $b['file_size']); ?>
                                </span>
                            </td>
                            <td>
                                <div><?php echo date('M d, Y', strtotime($b['created_at'])); ?></div>
                                <small class="text-muted"><?php echo date('H:i:s', strtotime($b['created_at'])); ?></small>
                            </td>
                            <td>
                                <?php if ($b['status'] === 'synced_to_cloud'): ?>
                                    <span class="badge bg-success-subtle text-success border border-success border-opacity-25 px-2 py-1">
                                        <i class="fa-solid fa-cloud-arrow-up me-1"></i><?php echo strtoupper($b['cloud_provider'] ?: 'Cloud'); ?> Synced
                                    </span>
                                <?php elseif ($b['status'] === 'local_only'): ?>
                                    <span class="badge bg-secondary-subtle text-secondary border border-secondary border-opacity-25 px-2 py-1">
                                        <i class="fa-solid fa-hard-drive me-1"></i>Offline / Local
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-warning-subtle text-warning border border-warning border-opacity-25 px-2 py-1">
                                        <?php echo e(ucfirst($b['status'])); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <small class="text-muted"><?php echo e($b['author_name'] ?? 'Automated In-App Cron'); ?></small>
                            </td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    <!-- Download -->
                                    <a href="?download=<?php echo (int) $b['id']; ?>" class="btn btn-outline-secondary" title="Download to PC">
                                        <i class="fa-solid fa-download"></i>
                                    </a>

                                    <!-- Sync to cloud -->
                                    <?php if ($b['status'] !== 'synced_to_cloud' && ($backupSettings['cloud_storage_type'] ?? 'none') !== 'none'): ?>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
                                            <input type="hidden" name="action" value="sync_cloud">
                                            <input type="hidden" name="backup_id" value="<?php echo (int) $b['id']; ?>">
                                            <button type="submit" class="btn btn-outline-info" title="Upload to Cloud">
                                                <i class="fa-solid fa-cloud-arrow-up"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <!-- Restore -->
                                    <form method="POST" class="d-inline" onsubmit="return confirm('WARNING: Restoring this backup will replace current database records with this snapshot! Proceed?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
                                        <input type="hidden" name="action" value="restore">
                                        <input type="hidden" name="backup_id" value="<?php echo (int) $b['id']; ?>">
                                        <button type="submit" class="btn btn-outline-warning" title="Restore this snapshot">
                                            <i class="fa-solid fa-rotate-left"></i>
                                        </button>
                                    </form>

                                    <!-- Delete -->
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Permanently delete this backup file?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="backup_id" value="<?php echo (int) $b['id']; ?>">
                                        <button type="submit" class="btn btn-outline-danger" title="Delete Archive">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Configuration Modal -->
<div class="modal fade" id="settingsModal" tabindex="-1" aria-labelledby="settingsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="settingsModalLabel">
                    <i class="fa-solid fa-gears me-2 text-primary"></i>Auto-Backup &amp; Cloud Settings
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
                <input type="hidden" name="action" value="save_settings">

                <div class="modal-body p-4">
                    <!-- Schedule Settings -->
                    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3">1. Automation Schedule &amp; Local Storage</h6>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" id="autoBackupSwitch" name="auto_backup_enabled" value="1" <?php echo !empty($backupSettings['auto_backup_enabled']) ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-bold" for="autoBackupSwitch">Enable Automated Scheduled Backups</label>
                            </div>
                            <small class="text-muted d-block mt-1">When enabled, CampusIQ creates regular archives in the background.</small>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Frequency</label>
                            <select class="form-select" name="frequency">
                                <option value="daily" <?php echo ($backupSettings['frequency'] === 'daily') ? 'selected' : ''; ?>>Daily (Recommended)</option>
                                <option value="weekly" <?php echo ($backupSettings['frequency'] === 'weekly') ? 'selected' : ''; ?>>Weekly</option>
                                <option value="manual" <?php echo ($backupSettings['frequency'] === 'manual') ? 'selected' : ''; ?>>Manual Only</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Retention (Days)</label>
                            <input type="number" class="form-control" name="retention_days" min="1" max="365" value="<?php echo (int) ($backupSettings['retention_days'] ?? 14); ?>">
                            <small class="text-muted">Auto-delete older than X days</small>
                        </div>
                        <div class="col-12 mt-2">
                            <label class="form-label fw-semibold"><i class="fa-solid fa-folder-tree me-1 text-primary"></i>Secondary Local / USB Drive Folder (Optional)</label>
                            <input type="text" class="form-control font-monospace" name="local_secondary_path" value="<?php echo e($backupSettings['local_secondary_path'] ?? ''); ?>" placeholder="e.g. D:\CampusIQ_Backups or E:\USB_Backups">
                            <small class="text-muted">Whenever a backup is taken, a duplicate copy will automatically be mirrored to this local directory or USB drive when connected.</small>
                        </div>
                    </div>

                    <!-- Cloud Provider Selection -->
                    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3 mt-4">2. Storage Destination &amp; Mode</h6>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Choose Storage Destination</label>
                        <select class="form-select" name="cloud_storage_type" id="cloudProviderSelect">
                            <option value="none" <?php echo ($backupSettings['cloud_storage_type'] === 'none') ? 'selected' : ''; ?>>💻 Local Storage Only (100% Offline / Zero Internet Required)</option>
                            <option value="cloudflare_r2" <?php echo ($backupSettings['cloud_storage_type'] === 'cloudflare_r2') ? 'selected' : ''; ?>>☁️ Cloudflare R2 (10 GB 100% Free - S3 Compatible)</option>
                            <option value="aws_s3" <?php echo ($backupSettings['cloud_storage_type'] === 'aws_s3') ? 'selected' : ''; ?>>🪣 Amazon AWS S3 Bucket</option>
                            <option value="email" <?php echo ($backupSettings['cloud_storage_type'] === 'email') ? 'selected' : ''; ?>>✉️ Administrator Email (Instant Gmail Dispatch)</option>
                        </select>
                    </div>

                    <!-- Offline Info Section -->
                    <div id="offlineInfoBox" class="p-3 bg-light border rounded-3 mb-3 <?php echo ($backupSettings['cloud_storage_type'] !== 'none') ? 'd-none' : ''; ?>">
                        <div class="d-flex align-items-center gap-2 text-success fw-bold mb-1">
                            <i class="fa-solid fa-shield-halved"></i> Offline Air-Gapped Mode Active
                        </div>
                        <small class="text-muted">Backups are stored entirely on this physical machine in <code>storage/backups/</code> (plus your secondary local/USB drive if configured). Zero internet connection is required.</small>
                    </div>

                    <!-- Email Setting Section -->
                    <div id="emailSettingsBox" class="p-3 bg-light rounded-3 mb-3 <?php echo ($backupSettings['cloud_storage_type'] !== 'email') ? 'd-none' : ''; ?>">
                        <label class="form-label fw-semibold">Backup Recipient Email</label>
                        <input type="email" class="form-control" name="email_backup_recipient" value="<?php echo e($backupSettings['email_backup_recipient'] ?? 'swanzyak47@gmail.com'); ?>" placeholder="admin@campusiq.edu.gh">
                        <small class="text-muted d-block mt-1">CampusIQ will automatically email the compressed <code>.sql.gz</code> archive to this inbox upon generation.</small>
                    </div>

                    <!-- S3 / Cloudflare R2 Section -->
                    <div id="s3SettingsBox" class="p-3 bg-light rounded-3 mb-3 <?php echo (!in_array($backupSettings['cloud_storage_type'], ['cloudflare_r2', 'aws_s3'])) ? 'd-none' : ''; ?>">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Bucket Name</label>
                                <input type="text" class="form-control" name="cloud_bucket" value="<?php echo e($backupSettings['cloud_bucket'] ?? ''); ?>" placeholder="campusiq-backups">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Endpoint / Account URL</label>
                                <input type="text" class="form-control" name="cloud_endpoint" value="<?php echo e($backupSettings['cloud_endpoint'] ?? ''); ?>" placeholder="https://<account_id>.r2.cloudflarestorage.com">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Access Key ID</label>
                                <input type="text" class="form-control" name="cloud_access_key" value="<?php echo e($backupSettings['cloud_access_key'] ?? ''); ?>" placeholder="Access Key">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Secret Access Key</label>
                                <input type="password" class="form-control" name="cloud_secret_key" value="<?php echo e($backupSettings['cloud_secret_key'] ?? ''); ?>" placeholder="Secret Key">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Region</label>
                                <input type="text" class="form-control" name="cloud_region" value="<?php echo e($backupSettings['cloud_region'] ?? 'auto'); ?>" placeholder="auto or us-east-1">
                            </div>
                        </div>
                    </div>

                    <!-- 3. Setup Automation Guide -->
                    <h6 class="fw-bold text-dark border-bottom pb-2 mb-3 mt-4">3. Offline &amp; Automation Setup Guide</h6>
                    <div class="bg-dark text-light p-3 rounded-3 small font-monospace">
                        <span class="text-info fw-bold d-block mb-1"># Option A: In-App Heartbeat (Zero Configuration):</span>
                        <div class="text-white-50 mb-2">CampusIQ automatically executes the daily scheduled backup in the background the first time an administrator accesses the system each day.</div>

                        <span class="text-success fw-bold d-block mb-1"># Option B: Windows Task Scheduler (Windows Server / Offline PC):</span>
                        <div class="user-select-all text-break mb-1">C:\xampp\php\php.exe C:\xampp\htdocs\campusIQ\cron\auto_backup.php</div>
                        <div class="text-muted mb-2">Or double-click: <code>scripts\register_windows_task.bat</code></div>

                        <span class="text-warning fw-bold d-block mb-1"># Option C: Web-Cron (For Live / Free Hosting):</span>
                        <div class="user-select-all text-break"><?php echo e($webCronUrl); ?></div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Settings</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Upload & Restore Modal -->
<div class="modal fade" id="uploadModal" tabindex="-1" aria-labelledby="uploadModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="uploadModalLabel">
                    <i class="fa-solid fa-file-arrow-up me-2 text-success"></i>Upload &amp; Restore Offline Backup
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
                <input type="hidden" name="action" value="upload_restore">
                <div class="modal-body p-4">
                    <div class="alert alert-warning d-flex align-items-center gap-2 small mb-3">
                        <i class="fa-solid fa-triangle-exclamation fs-5 flex-shrink-0"></i>
                        <div><strong>Caution:</strong> Restoring an offline backup snapshot will replace existing database records with the contents of your uploaded backup file.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold">Select Backup File (.sql or .sql.gz)</label>
                        <input type="file" class="form-control" name="backup_file" accept=".sql,.gz,.sql.gz" required>
                        <small class="text-muted d-block mt-1">Select an offline database backup file saved on your computer or USB drive.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger" onclick="return confirm('CRITICAL: Are you sure you want to restore the database from this uploaded backup file?');">
                        <i class="fa-solid fa-rotate-left me-1"></i>Upload &amp; Restore Now
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const providerSelect = document.getElementById('cloudProviderSelect');
    const emailBox = document.getElementById('emailSettingsBox');
    const s3Box = document.getElementById('s3SettingsBox');
    const offlineBox = document.getElementById('offlineInfoBox');

    if (providerSelect) {
        providerSelect.addEventListener('change', function () {
            const val = this.value;
            if (val === 'email') {
                emailBox?.classList.remove('d-none');
                s3Box?.classList.add('d-none');
                offlineBox?.classList.add('d-none');
            } else if (val === 'cloudflare_r2' || val === 'aws_s3') {
                emailBox?.classList.add('d-none');
                s3Box?.classList.remove('d-none');
                offlineBox?.classList.add('d-none');
            } else {
                emailBox?.classList.add('d-none');
                s3Box?.classList.add('d-none');
                offlineBox?.classList.remove('d-none');
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
