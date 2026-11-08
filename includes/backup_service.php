<?php

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/mailer.php';

class CampusBackupService
{
    private PDO $db;
    private string $storageDir;

    public function __construct()
    {
        $this->db = getDb();
        // Vercel serverless has a read-only filesystem except /tmp
        $isServerless = is_dir('/tmp') && is_writable('/tmp') && !empty($_ENV['VERCEL']);
        if ($isServerless || !is_writable(dirname(__DIR__) . '/storage')) {
            $this->storageDir = '/tmp/backups';
        } else {
            $this->storageDir = dirname(__DIR__) . '/storage/backups';
        }
        $this->ensureStorageDir();
    }

    /**
     * Ensure storage directory exists and is secured against direct HTTP downloads
     */
    private function ensureStorageDir(): void
    {
        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0755, true);
        }

        $htaccess = $this->storageDir . '/.htaccess';
        if (!file_exists($htaccess)) {
            $content = "# Prevent direct access to backups\n"
                     . "<IfModule authz_core_module>\n"
                     . "    Require all denied\n"
                     . "</IfModule>\n"
                     . "<IfModule !authz_core_module>\n"
                     . "    Deny from all\n"
                     . "</IfModule>\n";
            @file_put_contents($htaccess, $content);
        }
    }

    public function getSettings(): array
    {
        $stmt = $this->db->query('SELECT * FROM backup_settings ORDER BY id DESC LIMIT 1');
        $settings = $stmt->fetch();
        if (!$settings) {
            $this->db->exec("INSERT INTO backup_settings (auto_backup_enabled, frequency, retention_days, cloud_storage_type, email_backup_recipient) VALUES (1, 'daily', 14, 'email', 'swanzyak47@gmail.com')");
            return $this->getSettings();
        }
        return $settings;
    }

    public function updateSettings(array $data): bool
    {
        $settings = $this->getSettings();
        $stmt = $this->db->prepare('UPDATE backup_settings SET 
            auto_backup_enabled = :auto_backup_enabled,
            frequency = :frequency,
            retention_days = :retention_days,
            local_secondary_path = :local_secondary_path,
            cloud_storage_type = :cloud_storage_type,
            cloud_endpoint = :cloud_endpoint,
            cloud_bucket = :cloud_bucket,
            cloud_access_key = :cloud_access_key,
            cloud_secret_key = :cloud_secret_key,
            cloud_region = :cloud_region,
            email_backup_recipient = :email_backup_recipient
            WHERE id = :id');

        return $stmt->execute([
            'auto_backup_enabled' => !empty($data['auto_backup_enabled']) ? 1 : 0,
            'frequency' => in_array($data['frequency'] ?? '', ['daily', 'weekly', 'manual']) ? $data['frequency'] : 'daily',
            'retention_days' => max(1, (int) ($data['retention_days'] ?? 14)),
            'local_secondary_path' => trim($data['local_secondary_path'] ?? ''),
            'cloud_storage_type' => in_array($data['cloud_storage_type'] ?? '', ['none', 'cloudflare_r2', 'aws_s3', 'email']) ? $data['cloud_storage_type'] : 'none',
            'cloud_endpoint' => trim($data['cloud_endpoint'] ?? ''),
            'cloud_bucket' => trim($data['cloud_bucket'] ?? ''),
            'cloud_access_key' => trim($data['cloud_access_key'] ?? ''),
            'cloud_secret_key' => trim($data['cloud_secret_key'] ?? ''),
            'cloud_region' => trim($data['cloud_region'] ?? 'auto'),
            'email_backup_recipient' => trim($data['email_backup_recipient'] ?? ''),
            'id' => $settings['id'],
        ]);
    }

    /**
     * Dumps all tables into a standard SQL script
     */
    public function dumpDatabase(string $targetFile): bool
    {
        $dir = dirname($targetFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $handle = @fopen($targetFile, 'w');
        if (!$handle) {
            return false;
        }

        $school = getSchoolSettings();
        $schoolName = $school['school_name'] ?? 'CampusIQ';

        fwrite($handle, "-- =====================================================\n");
        fwrite($handle, "-- CampusIQ Automated Database Backup\n");
        fwrite($handle, "-- Institution: {$schoolName}\n");
        fwrite($handle, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
        fwrite($handle, "-- PHP Version: " . PHP_VERSION . "\n");
        fwrite($handle, "-- =====================================================\n\n");
        fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n");
        fwrite($handle, "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n");
        fwrite($handle, "START TRANSACTION;\n");
        fwrite($handle, "SET time_zone = '+00:00';\n\n");

        $tablesStmt = $this->db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        $tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tables as $table) {
            fwrite($handle, "-- -----------------------------------------------------\n");
            fwrite($handle, "-- Table structure for table `{$table}`\n");
            fwrite($handle, "-- -----------------------------------------------------\n");
            fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");

            $createStmt = $this->db->query("SHOW CREATE TABLE `{$table}`");
            $createRow = $createStmt->fetch(PDO::FETCH_ASSOC);
            if (!empty($createRow['Create Table'])) {
                fwrite($handle, $createRow['Create Table'] . ";\n\n");
            }

            // Dump data
            $countStmt = $this->db->query("SELECT COUNT(*) FROM `{$table}`");
            $totalRows = (int) $countStmt->fetchColumn();

            if ($totalRows > 0) {
                fwrite($handle, "-- Dumping data for table `{$table}`\n");

                $selectStmt = $this->db->query("SELECT * FROM `{$table}`");
                $batchSize = 100;
                $batchCount = 0;
                $cols = null;

                while ($row = $selectStmt->fetch(PDO::FETCH_ASSOC)) {
                    if ($cols === null) {
                        $cols = array_map(fn($c) => "`{$c}`", array_keys($row));
                    }

                    if ($batchCount === 0) {
                        fwrite($handle, "INSERT INTO `{$table}` (" . implode(', ', $cols) . ") VALUES\n");
                    } else {
                        fwrite($handle, ",\n");
                    }

                    $values = [];
                    foreach ($row as $val) {
                        if ($val === null) {
                            $values[] = 'NULL';
                        } else {
                            $values[] = $this->db->quote($val);
                        }
                    }

                    fwrite($handle, '(' . implode(', ', $values) . ')');
                    $batchCount++;

                    if ($batchCount >= $batchSize) {
                        fwrite($handle, ";\n\n");
                        $batchCount = 0;
                    }
                }

                if ($batchCount > 0) {
                    fwrite($handle, ";\n\n");
                }
            }
        }

        fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        fwrite($handle, "COMMIT;\n");
        fwrite($handle, "-- Dump completed on " . date('Y-m-d H:i:s') . "\n");

        fclose($handle);
        return true;
    }

    /**
     * Executes backup creation, compression, DB recording, and cloud dispatch
     */
    public function createBackup(?int $userId = null, string $backupType = 'database'): array
    {
        $timestamp = date('Y-m-d_H-i-s');
        $rawSqlFilename = "campusiq_db_{$timestamp}.sql";
        $rawSqlPath = $this->storageDir . '/' . $rawSqlFilename;

        // Step 1: Dump DB
        $dumped = $this->dumpDatabase($rawSqlPath);
        if (!$dumped || !file_exists($rawSqlPath)) {
            return [
                'success' => false,
                'message' => 'Failed to generate database dump.',
            ];
        }

        $finalFilename = $rawSqlFilename;
        $finalPath = $rawSqlPath;

        // Step 2: Compress to .sql.gz if zlib is enabled
        if (function_exists('gzopen')) {
            $gzFilename = $rawSqlFilename . '.gz';
            $gzPath = $this->storageDir . '/' . $gzFilename;
            $gz = gzopen($gzPath, 'w9');
            $raw = fopen($rawSqlPath, 'r');
            if ($gz && $raw) {
                while (!feof($raw)) {
                    gzwrite($gz, fread($raw, 65536));
                }
                fclose($raw);
                gzclose($gz);
                @unlink($rawSqlPath);
                $finalFilename = $gzFilename;
                $finalPath = $gzPath;
            }
        }

        $fileSize = filesize($finalPath);

        // Step 3: Record in DB
        $stmt = $this->db->prepare('INSERT INTO backups (filename, file_path, file_size, backup_type, status, cloud_provider, created_by) VALUES (:filename, :path, :size, :type, :status, :provider, :user_id)');
        $stmt->execute([
            'filename' => $finalFilename,
            'path' => $finalPath,
            'size' => $fileSize,
            'type' => $backupType,
            'status' => 'local_only',
            'provider' => 'none',
            'user_id' => $userId,
        ]);
        $backupId = (int) $this->db->lastInsertId();

        // Step 4: Mirror to secondary local / USB path if configured
        $settings = $this->getSettings();
        $secondaryPath = trim($settings['local_secondary_path'] ?? '');
        $mirrorStatus = '';

        if (!empty($secondaryPath)) {
            if (!is_dir($secondaryPath)) {
                @mkdir($secondaryPath, 0755, true);
            }
            if (is_dir($secondaryPath) && is_writable($secondaryPath)) {
                $secondaryTarget = rtrim($secondaryPath, '/\\') . DIRECTORY_SEPARATOR . $finalFilename;
                if (@copy($finalPath, $secondaryTarget)) {
                    $mirrorStatus = " & mirrored to {$secondaryPath}";
                    $this->pruneSecondaryFolder($secondaryPath, (int) ($settings['retention_days'] ?? 14));
                }
            }
        }

        // Step 5: Dispatch to cloud according to settings
        $cloudType = $settings['cloud_storage_type'] ?? 'none';
        $cloudResult = ['synced' => false, 'message' => 'Saved locally' . $mirrorStatus . '.'];

        if ($cloudType === 'cloudflare_r2' || $cloudType === 'aws_s3') {
            $cloudResult = $this->uploadToS3Cloud($finalPath, $finalFilename, $settings);
            if ($cloudResult['synced']) {
                $this->db->prepare('UPDATE backups SET status = "synced_to_cloud", cloud_provider = :provider, cloud_key = :key WHERE id = :id')
                    ->execute([
                        'provider' => $cloudType,
                        'key' => $finalFilename,
                        'id' => $backupId,
                    ]);
            }
        } elseif ($cloudType === 'email') {
            $recipient = !empty($settings['email_backup_recipient']) ? $settings['email_backup_recipient'] : 'swanzyak47@gmail.com';
            $cloudResult = $this->dispatchBackupEmail($finalPath, $finalFilename, $recipient);
            if ($cloudResult['synced']) {
                $this->db->prepare('UPDATE backups SET status = "synced_to_cloud", cloud_provider = "email", cloud_key = :key WHERE id = :id')
                    ->execute([
                        'key' => $recipient,
                        'id' => $backupId,
                    ]);
            }
        }

        // Update settings last backup info
        $this->db->prepare('UPDATE backup_settings SET last_backup_at = NOW(), last_backup_status = :status WHERE id = :id')
            ->execute([
                'status' => $cloudResult['synced'] ? 'Synced to ' . strtoupper($cloudType) : ('Saved Locally' . $mirrorStatus),
                'id' => $settings['id'],
            ]);

        // Step 6: Prune old backups in primary storage
        $retention = (int) ($settings['retention_days'] ?? 14);
        $this->pruneOldBackups($retention);

        return [
            'success' => true,
            'backup_id' => $backupId,
            'filename' => $finalFilename,
            'size' => $fileSize,
            'cloud_synced' => $cloudResult['synced'],
            'cloud_message' => $cloudResult['message'],
            'message' => "Backup {$finalFilename} (" . self::formatBytes($fileSize) . ") created successfully. " . $cloudResult['message'],
        ];
    }

    /**
     * Upload to S3-compatible cloud storage (Cloudflare R2, AWS S3, etc.)
     * Uses pure PHP AWS Signature Version 4
     */
    public function uploadToS3Cloud(string $filePath, string $filename, array $settings): array
    {
        $accessKey = trim($settings['cloud_access_key'] ?? '');
        $secretKey = trim($settings['cloud_secret_key'] ?? '');
        $bucket = trim($settings['cloud_bucket'] ?? '');
        $endpoint = trim($settings['cloud_endpoint'] ?? '');
        $region = trim($settings['cloud_region'] ?? 'auto');

        if (empty($accessKey) || empty($secretKey) || empty($bucket)) {
            return [
                'synced' => false,
                'message' => 'Cloud credentials missing (Bucket, Access Key or Secret Key).',
            ];
        }

        if (!file_exists($filePath)) {
            return ['synced' => false, 'message' => 'Local backup file not found.'];
        }

        $fileContent = file_get_contents($filePath);
        $contentLength = strlen($fileContent);
        $contentHash = hash('sha256', $fileContent);

        // Normalize Host and URL
        // If Cloudflare R2: endpoint is usually https://<account_id>.r2.cloudflarestorage.com
        if (!empty($endpoint)) {
            $parsed = parse_url($endpoint);
            $host = $parsed['host'] ?? $endpoint;
            $scheme = $parsed['scheme'] ?? 'https';
        } else {
            $host = "{$bucket}.s3.{$region}.amazonaws.com";
            $scheme = 'https';
        }

        $uriPath = '/' . $bucket . '/' . rawurlencode($filename);
        $fullUrl = "{$scheme}://{$host}{$uriPath}";

        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');
        $service = 's3';

        // Canonical Request
        $canonicalHeaders = "host:{$host}\n"
                          . "x-amz-content-sha256:{$contentHash}\n"
                          . "x-amz-date:{$amzDate}\n";
        $signedHeaders = "host;x-amz-content-sha256;x-amz-date";

        $canonicalRequest = "PUT\n"
                          . "{$uriPath}\n"
                          . "\n"
                          . $canonicalHeaders . "\n"
                          . $signedHeaders . "\n"
                          . $contentHash;

        // String to sign
        $credentialScope = "{$dateStamp}/{$region}/{$service}/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n"
                      . "{$amzDate}\n"
                      . "{$credentialScope}\n"
                      . hash('sha256', $canonicalRequest);

        // Calculate signature
        $kSecret = 'AWS4' . $secretKey;
        $kDate = hash_hmac('sha256', $dateStamp, $kSecret, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authorization = "AWS4-HMAC-SHA256 Credential={$accessKey}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        // Send via cURL
        $ch = curl_init($fullUrl);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fileContent);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Host: {$host}",
            "x-amz-date: {$amzDate}",
            "x-amz-content-sha256: {$contentHash}",
            "Authorization: {$authorization}",
            "Content-Length: {$contentLength}",
            "Content-Type: application/octet-stream",
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode === 200 || $httpCode === 201) {
            return [
                'synced' => true,
                'message' => 'Successfully uploaded to cloud bucket ' . htmlspecialchars($bucket) . '.',
            ];
        }

        return [
            'synced' => false,
            'message' => "Cloud upload failed (HTTP {$httpCode}): " . ($curlError ?: substr($response, 0, 200)),
        ];
    }

    /**
     * Dispatches the backup file to the administrator's email
     */
    public function dispatchBackupEmail(string $filePath, string $filename, string $recipient): array
    {
        if (empty($recipient) || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return ['synced' => false, 'message' => 'Invalid email backup recipient.'];
        }

        if (!file_exists($filePath)) {
            return ['synced' => false, 'message' => 'Backup file not found.'];
        }

        $school = getSchoolSettings();
        $schoolName = $school['school_name'] ?? 'CampusIQ';
        $sizeStr = self::formatBytes(filesize($filePath));

        $subject = "CampusIQ Automated Database Backup — " . date('Y-m-d');
        $html = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 12px;'>
            <h2 style='color: #1e3a8a; margin-top: 0;'>CampusIQ Automated Backup</h2>
            <p>Here is your daily automated database backup for <strong>" . htmlspecialchars($schoolName) . "</strong>.</p>
            <table style='width: 100%; border-collapse: collapse; margin: 20px 0;'>
                <tr><td style='padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;'>File Name:</td><td style='padding: 8px; border-bottom: 1px solid #eee;'>" . htmlspecialchars($filename) . "</td></tr>
                <tr><td style='padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;'>File Size:</td><td style='padding: 8px; border-bottom: 1px solid #eee;'>{$sizeStr}</td></tr>
                <tr><td style='padding: 8px; border-bottom: 1px solid #eee; font-weight: bold;'>Created At:</td><td style='padding: 8px; border-bottom: 1px solid #eee;'>" . date('Y-m-d H:i:s') . "</td></tr>
            </table>
            <p style='color: #64748b; font-size: 0.9em;'>The backup archive is attached to this email. Store it in a secure location.</p>
        </div>";

        $result = sendCampusEmailWithAttachment($recipient, 'Administrator', $subject, $html, $filePath, $filename);

        return [
            'synced' => $result['success'],
            'message' => $result['success'] ? "Dispatched to {$recipient} via email." : "Email dispatch failed: " . $result['error'],
        ];
    }

    /**
     * Restore database from backup file
     */
    public function restoreBackup(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return ['success' => false, 'message' => 'Backup file does not exist.'];
        }

        // Decompress if needed
        $sqlContent = '';
        if (substr($filePath, -3) === '.gz') {
            $gz = gzopen($filePath, 'r');
            if (!$gz) {
                return ['success' => false, 'message' => 'Could not read gzipped backup file.'];
            }
            while (!gzeof($gz)) {
                $sqlContent .= gzread($gz, 65536);
            }
            gzclose($gz);
        } else {
            $sqlContent = file_get_contents($filePath);
        }

        if (empty(trim($sqlContent))) {
            return ['success' => false, 'message' => 'Backup file is empty.'];
        }

        try {
            // DDL statements (DROP TABLE, CREATE TABLE) trigger implicit commits in MySQL.
            // Avoid active transaction conflicts while disabling foreign keys for bulk restore.
            $this->db->setAttribute(PDO::ATTR_EMULATE_PREPARES, 1);
            $this->db->exec("SET FOREIGN_KEY_CHECKS=0;");
            $this->db->exec($sqlContent);
            $this->db->exec("SET FOREIGN_KEY_CHECKS=1;");

            return ['success' => true, 'message' => 'Database successfully restored from backup snapshot.'];
        } catch (Exception $e) {
            try {
                $this->db->exec("SET FOREIGN_KEY_CHECKS=1;");
            } catch (Exception $ignored) {}
            return ['success' => false, 'message' => 'Restore failed: ' . $e->getMessage()];
        }
    }

    /**
     * Upload and restore an offline backup file (.sql or .sql.gz)
     */
    public function uploadAndRestore(array $uploadedFile, ?int $userId = null): array
    {
        if (empty($uploadedFile['name']) || ($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errCode = $uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE;
            return ['success' => false, 'message' => 'File upload error (Code ' . $errCode . '). Please ensure the file is within PHP upload limits.'];
        }

        $origName = basename($uploadedFile['name']);
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $isGz = (substr($origName, -7) === '.sql.gz' || $ext === 'gz');
        $isSql = ($ext === 'sql');

        if (!$isSql && !$isGz) {
            return ['success' => false, 'message' => 'Invalid file format. Please upload a valid .sql or .sql.gz backup file.'];
        }

        $timestamp = date('Y-m-d_H-i-s');
        $safeName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $origName);
        $targetFilename = "campusiq_offline_imported_{$timestamp}_{$safeName}";
        $targetPath = $this->storageDir . '/' . $targetFilename;

        if (!move_uploaded_file($uploadedFile['tmp_name'], $targetPath)) {
            return ['success' => false, 'message' => 'Failed to save uploaded backup file to storage directory.'];
        }

        $fileSize = (int) filesize($targetPath);

        // Record in backups table
        $stmt = $this->db->prepare('INSERT INTO backups (filename, file_path, file_size, backup_type, status, cloud_provider, created_by) VALUES (:filename, :path, :size, "imported", "local_only", "none", :uid)');
        $stmt->execute([
            'filename' => $targetFilename,
            'path' => $targetPath,
            'size' => $fileSize,
            'uid' => $userId,
        ]);

        // Execute restore
        $restoreRes = $this->restoreBackup($targetPath);
        if ($restoreRes['success']) {
            return [
                'success' => true,
                'message' => "Offline backup '{$origName}' (" . self::formatBytes($fileSize) . ") successfully uploaded and database restored!",
            ];
        } else {
            return [
                'success' => false,
                'message' => "File uploaded but restore failed: " . $restoreRes['message'],
            ];
        }
    }

    /**
     * Prune old backups in secondary / USB local folder
     */
    public function pruneSecondaryFolder(string $path, int $retentionDays): int
    {
        if ($retentionDays <= 0 || !is_dir($path)) {
            return 0;
        }

        $cutoff = time() - ($retentionDays * 86400);
        $files = glob(rtrim($path, '/\\') . '/campusiq_*.sql*');
        $pruned = 0;

        if ($files) {
            foreach ($files as $file) {
                if (is_file($file) && filemtime($file) < $cutoff) {
                    if (@unlink($file)) {
                        $pruned++;
                    }
                }
            }
        }
        return $pruned;
    }

    /**
     * In-app heartbeat to run scheduled backups if overdue (zero external cron required)
     */
    public function checkAndRunScheduledBackup(): void
    {
        $settings = $this->getSettings();
        if (empty($settings['auto_backup_enabled'])) {
            return;
        }

        $frequency = $settings['frequency'] ?? 'daily';
        if ($frequency === 'manual') {
            return;
        }

        $lastBackup = !empty($settings['last_backup_at']) ? strtotime($settings['last_backup_at']) : 0;
        $minSeconds = ($frequency === 'weekly') ? (6 * 86400) : (20 * 3600); // 20 hours for daily

        if ((time() - $lastBackup) >= $minSeconds) {
            // Run background creation
            $this->createBackup(null, 'database');
        }
    }

    /**
     * Delete backup from disk and database
     */
    public function deleteBackup(int $id): bool
    {
        $stmt = $this->db->prepare('SELECT * FROM backups WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $backup = $stmt->fetch();
        if (!$backup) {
            return false;
        }

        if (!empty($backup['file_path']) && file_exists($backup['file_path'])) {
            @unlink($backup['file_path']);
        }

        $this->db->prepare('DELETE FROM backups WHERE id = :id')->execute(['id' => $id]);
        return true;
    }

    /**
     * Prune local backups older than retention days
     */
    public function pruneOldBackups(int $retentionDays): int
    {
        if ($retentionDays <= 0) {
            return 0;
        }

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));
        $stmt = $this->db->prepare('SELECT * FROM backups WHERE created_at < :cutoff');
        $stmt->execute(['cutoff' => $cutoff]);
        $oldBackups = $stmt->fetchAll();

        $prunedCount = 0;
        foreach ($oldBackups as $backup) {
            if (!empty($backup['file_path']) && file_exists($backup['file_path'])) {
                @unlink($backup['file_path']);
            }
            $this->db->prepare('DELETE FROM backups WHERE id = :id')->execute(['id' => $backup['id']]);
            $prunedCount++;
        }

        return $prunedCount;
    }

    public function getBackupList(): array
    {
        $stmt = $this->db->query('SELECT b.*, u.name as author_name FROM backups b LEFT JOIN users u ON b.created_by = u.id ORDER BY b.created_at DESC');
        return $stmt->fetchAll();
    }

    public static function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
