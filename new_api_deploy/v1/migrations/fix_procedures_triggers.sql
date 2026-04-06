-- ============================================================
-- FIX STORED PROCEDURES AND TRIGGERS
-- Adds parameter validation and error handling
-- Fixes issues #25, #27
-- ============================================================

-- ============================================================
-- ISSUE #27: Add parameter validation to ApplyNewFeeStructure
-- ============================================================

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
    
    -- PARAMETER VALIDATION
    IF p_program IS NULL OR p_program = '' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Program name cannot be empty';
    END IF;
    
    IF p_new_total_fee IS NULL OR p_new_total_fee <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Total fee must be greater than 0';
    END IF;
    
    IF p_effective_from IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Effective date is required';
    END IF;
    
    IF p_effective_from < CURRENT_DATE THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Effective date cannot be in the past';
    END IF;
    
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

-- ============================================================
-- ISSUE #27: Add parameter validation to BulkPromoteStudents
-- ============================================================

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
    
    -- PARAMETER VALIDATION
    IF p_program_id IS NULL OR p_program_id <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid program ID';
    END IF;
    
    IF p_from_semester IS NULL OR p_from_semester < 1 OR p_from_semester > 8 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'From semester must be between 1 and 8';
    END IF;
    
    IF p_to_semester IS NULL OR p_to_semester < 1 OR p_to_semester > 8 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'To semester must be between 1 and 8';
    END IF;
    
    IF p_to_semester <= p_from_semester THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'To semester must be greater than from semester';
    END IF;
    
    -- Get program name
    SELECT name INTO v_program_name FROM programs WHERE id = p_program_id;
    
    IF v_program_name IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Program not found';
    END IF;
    
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

-- ============================================================
-- ISSUE #25: Add error handling to fee assignment trigger
-- ============================================================

DELIMITER $$

DROP TRIGGER IF EXISTS `trg_auto_assign_fee_to_student`$$

CREATE TRIGGER `trg_auto_assign_fee_to_student`
AFTER UPDATE ON `students`
FOR EACH ROW
BEGIN
    DECLARE v_fee_setting_id INT;
    DECLARE v_total_fee DECIMAL(10,2);
    DECLARE v_error_logged INT DEFAULT 0;
    
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
        
        -- Assign fee to student if found
        IF v_fee_setting_id IS NOT NULL THEN
            INSERT INTO student_fee_assignments 
                (student_id, fee_setting_id, program, total_fee, assignment_type)
            VALUES 
                (NEW.id, v_fee_setting_id, NEW.program, v_total_fee, 'auto')
            ON DUPLICATE KEY UPDATE
                fee_setting_id = v_fee_setting_id,
                total_fee = v_total_fee;
        ELSE
            -- Log error: No active fee setting found
            -- Insert into a trigger_errors log table (create if needed)
            INSERT IGNORE INTO trigger_errors 
                (trigger_name, table_name, record_id, error_message, created_at)
            VALUES 
                ('trg_auto_assign_fee_to_student', 'students', NEW.id, 
                 CONCAT('No active fee setting found for program: ', NEW.program), NOW());
        END IF;
    END IF;
END$$

DELIMITER ;

-- ============================================================
-- Create trigger_errors table for logging trigger failures
-- ============================================================

CREATE TABLE IF NOT EXISTS `trigger_errors` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `trigger_name` VARCHAR(100) NOT NULL,
    `table_name` VARCHAR(100) NOT NULL,
    `record_id` INT DEFAULT NULL,
    `error_message` TEXT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_trigger_errors_created` (`created_at`),
    INDEX `idx_trigger_errors_trigger` (`trigger_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- Verification
-- ============================================================

SELECT 'Procedures and triggers updated with validation and error handling!' AS status;
