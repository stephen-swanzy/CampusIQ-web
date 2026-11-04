<?php
/**
 * CampusIQ Security Archive & Deleted Users Manager
 * 
 * Provides secondary database archiving for deleted users,
 * preserving salted password hashes and audit metadata, with one-click restoration.
 */

require_once __DIR__ . '/../config/database.php';

class CampusSecurityArchive
{
    private PDO $db;

    public function __construct()
    {
        $this->db = getDb();
        $this->ensureTableExists();
    }

    /**
     * Ensure the deleted_users_archive table exists.
     */
    public function ensureTableExists(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS deleted_users_archive (
            id INT AUTO_INCREMENT PRIMARY KEY,
            original_user_id INT NOT NULL,
            name VARCHAR(150) NOT NULL,
            email VARCHAR(150) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            totp_secret TEXT NULL,
            totp_enabled TINYINT(1) DEFAULT 0,
            role VARCHAR(50) NOT NULL,
            status VARCHAR(50) DEFAULT 'inactive',
            phone VARCHAR(30) NULL,
            photo VARCHAR(255) NULL,
            deleted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            deleted_by VARCHAR(150) NULL,
            deletion_reason VARCHAR(255) NULL,
            original_created_at DATETIME NULL,
            restored_at DATETIME NULL,
            INDEX idx_deleted_email (email),
            INDEX idx_original_user (original_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $this->db->exec($sql);
    }

    /**
     * Archive a user into the secondary deleted users archive before deletion.
     */
    public function archiveUser(int $userId, string $deletedBy = 'Administrator', string $reason = 'Administrative deletion'): array
    {
        // 1. Fetch user from active users table
        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();

        if (!$user) {
            return ['success' => false, 'message' => "User ID {$userId} not found in active database."];
        }

        // 2. Insert into primary deleted_users_archive table
        $archiveSql = 'INSERT INTO deleted_users_archive 
            (original_user_id, name, email, password_hash, totp_secret, totp_enabled, role, status, phone, photo, deleted_at, deleted_by, deletion_reason, original_created_at)
            VALUES 
            (:orig_id, :name, :email, :pass, :totp_secret, :totp_enabled, :role, "deleted", :phone, :photo, NOW(), :deleted_by, :reason, :orig_created)';

        $params = [
            'orig_id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'pass' => $user['password_hash'],
            'totp_secret' => $user['totp_secret'] ?? null,
            'totp_enabled' => $user['totp_enabled'] ?? 0,
            'role' => $user['role'],
            'phone' => $user['phone'] ?? null,
            'photo' => $user['photo'] ?? null,
            'deleted_by' => $deletedBy,
            'reason' => $reason,
            'orig_created' => $user['created_at'] ?? null,
        ];

        $stmtArchive = $this->db->prepare($archiveSql);
        $stmtArchive->execute($params);
        $archiveId = (int) $this->db->lastInsertId();

        // 3. Mirror into secondary database campusiq_archive if present
        try {
            $this->db->exec("INSERT INTO campusiq_archive.deleted_users 
                (original_user_id, name, email, password_hash, totp_secret, totp_enabled, role, status, phone, photo, deleted_at, deleted_by, deletion_reason, original_created_at)
                VALUES 
                (" . (int)$user['id'] . ", " . $this->db->quote($user['name']) . ", " . $this->db->quote($user['email']) . ", " . $this->db->quote($user['password_hash']) . ", " . ($user['totp_secret'] ? $this->db->quote($user['totp_secret']) : "NULL") . ", " . (int)($user['totp_enabled'] ?? 0) . ", " . $this->db->quote($user['role']) . ", 'deleted', " . ($user['phone'] ? $this->db->quote($user['phone']) : "NULL") . ", " . ($user['photo'] ? $this->db->quote($user['photo']) : "NULL") . ", NOW(), " . $this->db->quote($deletedBy) . ", " . $this->db->quote($reason) . ", " . ($user['created_at'] ? $this->db->quote($user['created_at']) : "NULL") . ")");
        } catch (Exception $eSec) {
            // Secondary standalone database optional on some hosts
        }

        // 4. Remove or deactivate from active users table
        // Note: For students/teachers with child relations, we set status to 'inactive' or remove if safe
        $delStmt = $this->db->prepare('DELETE FROM users WHERE id = :id');
        try {
            $delStmt->execute(['id' => $userId]);
        } catch (Exception $eRel) {
            // If foreign keys prevent hard delete, mark status as inactive
            $this->db->prepare('UPDATE users SET status = "inactive" WHERE id = :id')->execute(['id' => $userId]);
        }

        return [
            'success' => true,
            'archive_id' => $archiveId,
            'message' => "User '{$user['name']}' ({$user['email']}) archived successfully with full password hash preserved."
        ];
    }

    /**
     * Restore an archived user back to the active users table.
     */
    public function restoreUser(int $archiveId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM deleted_users_archive WHERE id = :id');
        $stmt->execute(['id' => $archiveId]);
        $archived = $stmt->fetch();

        if (!$archived) {
            return ['success' => false, 'message' => "Archive record #{$archiveId} not found."];
        }

        // Check if email already exists in users
        $checkStmt = $this->db->prepare('SELECT id FROM users WHERE email = :email');
        $checkStmt->execute(['email' => $archived['email']]);
        $existing = $checkStmt->fetch();

        if ($existing) {
            // Re-activate existing user and restore password hash
            $update = $this->db->prepare('UPDATE users SET 
                name = :name, 
                password_hash = :hash, 
                role = :role, 
                status = "active", 
                phone = :phone, 
                totp_secret = :totp, 
                totp_enabled = :totp_en 
                WHERE id = :id');
            $update->execute([
                'name' => $archived['name'],
                'hash' => $archived['password_hash'],
                'role' => $archived['role'],
                'phone' => $archived['phone'],
                'totp' => $archived['totp_secret'],
                'totp_en' => $archived['totp_enabled'],
                'id' => $existing['id']
            ]);
            $restoredUserId = $existing['id'];
        } else {
            // Insert back with original or new ID
            $insert = $this->db->prepare('INSERT INTO users 
                (id, name, email, password_hash, role, status, phone, totp_secret, totp_enabled, created_at)
                VALUES 
                (:orig_id, :name, :email, :hash, :role, "active", :phone, :totp, :totp_en, COALESCE(:orig_created, NOW()))');
            try {
                $insert->execute([
                    'orig_id' => $archived['original_user_id'],
                    'name' => $archived['name'],
                    'email' => $archived['email'],
                    'hash' => $archived['password_hash'],
                    'role' => $archived['role'],
                    'phone' => $archived['phone'],
                    'totp' => $archived['totp_secret'],
                    'totp_en' => $archived['totp_enabled'],
                    'orig_created' => $archived['original_created_at']
                ]);
                $restoredUserId = $archived['original_user_id'];
            } catch (Exception $eId) {
                // If original ID collides, insert with auto ID
                $insertAuto = $this->db->prepare('INSERT INTO users 
                    (name, email, password_hash, role, status, phone, totp_secret, totp_enabled)
                    VALUES 
                    (:name, :email, :hash, :role, "active", :phone, :totp, :totp_en)');
                $insertAuto->execute([
                    'name' => $archived['name'],
                    'email' => $archived['email'],
                    'hash' => $archived['password_hash'],
                    'role' => $archived['role'],
                    'phone' => $archived['phone'],
                    'totp' => $archived['totp_secret'],
                    'totp_en' => $archived['totp_enabled']
                ]);
                $restoredUserId = (int) $this->db->lastInsertId();
            }
        }

        // Mark as restored in archive
        $this->db->prepare('UPDATE deleted_users_archive SET restored_at = NOW() WHERE id = :id')->execute(['id' => $archiveId]);

        return [
            'success' => true,
            'user_id' => $restoredUserId,
            'message' => "User '{$archived['name']}' ({$archived['email']}) restored to active users table successfully!"
        ];
    }

    /**
     * Get all archived deleted users.
     */
    public function getArchivedUsers(): array
    {
        $stmt = $this->db->query('SELECT * FROM deleted_users_archive ORDER BY deleted_at DESC');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Permanently purge an archived user record.
     */
    public function purgeUser(int $archiveId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM deleted_users_archive WHERE id = :id');
        return $stmt->execute(['id' => $archiveId]);
    }
}
