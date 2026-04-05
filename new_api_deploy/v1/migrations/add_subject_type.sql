-- Add type column to subjects table
-- This column stores the subject type: Theory, Practical, or Lab

ALTER TABLE `subjects` 
ADD COLUMN `type` VARCHAR(20) DEFAULT '' AFTER `schedule`;

-- Update existing records to have empty type (will be filled by users)
UPDATE `subjects` SET `type` = '' WHERE `type` IS NULL;
