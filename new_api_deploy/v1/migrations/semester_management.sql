-- ============================================================
-- SEMESTER MANAGEMENT SYSTEM - Database Migration
-- Run this SQL in phpMyAdmin or MySQL CLI
-- ============================================================

-- 1. Academic Calendar - Central semester management
CREATE TABLE IF NOT EXISTS `academic_calendar` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `program_id` INT NOT NULL,
    `academic_year` VARCHAR(9) NOT NULL COMMENT 'Format: 2024-2025',
    `semester_number` INT NOT NULL COMMENT '1-8 typically',
    `semester_name` VARCHAR(50) DEFAULT NULL COMMENT 'e.g., Fall 2024, Spring 2025',
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `registration_start` DATE DEFAULT NULL,
    `registration_end` DATE DEFAULT NULL,
    `exam_start` DATE DEFAULT NULL,
    `exam_end` DATE DEFAULT NULL,
    `status` ENUM('upcoming', 'active', 'frozen', 'completed') DEFAULT 'upcoming',
    `is_current` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`program_id`) REFERENCES `programs`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_program_semester` (`program_id`, `academic_year`, `semester_number`),
    INDEX `idx_status` (`status`),
    INDEX `idx_current` (`is_current`),
    INDEX `idx_academic_year` (`academic_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 2. Semester Transitions - Audit trail for student promotions
CREATE TABLE IF NOT EXISTS `semester_transitions` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `student_id` INT NOT NULL,
    `from_semester` INT NOT NULL,
    `to_semester` INT NOT NULL,
    `from_year` INT NOT NULL,
    `to_year` INT NOT NULL,
    `transition_type` ENUM('promotion', 'demotion', 'repeat', 'skip', 'manual') DEFAULT 'promotion',
    `transition_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `promoted_by` INT DEFAULT NULL COMMENT 'admin_id who initiated',
    `reason` TEXT DEFAULT NULL,
    `academic_calendar_id` INT DEFAULT NULL,
    FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`academic_calendar_id`) REFERENCES `academic_calendar`(`id`) ON DELETE SET NULL,
    INDEX `idx_student` (`student_id`),
    INDEX `idx_transition_date` (`transition_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3. Semester Settings - Global and per-program configuration
CREATE TABLE IF NOT EXISTS `semester_settings` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `program_id` INT DEFAULT NULL COMMENT 'NULL for global settings',
    `max_semesters` INT DEFAULT 8,
    `auto_promotion` TINYINT(1) DEFAULT 0,
    `promotion_criteria` JSON DEFAULT NULL COMMENT '{"min_attendance": 75, "min_credits": 20, "clear_dues": true}',
    `freeze_during_exams` TINYINT(1) DEFAULT 1,
    `allow_manual_override` TINYINT(1) DEFAULT 1,
    `notify_on_promotion` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`program_id`) REFERENCES `programs`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_program_settings` (`program_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 4. Insert default global settings
INSERT INTO `semester_settings` (`program_id`, `max_semesters`, `auto_promotion`, `promotion_criteria`, `freeze_during_exams`, `allow_manual_override`, `notify_on_promotion`)
VALUES (NULL, 8, 0, '{"min_attendance": 75, "clear_dues": true}', 1, 1, 1)
ON DUPLICATE KEY UPDATE `updated_at` = CURRENT_TIMESTAMP;

-- 5. Add academic_year column to students table if not exists
-- This helps track which academic year a student belongs to
SET @column_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'students' 
    AND COLUMN_NAME = 'academic_year'
);

