-- ============================================================
-- FEE VERSIONING SYSTEM
-- Allows fee structure changes without affecting existing payments
-- ============================================================

-- 1. Add versioning columns to fee_settings
ALTER TABLE `fee_settings` 
ADD COLUMN IF NOT EXISTS `effective_from` DATE NOT NULL DEFAULT (CURRENT_DATE),
ADD COLUMN IF NOT EXISTS `effective_to` DATE DEFAULT NULL,
ADD COLUMN IF NOT EXISTS `is_active` BOOLEAN DEFAULT 1,
ADD COLUMN IF NOT EXISTS `version` INT DEFAULT 1,
ADD COLUMN IF NOT EXISTS `created_by` INT DEFAULT NULL,
ADD COLUMN IF NOT EXISTS `notes` TEXT DEFAULT NULL;

-- 2. Create student fee assignments table
CREATE TABLE IF NOT EXISTS `student_fee_assignments` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `student_id` INT NOT NULL,
    `fee_setting_id` INT NOT NULL,
    `program` VARCHAR(255) NOT NULL,
    `total_fee` DECIMAL(10,2) NOT NULL,
    `assigned_date` DATE NOT NULL DEFAULT (CURRENT_DATE),
    `assigned_by` INT DEFAULT NULL COMMENT 'Admin who assigned',
    `assignment_type` ENUM('auto', 'manual', 'migration') DEFAULT 'auto',
    `notes` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`student_id`) REFERENCES `students`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`fee_setting_id`) REFERENCES `fee_settings`(`id`),
    UNIQUE KEY `unique_student_program` (`student_id`, `program`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3. Create fee change history table
CREATE TABLE IF NOT EXISTS `fee_change_history` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `program` VARCHAR(255) NOT NULL,
    `old_fee` DECIMAL(10,2) NOT NULL,
    `new_fee` DECIMAL(10,2) NOT NULL,
    `old_fee_setting_id` INT DEFAULT NULL,
    `new_fee_setting_id` INT NOT NULL,
    `effective_from` DATE NOT NULL,
    `changed_by` INT DEFAULT NULL,
    `reason` TEXT DEFAULT NULL,
    `affected_students` INT DEFAULT 0 COMMENT 'Number of students affected',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`old_fee_setting_id`) REFERENCES `fee_settings`(`id`),
    FOREIGN KEY (`new_fee_setting_id`) REFERENCES `fee_settings`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 4. Migrate existing fee settings to versioned system
-- Mark all existing fee settings as version 1
UPDATE `fee_settings` 
SET `version` = 1, 
    `is_active` = 1,
    `effective_from` = COALESCE(`effective_from`, CURRENT_DATE)
WHERE `version` IS NULL OR `version` = 0;

-- 5. Migrate existing students to fee assignments
-- Assign current fee structure to all existing students
INSERT INTO `student_fee_assignments` 
    (`student_id`, `fee_setting_id`, `program`, `total_fee`, `assignment_type`, `notes`)
SELECT 
    s.id,
    fs.id,
    s.program,
    fs.total_fee,
    'migration',
    'Migrated from existing fee structure'
FROM `students` s
JOIN `fee_settings` fs ON fs.program = s.program 
    AND fs.is_active = 1
WHERE NOT EXISTS (
    SELECT 1 FROM `student_fee_assignments` sfa 
    WHERE sfa.student_id = s.id 
    AND sfa.program = s.program
)
AND s.final_registration_number IS NOT NULL;

-- 6. Create view for student fee summary with versioning
CREATE OR REPLACE VIEW `v_student_fee_summary` AS
SELECT 
    s.id AS student_id,
    s.name AS student_name,
    s.final_registration_number,
    p.id AS program_id,
    p.name AS program_name,
    s.semester,
    s.year,
    COALESCE(sfa.total_fee, fs.total_fee) AS assigned_fee,
    COALESCE(SUM(pay.amount), 0) AS total_paid,
    COALESCE(sfa.total_fee, fs.total_fee) - COALESCE(SUM(pay.amount), 0) AS pending_amount,
    sfa.assigned_date AS fee_assigned_date,
    sfa.assignment_type,
    fs.version AS fee_version,
    fs.effective_from AS fee_effective_from,
    COUNT(pay.id) AS payment_count,
    MAX(pay.payment_date) AS last_payment_date
FROM students s
JOIN programs p ON s.program = p.name
LEFT JOIN student_fee_assignments sfa ON sfa.student_id = s.id 
    AND sfa.program = s.program
LEFT JOIN fee_settings fs ON fs.program = s.program 
    AND fs.is_active = 1
LEFT JOIN payments pay ON pay.student_id = s.id
WHERE s.final_registration_number IS NOT NULL
GROUP BY s.id, s.name, s.final_registration_number, p.id, p.name, 
         s.semester, s.year, sfa.total_fee, fs.total_fee, 
         sfa.assigned_date, sfa.assignment_type, fs.version, fs.effective_from;

-- 7. Create stored procedure to apply new fee structure
DELIMITER $$

DROP PROCEDURE IF EXISTS `ApplyNewFeeStructure`$$

