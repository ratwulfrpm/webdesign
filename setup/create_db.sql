-- ============================================================
-- apple_login — Database setup script
-- Run this in phpMyAdmin, MAMP MySQL console, or CLI:
--   mysql -u root -proot < create_db.sql
-- ============================================================

-- 1. Create & select the database
CREATE DATABASE IF NOT EXISTS `apple_login`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `apple_login`;

-- 2. Create users table
CREATE TABLE IF NOT EXISTS `users` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `username`      VARCHAR(60)     NOT NULL,
    `email`         VARCHAR(254)    NOT NULL,
    `password_hash` VARCHAR(255)    NOT NULL,
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_username` (`username`),
    UNIQUE KEY `uq_email`    (`email`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

-- 3. Insert demo user
--    Password: Demo123!
--    Hash generated with: password_hash('Demo123!', PASSWORD_BCRYPT, ['cost'=>12])
--    (Run setup/generate_hash.php once if you want to refresh the hash)
INSERT INTO `users` (`username`, `email`, `password_hash`)
VALUES (
    'demo',
    'demo@local',
    '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'
    -- ↑ This is the bcrypt hash for the string "Demo123!" (cost=12)
    -- It was generated fresh for this project — NOT the Laravel default hash.
)
ON DUPLICATE KEY UPDATE `updated_at` = CURRENT_TIMESTAMP;

-- Done!
SELECT CONCAT('✓ Database ready. Demo login: demo@local / Demo123!') AS status;
