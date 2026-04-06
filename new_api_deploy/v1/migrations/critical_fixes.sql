-- ============================================================
-- CRITICAL FIXES MIGRATION
-- Fixes foreign key constraints, indexes, and data integrity issues
-- Run this migration to fix issues #3, #7, #16, #21, #22, #24
-- ============================================================

-- ============================================================
-- ISSUE #3 & #21: Add CASCADE to student_subjects foreign key
-- This fixes the subject deletion error
-- ============================================================

-- Drop existing foreign key constraint
ALTER TABLE `student_subjects` 
DROP FOREIGN KEY `student_subjects_ibfk_2`;

-- Re-add with ON DELETE CASCADE
ALTER TABLE `student_subjects` 
ADD CONSTRAINT `student_subjects_ibfk_2` 
FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) 
ON DELETE CASCADE;

-- Also add CASCADE to student_id for consistency
ALTER TABLE `student_subjects` 
DROP FOREIGN KEY `student_subjects_ibfk_1`;

ALTER TABLE `student_subjects` 
ADD CONSTRAINT `student_subjects_ibfk_1` 
FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) 
ON DELETE CASCADE;

-- ============================================================
-- ISSUE #7: Add unique constraint for subject_code
-- Prevents race condition in subject code generation
-- ============================================================

-- First, check for and remove any duplicate subject codes
CREATE TEMPORARY TABLE temp_duplicate_subjects AS
SELECT subject_code, MIN(id) as keep_id
FROM subjects
WHERE subject_code IS NOT NULL
GROUP BY subject_code
HAVING COUNT(*) > 1;

-- Delete duplicates (keeping the oldest one)
DELETE s FROM subjects s
INNER JOIN temp_duplicate_subjects t ON s.subject_code = t.subject_code
WHERE s.id != t.keep_id;

DROP TEMPORARY TABLE temp_duplicate_subjects;

-- Now add unique constraint (if not already exists)
SET @constraint_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'subjects' 
    AND CONSTRAINT_NAME = 'unique_subject_code'
);

SET @sql = IF(@constraint_exists = 0, 
    'ALTER TABLE `subjects` ADD CONSTRAINT `unique_subject_code` UNIQUE (`subject_code`)',
    'SELECT "Unique constraint already exists on subject_code"'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- ISSUE #16 & #24: Add missing indexes for performance
-- ============================================================

-- Index for notifications foreign key
CREATE INDEX IF NOT EXISTS `idx_notifications_student_fk` 
ON `notifications`(`student_id`);

-- Index for documents foreign key
CREATE INDEX IF NOT EXISTS `idx_documents_student_fk` 
ON `documents`(`student_id`);

-- Index for payments foreign keys
CREATE INDEX IF NOT EXISTS `idx_payments_student_fk` 
ON `payments`(`student_id`);

CREATE INDEX IF NOT EXISTS `idx_payments_session_fk` 
ON `payments`(`session_id`);

-- Index for student_subjects foreign keys
CREATE INDEX IF NOT EXISTS `idx_student_subjects_student_fk` 
ON `student_subjects`(`student_id`);

CREATE INDEX IF NOT EXISTS `idx_student_subjects_subject_fk` 
ON `student_subjects`(`subject_id`);

-- Index for trigger query optimization (Issue #24)
CREATE INDEX IF NOT EXISTS `idx_transitions_lookup` 
ON `semester_transitions`(`student_id`, `from_semester`, `to_semester`, `transition_date`);

-- Index for allocation_logs
CREATE INDEX IF NOT EXISTS `idx_allocation_logs_student_fk` 
ON `allocation_logs`(`student_id`);

-- Index for approvals
CREATE INDEX IF NOT EXISTS `idx_approvals_student_fk` 
ON `approvals`(`student_id`);

CREATE INDEX IF NOT EXISTS `idx_approvals_admin_fk` 
ON `approvals`(`admin_id`);

-- ============================================================
-- ISSUE #22: Fix trigger race condition
-- Add unique constraint to prevent duplicate logging
-- ============================================================

-- Add unique constraint to prevent duplicate transition logs
CREATE UNIQUE INDEX IF NOT EXISTS `idx_unique_transition` 
ON `semester_transitions`(`student_id`, `from_semester`, `to_semester`, DATE(`transition_date`));

-- ============================================================
-- Verification Queries
-- ============================================================

-- Verify CASCADE constraints
SELECT 
    CONSTRAINT_NAME,
    TABLE_NAME,
    REFERENCED_TABLE_NAME,
    DELETE_RULE,
    UPDATE_RULE
FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = DATABASE()
AND TABLE_NAME IN ('student_subjects', 'notifications', 'documents', 'payments')
ORDER BY TABLE_NAME, CONSTRAINT_NAME;

-- Verify unique constraints
SELECT 
    TABLE_NAME,
    CONSTRAINT_NAME,
    CONSTRAINT_TYPE
FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
WHERE TABLE_SCHEMA = DATABASE()
AND CONSTRAINT_TYPE = 'UNIQUE'
AND TABLE_NAME IN ('subjects', 'semester_transitions')
ORDER BY TABLE_NAME;

-- Verify indexes
SELECT 
    TABLE_NAME,
    INDEX_NAME,
    COLUMN_NAME,
    SEQ_IN_INDEX
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
AND TABLE_NAME IN ('student_subjects', 'semester_transitions', 'notifications', 'documents', 'payments')
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;

SELECT 'Critical fixes migration completed successfully!' AS status;