SET @sql = IF(@column_exists = 0, 
    'ALTER TABLE `students` ADD COLUMN `academic_year` VARCHAR(9) DEFAULT NULL AFTER `year`',
    'SELECT "Column academic_year already exists"'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 6. Update existing students with academic year based on their year
UPDATE `students` 
SET `academic_year` = CONCAT(`year`, '-', `year` + 1)
WHERE `academic_year` IS NULL AND `year` IS NOT NULL;

-- 7. Create a view for easy semester statistics
CREATE OR REPLACE VIEW `v_semester_stats` AS
SELECT 
    p.id AS program_id,
    p.name AS program_name,
    s.semester,
    s.academic_year,
    COUNT(s.id) AS student_count,
    ac.status AS semester_status,
    ac.start_date,
    ac.end_date,
    ac.is_current
FROM students s
JOIN programs p ON s.program = p.name
LEFT JOIN academic_calendar ac ON ac.program_id = p.id 
    AND ac.semester_number = s.semester 
    AND ac.academic_year = s.academic_year
WHERE s.final_registration_number IS NOT NULL
GROUP BY p.id, p.name, s.semester, s.academic_year, ac.status, ac.start_date, ac.end_date, ac.is_current;

-- 8. Create stored procedure for bulk semester promotion
DELIMITER $$

DROP PROCEDURE IF EXISTS `BulkPromoteStudents`$$

CREATE PROCEDURE `BulkPromoteStudents`(
    IN p_program_id INT,
    IN p_from_semester INT,
    IN p_to_semester INT,
    IN p_admin_id INT,
    IN p_reason TEXT
)
BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE v_student_id INT;
    DECLARE v_old_year INT;
    DECLARE v_new_year INT;
    DECLARE v_program_name VARCHAR(100);
    DECLARE promoted_count INT DEFAULT 0;
    
    -- Get program name
    SELECT name INTO v_program_name FROM programs WHERE id = p_program_id;
    
    -- Cursor for eligible students
    DECLARE student_cursor CURSOR FOR
        SELECT id, year FROM students 
        WHERE program = v_program_name 
        AND semester = p_from_semester
        AND final_registration_number IS NOT NULL;
    
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;
    
    START TRANSACTION;
    
    OPEN student_cursor;
    
    read_loop: LOOP
        FETCH student_cursor INTO v_student_id, v_old_year;
        IF done THEN
            LEAVE read_loop;
        END IF;
        
        -- Calculate new year (increment if moving to odd semester from even)
        SET v_new_year = CASE 
            WHEN p_to_semester > p_from_semester AND MOD(p_to_semester, 2) = 1 AND MOD(p_from_semester, 2) = 0 
            THEN v_old_year + 1 
            ELSE v_old_year 
        END;
        
        -- Update student semester
        UPDATE students 
        SET semester = p_to_semester, year = v_new_year
        WHERE id = v_student_id;
        
        -- Log the transition
        INSERT INTO semester_transitions 
            (student_id, from_semester, to_semester, from_year, to_year, transition_type, promoted_by, reason)
        VALUES 
            (v_student_id, p_from_semester, p_to_semester, v_old_year, v_new_year, 'promotion', p_admin_id, p_reason);
        
        SET promoted_count = promoted_count + 1;
    END LOOP;
    
    CLOSE student_cursor;
    
    COMMIT;
    
    SELECT promoted_count AS students_promoted;
END$$

DELIMITER ;

-- 9. Create trigger to log semester changes
DROP TRIGGER IF EXISTS `trg_log_semester_change`;

DELIMITER $$

CREATE TRIGGER `trg_log_semester_change` 
AFTER UPDATE ON `students` 
FOR EACH ROW
BEGIN
    -- Only log if semester actually changed and not already logged by bulk promotion
    IF NEW.semester != OLD.semester THEN
        -- Check if this change was already logged (within last 5 seconds)
        IF NOT EXISTS (
            SELECT 1 FROM semester_transitions 
            WHERE student_id = NEW.id 
            AND from_semester = OLD.semester 
            AND to_semester = NEW.semester
            AND transition_date > DATE_SUB(NOW(), INTERVAL 5 SECOND)
        ) THEN
            INSERT INTO semester_transitions 
                (student_id, from_semester, to_semester, from_year, to_year, transition_type, reason)
            VALUES 
                (NEW.id, OLD.semester, NEW.semester, OLD.year, NEW.year, 
                 CASE WHEN NEW.semester > OLD.semester THEN 'promotion' ELSE 'demotion' END,
                 'Automatic trigger');
        END IF;
    END IF;
END$$

DELIMITER ;

-- ============================================================
-- Migration Complete!
-- Tables created: academic_calendar, semester_transitions, semester_settings
-- View created: v_semester_stats
-- Procedure created: BulkPromoteStudents
-- Trigger updated: trg_log_semester_change
-- ============================================================
