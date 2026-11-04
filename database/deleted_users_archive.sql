-- =========================================================================
-- CampusIQ: Secondary Archive Database & Deleted Users Archive Table
-- Assignment Demonstration Feature (Safe, Modular, and Reversible)
-- =========================================================================

-- 1. Create table in the main database
CREATE TABLE IF NOT EXISTS deleted_users_archive (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Optional: If secondary database campusiq_archive is supported on the server
-- CREATE DATABASE IF NOT EXISTS campusiq_archive;
-- CREATE TABLE IF NOT EXISTS campusiq_archive.deleted_users LIKE deleted_users_archive;
