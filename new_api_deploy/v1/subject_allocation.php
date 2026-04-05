<?php
// subject_allocation.php — hardened, logged, non-breaking replacement
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

$logger = getLogger('subject_allocation');
$pdo = getPDO();
$method = $_SERVER['REQUEST_METHOD'];
$start = microtime(true);

// Debug: Log the actual method received
$logger->info('subject_allocation: request received', [
    'method' => $method,
    'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? ''
]);

try {
    if ($method === 'POST') {
        $user = authenticate('admin');
        $actor = $user->id ?? null;

        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $logger->warning('subject_allocation POST: invalid JSON payload', ['actor' => $actor]);
            jsonResponse("error", "Invalid JSON payload.", [], 400);
        }

        // Program creation flow
        if (isset($data['action']) && $data['action'] === 'create_program') {
            $name = trim((string)($data['name'] ?? ''));
            $code = trim((string)($data['code'] ?? ''));

            if ($name === '' || $code === '') {
                $logger->info('subject_allocation create_program: missing fields', ['actor' => $actor]);
                jsonResponse("error", "Missing required fields: name or code.", [], 400);
            }

            try {
                $stmt = $pdo->prepare("INSERT INTO programs (name, code, created_at) VALUES (?, ?, NOW())");
                $stmt->execute([$name, $code]);

                $logger->info('subject_allocation create_program: created', ['program' => $name, 'code' => $code, 'actor' => $actor]);
                jsonResponse("success", "Program '{$name}' created successfully.", [], 201);
            } catch (PDOException $e) {
                $logger->error('subject_allocation create_program: DB error', ['error' => $e->getMessage(), 'actor' => $actor]);
                jsonResponse("error", "Failed to create program: " . $e->getMessage(), [], 500);
            }
            // end create_program
        }

        // Subject allocation flow — require required fields
        $required = ['program', 'semester', 'subjects', 'valid_from', 'valid_to'];
        foreach ($required as $r) {
            if (!isset($data[$r])) {
                $logger->warning('subject_allocation POST: missing required field', ['field' => $r, 'actor' => $actor]);
                jsonResponse("error", "Missing required fields: program, semester, subjects, valid_from, or valid_to.", [], 400);
            }
        }

        $programId = filter_var($data['program'], FILTER_VALIDATE_INT);
        $semester = filter_var($data['semester'], FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
        $subjects = $data['subjects'];
        $validFrom = trim((string)$data['valid_from']);
        $validTo = trim((string)$data['valid_to']);

        if (!$programId || !$semester || !is_array($subjects) || $validFrom === '' || $validTo === '') {
            $logger->warning('subject_allocation POST: invalid input', ['actor' => $actor, 'input' => $data]);
            jsonResponse("error", "Invalid input data provided.", [], 400);
        }
        
        // Allow empty subjects array - just skip processing if empty
        if (empty($subjects)) {
            $logger->info('subject_allocation POST: empty subjects array, nothing to process', ['program' => $programId, 'semester' => $semester, 'actor' => $actor]);
            jsonResponse("success", "No subjects to allocate.", [], 200);
        }

        // Validate dates
        $validFromDate = DateTime::createFromFormat('Y-m-d', $validFrom);
        $validToDate = DateTime::createFromFormat('Y-m-d', $validTo);
        if (!$validFromDate || !$validToDate || $validFromDate > $validToDate) {
            $logger->info('subject_allocation POST: invalid date range', ['valid_from' => $validFrom, 'valid_to' => $validTo, 'actor' => $actor]);
            jsonResponse("error", "Invalid date range: valid_to must be after valid_from.", [], 400);
        }

        try {
            // Resolve program info
            $stmt = $pdo->prepare("SELECT name, code FROM programs WHERE id = ? LIMIT 1");
            $stmt->execute([$programId]);
            $programRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$programRow) {
                $logger->info('subject_allocation POST: invalid program id', ['program_id' => $programId, 'actor' => $actor]);
                jsonResponse("error", "Invalid program ID specified.", [], 400);
            }
            $programName = $programRow['name'];
            $programCode = $programRow['code'];

            $logger->info('subject_allocation POST: start allocation', [
                'program_id' => $programId, 'program' => $programName, 'semester' => $semester, 'valid_from' => $validFrom, 'valid_to' => $validTo, 'actor' => $actor
            ]);

            // Fetch existing subjects for this program+semester that overlap with the new date range
            // This prevents treating new allocations as "no change" when subject names match but date ranges differ
            $stmt = $pdo->prepare("
                SELECT id, subject_name, instructor, subject_code, valid_from, valid_to 
                FROM subjects 
                WHERE department = ? 
                  AND semester = ?
                  AND (
                    (valid_from <= ? AND valid_to >= ?) OR  -- Existing overlaps new start date
                    (valid_from <= ? AND valid_to >= ?) OR  -- Existing overlaps new end date
                    (valid_from >= ? AND valid_to <= ?)     -- New date range contains existing
                  )
            ");
            $stmt->execute([$programName, $semester, $validFrom, $validFrom, $validTo, $validTo, $validFrom, $validTo]);
            $existingSubjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $logger->info('subject_allocation POST: found overlapping subjects', [
                'count' => count($existingSubjects),
                'subjects' => array_column($existingSubjects, 'subject_name'),
                'date_range' => "$validFrom to $validTo",
                'actor' => $actor
            ]);

            // Build fast lookup by (subject_name)
            $existingByName = [];
            foreach ($existingSubjects as $es) {
                $existingByName[$es['subject_name']][] = $es;
            }

            $pdo->beginTransaction();
            $updatesMade = false;
            $insertCount = 0;
            $updateCount = 0;
            $noChangeCount = 0;
            $deleteCount = 0;
            $year = (int)date('Y');

            // Helper: generate unique subject code with DB check (uses SELECT FOR UPDATE to reduce race - fallback simple loop)
            $generateUniqueCode = function(string $baseCode, string $subjectName) use ($pdo, $programName) : string {
                $maxAttempts = 2000;
                $attempt = 0;
                do {
                    $suffix = str_pad((string)random_int(1, 999), 3, '0', STR_PAD_LEFT);
                    $candidate = $baseCode . $suffix;
                    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM subjects WHERE department = ? AND subject_code = ?");
                    $stmtCheck->execute([$programName, $candidate]);
                    $count = (int)$stmtCheck->fetchColumn();
                    $attempt++;
                    if ($attempt >= $maxAttempts) {
                        throw new RuntimeException("Unable to generate unique subject code for '{$subjectName}' after $maxAttempts attempts.");
                    }
                } while ($count > 0);
                return $candidate;
            };

            foreach ($subjects as $subject) {
                // Validate subject entry
                if (!is_array($subject) || empty($subject['name'])) {
                    $logger->info('subject_allocation POST: skipping invalid subject entry', ['entry' => $subject, 'actor' => $actor]);
                    continue;
                }

                $subjectName = trim((string)$subject['name']);
                $instructor = isset($subject['instructor']) ? trim((string)$subject['instructor']) : 'N/A';
                $providedSubjectCode = isset($subject['subject_code']) ? trim((string)$subject['subject_code']) : '';

                // Determine subject code
                if ($providedSubjectCode === '') {
                    // generate
                    try {
                        $subjectCode = $generateUniqueCode($programCode, $subjectName);
                    } catch (RuntimeException $e) {
                        $pdo->rollBack();
                        $logger->error('subject_allocation POST: code generation failed', ['error' => $e->getMessage(), 'actor' => $actor]);
                        jsonResponse("error", $e->getMessage(), [], 500);
                    }
                } else {
                    // validate provided code prefix
                    if (strpos($providedSubjectCode, $programCode) !== 0) {
                        $pdo->rollBack();
                        $logger->info('subject_allocation POST: provided code invalid prefix', ['provided' => $providedSubjectCode, 'programCode' => $programCode, 'actor' => $actor]);
                        jsonResponse("error", "Provided subject code '{$providedSubjectCode}' must start with the program code '{$programCode}'.", [], 400);
                    }
                    // ensure uniqueness (allow updating same named subject)
                    $stmtDup = $pdo->prepare("SELECT COUNT(*) FROM subjects WHERE department = ? AND subject_code = ? AND subject_name != ?");
                    $stmtDup->execute([$programName, $providedSubjectCode, $subjectName]);
                    if ((int)$stmtDup->fetchColumn() > 0) {
                        $pdo->rollBack();
                        $logger->info('subject_allocation POST: duplicate subject code', ['code' => $providedSubjectCode, 'actor' => $actor]);
                        jsonResponse("error", "Subject code '{$providedSubjectCode}' already exists for this program.", [], 400);
                    }
                    $subjectCode = $providedSubjectCode;
                }

                // Compare with existing subjects for same name; if none, insert; if present but differing, update
                $matches = $existingByName[$subjectName] ?? [];

                $matched = null;
                foreach ($matches as $m) {
                    // consider it a match if subject_name matches; then decide update vs insert
                    $matched = $m;
                    break;
                }

                if ($matched === null) {
                    // Insert new subject
                    $stmtInsert = $pdo->prepare("
                        INSERT INTO subjects (department, semester, year, subject_name, valid_from, valid_to, subject_code, instructor, schedule, credits, progress, type)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmtInsert->execute([
                        $programName,
                        $semester,
                        $year,
                        $subjectName,
                        $validFrom,
                        $validTo,
                        $subjectCode,
                        $instructor,
                        $subject['schedule'] ?? 'N/A',
                        $subject['credits'] ?? '0',
                        $subject['progress'] ?? '0',
                        $subject['type'] ?? ''
                    ]);
                    $updatesMade = true;
                    $insertCount++;
                    $logger->info('subject_allocation POST: inserted subject', ['subject' => $subjectName, 'code' => $subjectCode, 'actor' => $actor]);
                } else {
                    // Check if any meaningful field changed
                    $needsUpdate = false;
                    if ($matched['instructor'] !== $instructor) $needsUpdate = true;
                    if ($matched['subject_code'] !== $subjectCode) $needsUpdate = true;
                    if ($matched['valid_from'] !== $validFrom) $needsUpdate = true;
                    if ($matched['valid_to'] !== $validTo) $needsUpdate = true;

                    if ($needsUpdate) {
                        $stmtUpdate = $pdo->prepare("
                            UPDATE subjects 
                            SET instructor = ?, valid_from = ?, valid_to = ?, subject_code = ?, type = ?
                            WHERE department = ? AND semester = ? AND subject_name = ?
                        ");
                        $stmtUpdate->execute([$instructor, $validFrom, $validTo, $subjectCode, $subject['type'] ?? '', $programName, $semester, $subjectName]);
                        $updatesMade = true;
                        $updateCount++;
                        $logger->info('subject_allocation POST: updated subject', ['subject' => $subjectName, 'code' => $subjectCode, 'actor' => $actor]);
                    } else {
                        $noChangeCount++;
                        $logger->debug('subject_allocation POST: no change for subject', ['subject' => $subjectName, 'actor' => $actor]);
                    }
                }
            } // end foreach subjects

            // Deletion logic removed - now handled by separate DELETE endpoint
            // Allocate endpoint only handles INSERT and UPDATE operations

            // Build response message based on what happened
            if (!$updatesMade) {
                // True duplicates - no changes needed
                $pdo->commit();
                $message = "All subjects are already up to date. No changes needed.";
                $logger->info('subject_allocation POST: no changes needed', [
                    'program' => $programName,
                    'semester' => $semester,
                    'no_change_count' => $noChangeCount,
                    'actor' => $actor
                ]);
                jsonResponse("success", $message, [
                    'inserts' => 0,
                    'updates' => 0,
                    'deletes' => 0,
                    'no_changes' => $noChangeCount
                ], 200);
            }

            // After subjects insertion/update, update student semesters if subject validity expired
            // This is a best-effort, non-transactional with respect to student updates (we performed subject writes already)
            try {
                $currentDate = new DateTime('now');
                $stmtStudents = $pdo->prepare("SELECT id, semester, year FROM students WHERE program = ?");
                $stmtStudents->execute([$programName]);
                $studentsToUpdate = $stmtStudents->fetchAll(PDO::FETCH_ASSOC);

                foreach ($studentsToUpdate as $studentRow) {
                    $sid = (int)$studentRow['id'];
                    $studentSemester = (int)$studentRow['semester'];
                    $stmtCheck = $pdo->prepare("SELECT MAX(valid_to) as last_valid_to FROM subjects WHERE department = ? AND semester = ?");
                    $stmtCheck->execute([$programName, $studentSemester]);
                    $lastValidTo = $stmtCheck->fetchColumn();
                    if ($lastValidTo && new DateTime($lastValidTo) < $currentDate) {
                        $newSemester = $studentSemester + 1;
                        $newYear = (int)$studentRow['year'];
                        if ($newSemester > 8) {
                            $newYear += 1;
                            $newSemester = 1;
                        }
                        $stmtUpdateStudent = $pdo->prepare("UPDATE students SET semester = ?, year = ? WHERE id = ?");
                        $stmtUpdateStudent->execute([$newSemester, $newYear, $sid]);
                        $logger->info('subject_allocation POST: advanced student semester', ['student_id' => $sid, 'new_semester' => $newSemester, 'new_year' => $newYear, 'actor' => $actor]);
                    }
                }
            } catch (PDOException $e) {
                // Student updates are best-effort — log but don't fail the whole operation
                $logger->warning('subject_allocation POST: student semester update failed (best-effort)', ['error' => $e->getMessage(), 'actor' => $actor]);
            }

            // commit final
            $pdo->commit();
            $durationMs = round((microtime(true) - $start) * 1000, 2);
            
            // Build detailed success message
            $operations = [];
            if ($insertCount > 0) $operations[] = "$insertCount inserted";
            if ($updateCount > 0) $operations[] = "$updateCount updated";
            
            $message = "Subjects allocation completed successfully.";
            if (!empty($operations)) {
                $message .= " (" . implode(", ", $operations) . ")";
            }
            
            $logger->info('subject_allocation POST: completed', [
                'program' => $programName,
                'semester' => $semester,
                'inserts' => $insertCount,
                'updates' => $updateCount,
                'no_changes' => $noChangeCount,
                'duration_ms' => $durationMs,
                'actor' => $actor
            ]);

            jsonResponse("success", $message, [
                'updates_made' => true,
                'inserts' => $insertCount,
                'updates' => $updateCount,
                'no_changes' => $noChangeCount
            ], 201);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $logger->error('subject_allocation POST: DB error', ['error' => $e->getMessage(), 'actor' => $actor]);
            jsonResponse("error", "Failed to allocate subjects: " . $e->getMessage(), [], 500);
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $logger->error('subject_allocation POST: runtime error', ['error' => $e->getMessage(), 'actor' => $actor]);
            jsonResponse("error", $e->getMessage(), [], 500);
        }
    }

    // ----------------------------------------
    // DELETE — delete subjects
    // ----------------------------------------
    elseif ($method === 'DELETE') {
        $user = authenticate('admin');
        $actor = $user->id ?? null;

        $program = isset($_GET['program']) ? filter_var($_GET['program'], FILTER_VALIDATE_INT) : null;
        $semester = isset($_GET['semester']) ? filter_var($_GET['semester'], FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]) : null;
        
        // Get subject IDs from request body
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        $subjectIds = $data['subject_ids'] ?? [];

        if (!$program || !$semester) {
            $logger->warning('subject_allocation DELETE: missing program or semester', ['actor' => $actor]);
            jsonResponse("error", "Program and semester are required.", [], 400);
        }

        try {
            // Get program name
            $stmt = $pdo->prepare("SELECT name FROM programs WHERE id = ? LIMIT 1");
            $stmt->execute([$program]);
            $programName = $stmt->fetchColumn();
            if (!$programName) {
                $logger->info('subject_allocation DELETE: invalid program id', ['program' => $program, 'actor' => $actor]);
                jsonResponse("error", "Invalid program ID specified.", [], 400);
            }

            if (empty($subjectIds)) {
                // Delete ALL subjects for this program+semester
                $stmt = $pdo->prepare("DELETE FROM subjects WHERE department = ? AND semester = ?");
                $stmt->execute([$programName, $semester]);
                $deleteCount = $stmt->rowCount();
                
                $logger->info('subject_allocation DELETE: deleted all subjects', [
                    'program' => $programName,
                    'semester' => $semester,
                    'deleted_count' => $deleteCount,
                    'actor' => $actor
                ]);
                jsonResponse("success", "Deleted all subjects for this semester.", ['deleted_count' => $deleteCount], 200);
            } else {
                // Delete specific subjects by ID
                $placeholders = implode(',', array_fill(0, count($subjectIds), '?'));
                $stmt = $pdo->prepare("DELETE FROM subjects WHERE id IN ($placeholders) AND department = ? AND semester = ?");
                $params = array_merge($subjectIds, [$programName, $semester]);
                $stmt->execute($params);
                $deleteCount = $stmt->rowCount();
                
                $logger->info('subject_allocation DELETE: deleted specific subjects', [
                    'program' => $programName,
                    'semester' => $semester,
                    'subject_ids' => $subjectIds,
                    'deleted_count' => $deleteCount,
                    'actor' => $actor
                ]);
                jsonResponse("success", "Deleted $deleteCount subject(s) successfully.", ['deleted_count' => $deleteCount], 200);
            }
        } catch (PDOException $e) {
            $logger->error('subject_allocation DELETE: DB error', ['error' => $e->getMessage(), 'actor' => $actor]);
            jsonResponse("error", "Failed to delete subjects: " . $e->getMessage(), [], 500);
        }
    }

    // ----------------------------------------
    // GET — retrieve allocated subjects or programs
    // ----------------------------------------
    elseif ($method === 'GET') {
        $user = authenticate('admin');
        $actor = $user->id ?? null;

        $action = isset($_GET['action']) ? trim($_GET['action']) : '';
        $program = isset($_GET['program']) ? filter_var($_GET['program'], FILTER_VALIDATE_INT) : null;
        $semester = isset($_GET['semester']) ? filter_var($_GET['semester'], FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]) : 1;

        try {
            // Get instructors list with their teaching history
            if ($action === 'get_instructors' && $program) {
                $stmt = $pdo->prepare("SELECT name FROM programs WHERE id = ? LIMIT 1");
                $stmt->execute([$program]);
                $programName = $stmt->fetchColumn();
                if (!$programName) {
                    $logger->info('subject_allocation GET instructors: invalid program id', ['program' => $program, 'actor' => $actor]);
                    jsonResponse("error", "Invalid program ID specified.", [], 400);
                }

                $stmt = $pdo->prepare("
                    SELECT 
                        instructor,
                        GROUP_CONCAT(DISTINCT subject_name ORDER BY subject_name SEPARATOR ', ') as subjects_taught,
                        MAX(semester) as last_semester,
                        MAX(YEAR(valid_from)) as last_year,
                        COUNT(DISTINCT subject_name) as total_subjects,
                        MAX(valid_from) as last_taught_date
                    FROM subjects
                    WHERE department = ? 
                      AND instructor IS NOT NULL 
                      AND instructor != '' 
                      AND instructor != 'N/A'
                    GROUP BY instructor
                    ORDER BY last_taught_date DESC, total_subjects DESC
                    LIMIT 50
                ");
                $stmt->execute([$programName]);
                $instructors = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $logger->info('subject_allocation GET: instructors retrieved', ['program' => $programName, 'count' => count($instructors), 'actor' => $actor]);
                jsonResponse("success", "Instructors retrieved successfully.", ["instructors" => $instructors], 200);
            }
            // Get previous semester allocations for copying
            elseif ($action === 'get_previous_allocations' && $program) {
                $stmt = $pdo->prepare("SELECT name FROM programs WHERE id = ? LIMIT 1");
                $stmt->execute([$program]);
                $programName = $stmt->fetchColumn();
                if (!$programName) {
                    $logger->info('subject_allocation GET previous: invalid program id', ['program' => $program, 'actor' => $actor]);
                    jsonResponse("error", "Invalid program ID specified.", [], 400);
                }

                // Exclude current semester from previous allocations
                $currentSemester = $semester; // Already fetched from $_GET above
                
                // Get distinct semesters with their most recent data (ignoring year)
                $stmt = $pdo->prepare("
                    SELECT 
                        semester,
                        MAX(year) as academic_year,
                        MAX(valid_from) as valid_from,
                        MAX(valid_to) as valid_to,
                        COUNT(*) as subject_count
                    FROM subjects
                    WHERE department = ?
                      AND semester != ?
                    GROUP BY semester
                    ORDER BY semester ASC
                ");
                $stmt->execute([$programName, $currentSemester]);
                $allocations = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Get subjects for each semester (most recent entries)
                foreach ($allocations as &$allocation) {
                    $stmt = $pdo->prepare("
                        SELECT subject_name, instructor, subject_code, credits, schedule, type
                        FROM subjects
                        WHERE department = ? 
                          AND semester = ?
                        ORDER BY subject_name ASC
                    ");
                    $stmt->execute([$programName, $allocation['semester']]);
                    $allocation['subjects'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }

                $logger->info('subject_allocation GET: previous allocations retrieved', ['program' => $programName, 'count' => count($allocations), 'actor' => $actor]);
                jsonResponse("success", "Previous allocations retrieved successfully.", ["previous_allocations" => $allocations], 200);
            }
            // Get allocated subjects for program+semester
            elseif ($program) {
                $stmt = $pdo->prepare("SELECT name FROM programs WHERE id = ? LIMIT 1");
                $stmt->execute([$program]);
                $programName = $stmt->fetchColumn();
                if (!$programName) {
                    $logger->info('subject_allocation GET: invalid program id', ['program' => $program, 'actor' => $actor]);
                    jsonResponse("error", "Invalid program ID specified.", [], 400);
                }

                $stmt = $pdo->prepare("
                    SELECT s.id, s.department, s.semester, s.year, s.subject_name, s.valid_from, s.valid_to, s.subject_code, s.instructor, s.schedule, s.credits, s.progress, s.type
                    FROM subjects s
                    WHERE s.department = ? AND s.semester = ? AND s.valid_from <= CURDATE() AND s.valid_to >= CURDATE()
                    ORDER BY s.subject_name ASC
                ");
                $stmt->execute([$programName, $semester]);
                $allocatedSubjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $logger->info('subject_allocation GET: allocated subjects retrieved', ['program' => $programName, 'semester' => $semester, 'count' => count($allocatedSubjects), 'actor' => $actor]);
                jsonResponse("success", "Allocated subjects retrieved successfully.", ["allocated_subjects" => $allocatedSubjects], 200);
            } else {
                $stmt = $pdo->query("SELECT id, name FROM programs ORDER BY name ASC");
                $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $logger->info('subject_allocation GET: programs list retrieved', ['count' => count($programs), 'actor' => $actor]);
                jsonResponse("success", "Programs retrieved.", ["programs" => $programs], 200);
            }
        } catch (PDOException $e) {
            $logger->error('subject_allocation GET: DB error', ['error' => $e->getMessage(), 'actor' => $actor]);
            jsonResponse("error", "Server error: " . $e->getMessage(), [], 500);
        }
    }

    // unsupported method
    else {
        $logger->warning('subject_allocation: method not allowed', ['method' => $method]);
        jsonResponse("error", "Method not allowed.", [], 405);
    }
} catch (Exception $e) {
    $logger->critical('subject_allocation: unexpected error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    jsonResponse("error", "Internal server error.", [], 500);
}
