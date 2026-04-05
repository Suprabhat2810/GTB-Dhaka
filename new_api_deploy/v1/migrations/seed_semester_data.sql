-- ============================================================
-- SEED DATA FOR SEMESTER MANAGEMENT SYSTEM
-- Run this after semester_management.sql migration
-- ============================================================

-- Insert semester settings for each program
INSERT INTO semester_settings (program_id, max_semesters, auto_promotion_enabled, promotion_criteria) VALUES
(1, 8, 0, '{"clear_dues": true, "min_attendance": 75}'),
(2, 8, 0, '{"clear_dues": true, "min_attendance": 75}'),
(3, 8, 0, '{"clear_dues": true, "min_attendance": 75}')
ON DUPLICATE KEY UPDATE 
    max_semesters = VALUES(max_semesters),
    promotion_criteria = VALUES(promotion_criteria);

-- Insert academic calendar for 2024-2025
-- Computer Science Program (ID: 1)
INSERT INTO academic_calendar 
(program_id, academic_year, semester_number, semester_name, start_date, end_date, registration_start, registration_end, exam_start, exam_end, status, is_current)
VALUES
-- Semester 1 (Active)
(1, '2024-2025', 1, 'Fall 2024 - Semester 1', '2024-07-01', '2024-11-30', '2024-06-15', '2024-06-30', '2024-11-15', '2024-11-30', 'active', 1),

-- Semester 2 (Upcoming)
(1, '2024-2025', 2, 'Spring 2025 - Semester 2', '2024-12-01', '2025-04-30', '2024-11-15', '2024-11-30', '2025-04-15', '2025-04-30', 'upcoming', 0),

-- Semester 3 (Upcoming)
(1, '2024-2025', 3, 'Fall 2025 - Semester 3', '2025-07-01', '2025-11-30', '2025-06-15', '2025-06-30', '2025-11-15', '2025-11-30', 'upcoming', 0),

-- Semester 4 (Upcoming)
(1, '2024-2025', 4, 'Spring 2026 - Semester 4', '2025-12-01', '2026-04-30', '2025-11-15', '2025-11-30', '2026-04-15', '2026-04-30', 'upcoming', 0),

-- Design Program (ID: 2)
(2, '2024-2025', 1, 'Fall 2024 - Semester 1', '2024-07-01', '2024-11-30', '2024-06-15', '2024-06-30', '2024-11-15', '2024-11-30', 'active', 1),
(2, '2024-2025', 2, 'Spring 2025 - Semester 2', '2024-12-01', '2025-04-30', '2024-11-15', '2024-11-30', '2025-04-15', '2025-04-30', 'upcoming', 0),

-- Mechanical Program (ID: 3)
(3, '2024-2025', 1, 'Fall 2024 - Semester 1', '2024-07-01', '2024-11-30', '2024-06-15', '2024-06-30', '2024-11-15', '2024-11-30', 'active', 1),
(3, '2024-2025', 2, 'Spring 2025 - Semester 2', '2024-12-01', '2025-04-30', '2024-11-15', '2024-11-30', '2025-04-15', '2025-04-30', 'upcoming', 0)

ON DUPLICATE KEY UPDATE
    semester_name = VALUES(semester_name),
    start_date = VALUES(start_date),
    end_date = VALUES(end_date),
    status = VALUES(status);

-- Insert previous academic year (2023-2024) as completed
INSERT INTO academic_calendar 
(program_id, academic_year, semester_number, semester_name, start_date, end_date, registration_start, registration_end, exam_start, exam_end, status, is_current)
VALUES
-- Computer Science - Previous Year
(1, '2023-2024', 1, 'Fall 2023 - Semester 1', '2023-07-01', '2023-11-30', '2023-06-15', '2023-06-30', '2023-11-15', '2023-11-30', 'completed', 0),
(1, '2023-2024', 2, 'Spring 2024 - Semester 2', '2023-12-01', '2024-04-30', '2023-11-15', '2023-11-30', '2024-04-15', '2024-04-30', 'completed', 0)

ON DUPLICATE KEY UPDATE
    status = VALUES(status);

-- Verify data
SELECT 
    ac.id,
    p.name AS program,
    ac.academic_year,
    ac.semester_number,
    ac.semester_name,
    ac.start_date,
    ac.end_date,
    ac.status,
    ac.is_current
FROM academic_calendar ac
JOIN programs p ON ac.program_id = p.id
ORDER BY p.name, ac.academic_year DESC, ac.semester_number;

-- Show semester statistics
SELECT 
    p.name AS program,
    s.semester,
    COUNT(s.id) AS student_count
FROM students s
JOIN programs p ON s.program = p.name
WHERE s.final_registration_number IS NOT NULL
GROUP BY p.name, s.semester
ORDER BY p.name, s.semester;
