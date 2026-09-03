-- ==========================================================
-- Highway Tires Management System - Account Security Migration
-- Adds temporary password flags, password change timestamps,
-- and login rate-limiting table.
-- ==========================================================

USE hwtires;

-- 1. Safely add must_change_password column if not exists
SET @col_must_change = (
    SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'must_change_password'
);

SET @sql_must_change = IF(
    @col_must_change = 0,
    'ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER status',
    'SELECT "Column users.must_change_password already exists"'
);

PREPARE stmt1 FROM @sql_must_change;
EXECUTE stmt1;
DEALLOCATE PREPARE stmt1;

-- 2. Safely add password_changed_at column if not exists
SET @col_pwd_changed = (
    SELECT COUNT(*) FROM information_schema.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'password_changed_at'
);

SET @sql_pwd_changed = IF(
    @col_pwd_changed = 0,
    'ALTER TABLE users ADD COLUMN password_changed_at TIMESTAMP NULL DEFAULT NULL AFTER must_change_password',
    'SELECT "Column users.password_changed_at already exists"'
);

PREPARE stmt2 FROM @sql_pwd_changed;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- 3. Ensure all existing users have must_change_password = 0
UPDATE users SET must_change_password = 0 WHERE must_change_password IS NULL;

-- 4. Safely create login_attempts table if not exists
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    email VARCHAR(100) NOT NULL,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_lockout (email, ip_address, attempted_at),
    INDEX idx_attempted_at (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
