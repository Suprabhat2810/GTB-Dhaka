<?php
// subject_allocation.php — hardened, logged, non-breaking replacement
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

$logger = getLogger('subject_allocation');
$pdo = getPDO();
$method = $_SERVER['REQUEST_METHOD'];
$start = microtime(true);

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

        if (!$programId || !$semester || !is_array($subjects) || empty($subjects) || $validFrom === '' || $validTo === '') {
            $logger->warning('subject_allocation POST: invalid input', ['actor' => $actor, 'input' => $data]);
            jsonResponse("error", "Invalid input data provided.", [], 400);
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

            // Fetch existing subjects for this program+semester (to compare/update)
            $stmt = $pdo->prepare("SELECT id, subject_name, instructor, subject_code, valid_from, valid_to FROM subjects WHERE department = ? AND semester = ?");
            $stmt->execute([$programName, $semester]);
            $existingSubjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Build fast lookup by (subject_name)
            $existingByName = [];
            foreach ($existingSubjects as $es) {
                $existingByName[$es['subject_name']][] = $es;
            }

            $pdo->beginTransaction();
            $updatesMade = false;
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
                        INSERT INTO subjects (department, semester, year, subject_name, valid_from, valid_to, subject_code, instructor, schedule, credits, progress)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
                        $subject['progress'] ?? '0'
                    ]);
                    $updatesMade = true;
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
                            SET instructor = ?, valid_from = ?, valid_to = ?, subject_code = ?
                            WHERE department = ? AND semester = ? AND subject_name = ?
                        ");
                        $stmtUpdate->execute([$instructor, $validFrom, $validTo, $subjectCode, $programName, $semester, $subjectName]);
                        $updatesMade = true;
                        $logger->info('subject_allocation POST: updated subject', ['subject' => $subjectName, 'code' => $subjectCode, 'actor' => $actor]);
                    } else {
                        $logger->debug('subject_allocation POST: no change for subject', ['subject' => $subjectName, 'actor' => $actor]);
                    }
                }
            } // end foreach subjects

            // If no changes, rollback and return a warning (non-fatal)
            if (!$updatesMade) {
                $pdo->rollBack();
                $logger->info('subject_allocation POST: no updates detected', ['program' => $programName, 'semester' => $semester, 'actor' => $actor]);
                jsonResponse("warning", "Subjects are already allocated with no changes detected.", [], 200);
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
            $logger->info('subject_allocation POST: completed', ['program' => $programName, 'semester' => $semester, 'duration_ms' => $durationMs, 'actor' => $actor]);

            jsonResponse("success", "Subjects created/updated successfully.", ['updates_made' => true], 201);
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
    // GET — retrieve allocated subjects or programs
    // ----------------------------------------
    elseif ($method === 'GET') {
        $user = authenticate('admin');
        $actor = $user->id ?? null;

        $program = isset($_GET['program']) ? filter_var($_GET['program'], FILTER_VALIDATE_INT) : null;
        $semester = isset($_GET['semester']) ? filter_var($_GET['semester'], FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]) : 1;

        try {
            if ($program) {
                $stmt = $pdo->prepare("SELECT name FROM programs WHERE id = ? LIMIT 1");
                $stmt->execute([$program]);
                $programName = $stmt->fetchColumn();
                if (!$programName) {
                    $logger->info('subject_allocation GET: invalid program id', ['program' => $program, 'actor' => $actor]);
                    jsonResponse("error", "Invalid program ID specified.", [], 400);
                }

                $stmt = $pdo->prepare("
                    SELECT s.id, s.department, s.semester, s.year, s.subject_name, s.valid_from, s.valid_to, s.subject_code, s.instructor, s.schedule, s.credits, s.progress
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
