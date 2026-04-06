-- ============================================================
-- Program Management Enhancement Migration
-- ============================================================
-- This migration adds program management features including:
-- 1. Program settings table for registration window control
-- 2. Enhanced programs table with code and description
-- 3. Contact information for queries
-- ============================================================

-- Add new columns to programs table
ALTER TABLE `programs` 
ADD COLUMN `program_code` VARCHAR(10) UNIQUE AFTER `name`,
ADD COLUMN `description` TEXT AFTER `program_code`,
ADD COLUMN `is_active` BOOLEAN DEFAULT 1 AFTER `description`,
ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER `is_active`,
ADD COLUMN `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;

-- Create program_settings table
CREATE TABLE IF NOT EXISTS `program_settings` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `program_id` INT NOT NULL,
    `registration_open` BOOLEAN DEFAULT 0 COMMENT 'Whether registration is currently open',
    `registration_start` DATETIME NULL COMMENT 'When registration opens',
    `registration_end` DATETIME NULL COMMENT 'When registration closes',
    `contact_email` VARCHAR(255) NULL COMMENT 'Email for queries',
    `contact_whatsapp` VARCHAR(20) NULL COMMENT 'WhatsApp number for queries',
    `query_message` TEXT NULL COMMENT 'Custom message shown when registration is closed',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`program_id`) REFERENCES `programs`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_program_settings` (`program_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create index for faster queries
CREATE INDEX `idx_program_settings_registration` ON `program_settings`(`registration_open`, `registration_start`, `registration_end`);

-- Insert default settings for existing programs
INSERT INTO `program_settings` (`program_id`, `registration_open`, `query_message`)
SELECT 
    `id`, 
    1, -- Default to open for existing programs
    'For admission queries, please contact the administration office.'
FROM `programs`
WHERE NOT EXISTS (
    SELECT 1 FROM `program_settings` WHERE `program_settings`.`program_id` = `programs`.`id`
);

-- ============================================================
-- Rollback Instructions (if needed)
-- ============================================================
-- DROP TABLE IF EXISTS `program_settings`;
-- ALTER TABLE `programs` 
--   DROP COLUMN `program_code`,
--   DROP COLUMN `description`,
--   DROP COLUMN `is_active`,
--   DROP COLUMN `created_at`,
--   DROP COLUMN `updated_at`;
-- ============================================================
