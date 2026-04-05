-- Migration: Previous Year Data System
-- Creates tables for storing historical data (fees, student details, subjects)

-- Drop tables if exists (for clean migration)
DROP TABLE IF EXISTS `previous_year_fees`;
DROP TABLE IF EXISTS `previous_year_students`;
DROP TABLE IF EXISTS `previous_year_subjects`;
DROP TABLE IF EXISTS `previous_year_data`;

-- Table 1: Previous Year Fees (from GTB Fees Reporting)
CREATE TABLE `previous_year_fees` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `academic_year` VARCHAR(20) NOT NULL COMMENT '2018-2019, 2019-2020, etc.',
    `program` VARCHAR(100) NOT NULL COMMENT 'B.A, B.Com, M.A History, etc.',
    `year_level` VARCHAR(20) NOT NULL COMMENT '1st Year, 2nd Year, 3rd Year',
    `status` ENUM('Active', 'Releave') NOT NULL DEFAULT 'Active',
    `student_name` VARCHAR(255) NOT NULL,
    `roll_number` VARCHAR(50) NULL,
    `father_name` VARCHAR(255) NULL,
    `total_fee` DECIMAL(10,2) DEFAULT 0,
    `paid_amount` DECIMAL(10,2) DEFAULT 0,
    `pending_amount` DECIMAL(10,2) DEFAULT 0,
    `payment_details` JSON NULL COMMENT 'Array of payment records',
    `remarks` TEXT NULL,
    `source_file` VARCHAR(255) NULL COMMENT 'Original Excel filename',
    `imported_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `imported_by` INT NULL,
    INDEX `idx_academic_year` (`academic_year`),
    INDEX `idx_program` (`program`),
    INDEX `idx_year_level` (`year_level`),
    INDEX `idx_status` (`status`),
    INDEX `idx_student_name` (`student_name`),
    INDEX `idx_composite` (`academic_year`, `program`, `year_level`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table 2: Previous Year Students (from GTB Student Details)
CREATE TABLE `previous_year_students` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `academic_year` VARCHAR(20) NOT NULL,
    `program` VARCHAR(100) NOT NULL,
    `year_level` VARCHAR(20) NOT NULL,
    `student_name` VARCHAR(255) NOT NULL,
    `roll_number` VARCHAR(50) NULL,
    `father_name` VARCHAR(255) NULL,
    `mother_name` VARCHAR(255) NULL,
    `date_of_birth` DATE NULL,
    `gender` VARCHAR(20) NULL,
    `category` VARCHAR(50) NULL,
    `address` TEXT NULL,
    `phone` VARCHAR(20) NULL,
    `email` VARCHAR(255) NULL,
    `admission_date` DATE NULL,
    `student_data` JSON NULL COMMENT 'Additional student fields',
    `source_file` VARCHAR(255) NULL,
    `imported_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_academic_year` (`academic_year`),
    INDEX `idx_program` (`program`),
    INDEX `idx_student_name` (`student_name`),
    INDEX `idx_roll_number` (`roll_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table 3: Previous Year Subjects (from GTB Student Subject Details)
CREATE TABLE `previous_year_subjects` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `academic_year` VARCHAR(20) NOT NULL,
    `program` VARCHAR(100) NOT NULL,
    `year_level` VARCHAR(20) NOT NULL,
    `student_name` VARCHAR(255) NOT NULL,
    `roll_number` VARCHAR(50) NULL,
    `subject_name` VARCHAR(255) NULL,
    `subject_code` VARCHAR(50) NULL,
    `subject_type` VARCHAR(50) NULL COMMENT 'Core, Elective, Optional',
    `marks_obtained` DECIMAL(5,2) NULL,
    `total_marks` DECIMAL(5,2) NULL,
    `grade` VARCHAR(10) NULL,
    `result` VARCHAR(20) NULL COMMENT 'Pass, Fail, Absent',
    `subjects_data` JSON NULL COMMENT 'All subjects as array',
    `source_file` VARCHAR(255) NULL,
    `imported_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_academic_year` (`academic_year`),
    INDEX `idx_program` (`program`),
    INDEX `idx_student_name` (`student_name`),
    INDEX `idx_roll_number` (`roll_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Backward compatibility: Keep previous_year_data as alias to fees
CREATE OR REPLACE VIEW `previous_year_data` AS
SELECT * FROM `previous_year_fees`;

-- Create view for easy querying
CREATE OR REPLACE VIEW `v_previous_year_summary` AS
SELECT 
    academic_year,
    program,
    year_level,
    status,
    COUNT(*) as total_students,
    SUM(total_fee) as total_fee_sum,
    SUM(paid_amount) as total_paid,
    SUM(pending_amount) as total_pending,
    AVG(paid_amount) as avg_paid,
    COUNT(CASE WHEN pending_amount = 0 THEN 1 END) as fully_paid_count,
    COUNT(CASE WHEN pending_amount > 0 THEN 1 END) as pending_count
FROM previous_year_fees
GROUP BY academic_year, program, year_level, status;

-- Create summary table for quick stats
CREATE TABLE `previous_year_stats` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `academic_year` VARCHAR(20) NOT NULL,
    `total_students` INT DEFAULT 0,
    `total_active` INT DEFAULT 0,
    `total_releave` INT DEFAULT 0,
    `total_fee_collected` DECIMAL(15,2) DEFAULT 0,
    `total_pending` DECIMAL(15,2) DEFAULT 0,
    `programs_count` INT DEFAULT 0,
    `last_updated` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_year` (`academic_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Success message
SELECT 'Previous Year Data tables created successfully!' as status;