CREATE PROCEDURE `ApplyNewFeeStructure`(
    IN p_program VARCHAR(255),
    IN p_new_total_fee DECIMAL(10,2),
    IN p_effective_from DATE,
    IN p_admin_id INT,
    IN p_reason TEXT,
    IN p_apply_to_existing BOOLEAN
)
BEGIN
    DECLARE v_old_fee_setting_id INT;
    DECLARE v_new_fee_setting_id INT;
    DECLARE v_old_fee DECIMAL(10,2);
    DECLARE v_affected_count INT DEFAULT 0;
    DECLARE v_max_version INT;
    
    -- Get current active fee setting
    SELECT id, total_fee INTO v_old_fee_setting_id, v_old_fee
    FROM fee_settings
    WHERE program = p_program
      AND is_active = 1
    LIMIT 1;
    
    -- Get max version for this program
    SELECT COALESCE(MAX(version), 0) INTO v_max_version
    FROM fee_settings
    WHERE program = p_program;
    
    -- Deactivate old fee setting
    IF v_old_fee_setting_id IS NOT NULL THEN
        UPDATE fee_settings
        SET is_active = 0,
            effective_to = DATE_SUB(p_effective_from, INTERVAL 1 DAY)
        WHERE id = v_old_fee_setting_id;
    END IF;
    
    -- Create new fee setting with incremented version
    INSERT INTO fee_settings 
        (program, total_fee, effective_from, is_active, version, created_by, notes)
    VALUES 
        (p_program, p_new_total_fee, p_effective_from, 1, v_max_version + 1, p_admin_id, p_reason);
    
    SET v_new_fee_setting_id = LAST_INSERT_ID();
    
    -- Log the change
    INSERT INTO fee_change_history 
        (program, old_fee, new_fee, old_fee_setting_id, new_fee_setting_id, 
         effective_from, changed_by, reason)
    VALUES 
        (p_program, COALESCE(v_old_fee, 0), p_new_total_fee, 
         v_old_fee_setting_id, v_new_fee_setting_id, p_effective_from, p_admin_id, p_reason);
    
    -- Apply to existing students if requested
    IF p_apply_to_existing THEN
        -- Update existing assignments
        UPDATE student_fee_assignments
        SET fee_setting_id = v_new_fee_setting_id,
            total_fee = p_new_total_fee,
            assigned_date = p_effective_from,
            assigned_by = p_admin_id,
            assignment_type = 'manual',
            notes = CONCAT('Fee updated: ', p_reason)
        WHERE program = p_program;
        
        SET v_affected_count = ROW_COUNT();
    END IF;
    
    -- Update history with affected count
    UPDATE fee_change_history
    SET affected_students = v_affected_count
    WHERE id = LAST_INSERT_ID();
    
    -- Return summary
    SELECT 
        v_new_fee_setting_id AS new_fee_setting_id,
        v_old_fee AS old_fee,
        p_new_total_fee AS new_fee,
        v_affected_count AS affected_students,
        p_effective_from AS effective_from;
END$$

DELIMITER ;

-- 8. Create trigger to auto-assign fees to new students
DELIMITER $$

DROP TRIGGER IF EXISTS `trg_auto_assign_fee_to_student`$$

CREATE TRIGGER `trg_auto_assign_fee_to_student`
AFTER UPDATE ON `students`
FOR EACH ROW
BEGIN
    DECLARE v_fee_setting_id INT;
    DECLARE v_total_fee DECIMAL(10,2);
    
    -- Only trigger when student is finalized (gets registration number)
    IF NEW.final_registration_number IS NOT NULL 
       AND (OLD.final_registration_number IS NULL OR OLD.final_registration_number = '') THEN
        
        -- Get active fee setting for this program
        SELECT id, total_fee INTO v_fee_setting_id, v_total_fee
        FROM fee_settings
        WHERE program = NEW.program
          AND is_active = 1
          AND (effective_from <= CURRENT_DATE OR effective_from IS NULL)
        ORDER BY version DESC
        LIMIT 1;
        
        -- Assign fee to student
        IF v_fee_setting_id IS NOT NULL THEN
            INSERT INTO student_fee_assignments 
                (student_id, fee_setting_id, program, total_fee, assignment_type)
            VALUES 
                (NEW.id, v_fee_setting_id, NEW.program, v_total_fee, 'auto')
            ON DUPLICATE KEY UPDATE
                fee_setting_id = v_fee_setting_id,
                total_fee = v_total_fee;
        END IF;
    END IF;
END$$

DELIMITER ;

-- 9. Create indexes for performance
CREATE INDEX IF NOT EXISTS `idx_fee_settings_active` ON `fee_settings`(`program`, `is_active`, `effective_from`);
CREATE INDEX IF NOT EXISTS `idx_student_fee_assignments_lookup` ON `student_fee_assignments`(`student_id`, `program`);
CREATE INDEX IF NOT EXISTS `idx_fee_change_history_program` ON `fee_change_history`(`program`, `effective_from`);

-- Done!
SELECT 'Fee versioning system installed successfully!' AS status;
