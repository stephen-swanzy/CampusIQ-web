USE campusiq;

ALTER TABLE users
    ADD COLUMN totp_secret TEXT DEFAULT NULL AFTER password_hash,
    ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER totp_secret,
    ADD COLUMN totp_failed_attempts INT UNSIGNED NOT NULL DEFAULT 0 AFTER totp_enabled,
    ADD COLUMN totp_locked_until DATETIME DEFAULT NULL AFTER totp_failed_attempts;