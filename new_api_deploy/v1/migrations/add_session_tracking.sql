-- Migration: Add session tracking to payments table
-- Date: 2025-11-27
-- Description: Add session_id and remaining_after_payment columns to track payment sessions

-- Add session_id column to link payments to sessions
ALTER TABLE payments 
ADD COLUMN session_id INT NULL AFTER total_fee,
ADD COLUMN remaining_after_payment DECIMAL(10,2) NULL AFTER session_id;

-- Add foreign key constraint
ALTER TABLE payments 
ADD CONSTRAINT fk_payments_session 
FOREIGN KEY (session_id) REFERENCES payment_sessions(id) 
ON DELETE SET NULL;

-- Create index for better query performance
CREATE INDEX idx_payments_session ON payments(session_id);
CREATE INDEX idx_payments_student_session ON payments(student_id, session_id);

-- Update existing payments to have remaining_after_payment calculated
-- This will be done by the backend when fetching old payments
