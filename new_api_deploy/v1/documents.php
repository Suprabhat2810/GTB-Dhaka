<?php
// documents.php — hardened, logged, non-breaking replacement
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

$logger = getLogger('documents');
$pdo = getPDO();
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        // Only admins can fetch documents (keeps original behavior)
        $user = authenticate('admin');

        try {
            $logger->info('Documents listing requested', ['actor' => $user->id ?? null]);

            $stmt = $pdo->prepare("
                SELECT d.id, s.name AS student, d.document_path, d.upload_date, d.file_size, d.file_type, COALESCE(d.document_type, 'N/A') AS document_type, COALESCE(d.status, 'pending') AS status
                FROM documents d
                JOIN students s ON d.student_id = s.id
            ");
            $stmt->execute();
            $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $formattedDocuments = array_map(function ($doc) {
                return [
                    'id' => $doc['id'],
                    'student' => $doc['student'] ?? 'Unknown',
                    'document_name' => basename($doc['document_path']),
                    'document_path' => $doc['document_path'],
                    'upload_date' => $doc['upload_date'],
                    'file_type' => $doc['file_type'],
                    'document_type' => $doc['document_type'],
                    'status' => $doc['status'],
                ];
            }, $documents);

            $total = count($documents);
            $verified = count(array_filter($documents, fn($d) => ($d['status'] ?? 'pending') === 'verified'));
            $pending = $total - $verified;

            $logger->info('Documents retrieved', ['total' => $total, 'verified' => $verified, 'pending' => $pending]);

            jsonResponse("success", "Documents retrieved successfully.", [
                'documents' => $formattedDocuments,
                'total' => $total,
                'verified' => $verified,
                'pending' => $pending,
            ], 200);
        } catch (PDOException $e) {
            $logger->error('Failed to fetch documents', ['error' => $e->getMessage()]);
            jsonResponse("error", "Failed to fetch documents: " . $e->getMessage(), [], 500);
        }
    }

    elseif ($method === 'POST') {
        // Upload document: admin can upload for any student; student can upload for themselves
        $user = authenticate(); // may be admin or student

        if (!isset($_FILES['document']) || !isset($_POST['student_id'])) {
            $logger->warning('Document upload missing file or student_id', ['actor' => $user->id ?? null]);
            jsonResponse("error", "Missing document or student ID.", [], 400);
        }

        $studentId = filter_var($_POST['student_id'], FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
        if (!$studentId) {
            $logger->warning('Invalid student_id provided for document upload', ['student_id' => $_POST['student_id'] ?? null, 'actor' => $user->id ?? null]);
            jsonResponse("error", "Invalid student ID.", [], 400);
        }

        // permission: admin OR the student themself
        if (($user->role ?? null) !== 'admin' && ($user->id ?? null) != $studentId && ($user->student_id ?? null) != $studentId) {
            $logger->warning('Unauthorized document upload attempt', ['actor' => $user->id ?? null, 'student_id' => $studentId]);
            jsonResponse("error", "Unauthorized access.", [], 403);
        }

        // Validate student exists and is approved
        try {
            $checkStudent = $pdo->prepare("SELECT 1 FROM students WHERE id = ? LIMIT 1");
            $checkStudent->execute([$studentId]);
            if (!$checkStudent->fetchColumn()) {
                $logger->info('Document upload for non-existing student', ['student_id' => $studentId, 'actor' => $user->id ?? null]);
                jsonResponse("error", "Student not found.", [], 404);
            }

            $checkApproval = $pdo->prepare("SELECT approved FROM approvals WHERE student_id = ? LIMIT 1");
            $checkApproval->execute([$studentId]);
            $approval = $checkApproval->fetch(PDO::FETCH_ASSOC);
            if (!$approval || (int)$approval['approved'] !== 1) {
                $logger->info('Document upload attempted for unapproved student', ['student_id' => $studentId, 'actor' => $user->id ?? null]);
                jsonResponse("error", "Student not approved yet.", [], 403);
            }
        } catch (PDOException $e) {
            $logger->error('Student validation failed', ['error' => $e->getMessage(), 'student_id' => $studentId]);
            jsonResponse("error", "Failed to validate student.", [], 500);
        }

        // File validations
        $allowedFileTypes = ['application/pdf', 'image/jpeg', 'image/png'];
        $allowedExtensions = ['pdf', 'jpeg', 'jpg', 'png'];
        $maxFileSize = 5 * 1024 * 1024; // 5 MB
        $uploadDir = __DIR__ . '/../uploads/documents/';

        $file = $_FILES['document'];
        $fileName = basename($file['name'] ?? '');
        $fileTmpName = $file['tmp_name'] ?? '';
        $fileSize = $file['size'] ?? 0;

        if (!is_uploaded_file($fileTmpName)) {
            $logger->warning('Upload failed - temp file missing or invalid upload', ['actor' => $user->id ?? null, 'file' => $fileName]);
            jsonResponse("error", "Invalid uploaded file.", [], 400);
        }

        if ($fileSize > $maxFileSize) {
            $logger->info('Upload rejected - file too large', ['size' => $fileSize, 'max' => $maxFileSize, 'actor' => $user->id ?? null]);
            jsonResponse("error", "File is too large. Maximum allowed size is 5 MB.", [], 400);
        }

        // Use finfo for MIME detection
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $fileType = $finfo->file($fileTmpName) ?: mime_content_type($fileTmpName);
        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if (!in_array($fileType, $allowedFileTypes, true) || !in_array($fileExt, $allowedExtensions, true)) {
            $logger->warning('Upload rejected - invalid file type', ['mime' => $fileType, 'ext' => $fileExt, 'actor' => $user->id ?? null]);
            jsonResponse("error", "Invalid file type. Allowed types are PDF, JPEG, PNG.", [], 400);
        }

        // Ensure upload directory exists and writable
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
            $logger->error('Failed to create upload directory', ['dir' => $uploadDir]);
            jsonResponse("error", "Failed to create upload directory.", [], 500);
        }
        if (!is_writable($uploadDir)) {
            $logger->error('Upload directory not writable', ['dir' => $uploadDir]);
            jsonResponse("error", "Upload directory is not writable.", [], 500);
        }

        // Move file
        $newFileName = uniqid('doc_', true) . '.' . $fileExt;
        $targetFilePath = $uploadDir . $newFileName;
        if (move_uploaded_file($fileTmpName, $targetFilePath)) {
            // store relative path for DB (keep consistent with your app)
            $relativePath = 'uploads/documents/' . $newFileName;
            try {
                $stmt = $pdo->prepare("INSERT INTO documents (student_id, document_path, upload_date, file_size, file_type, status) VALUES (?, ?, NOW(), ?, ?, 'pending')");
                $stmt->execute([$studentId, $relativePath, $fileSize, $fileType]);
                $documentId = $pdo->lastInsertId();

                $logger->info('Document uploaded', ['document_id' => $documentId, 'student_id' => $studentId, 'actor' => $user->id ?? null, 'path' => $relativePath, 'size' => $fileSize]);

                jsonResponse("success", "Document uploaded successfully.", [
                    "document_id" => $documentId,
                    "file_path" => $relativePath
                ], 201);
            } catch (PDOException $e) {
                // cleanup file on DB failure
                if (file_exists($targetFilePath)) {
                    @unlink($targetFilePath);
                }
                $logger->error('Document upload DB insert failed', ['error' => $e->getMessage(), 'actor' => $user->id ?? null]);
                jsonResponse("error", "Document upload failed.", [], 500);
            }
        } else {
            $logger->error('Failed to move uploaded file', ['tmp' => $fileTmpName, 'target' => $targetFilePath, 'actor' => $user->id ?? null]);
            jsonResponse("error", "Failed to move uploaded file.", [], 500);
        }
    }

    elseif ($method === 'PUT') {
        // Update document status (admin)
        $user = authenticate('admin');

        $data = json_decode(file_get_contents("php://input"), true);
        $documentId = isset($data['id']) ? filter_var($data['id'], FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]) : null;
        $status = isset($data['status']) ? strtolower(trim((string)$data['status'])) : null;

        if (!$documentId || !in_array($status, ['pending', 'verified'], true)) {
            $logger->warning('Invalid document status update request', ['payload' => $data, 'actor' => $user->id ?? null]);
            jsonResponse("error", "Invalid document ID or status.", [], 400);
        }

        try {
            $stmt = $pdo->prepare("UPDATE documents SET status = ? WHERE id = ?");
            $stmt->execute([$status, $documentId]);

            if ($stmt->rowCount() === 0) {
                $logger->info('Document status update - not found or no change', ['document_id' => $documentId, 'status' => $status, 'actor' => $user->id ?? null]);
                jsonResponse("error", "Document not found or no changes made.", [], 404);
            }

            $logger->info('Document status updated', ['document_id' => $documentId, 'status' => $status, 'actor' => $user->id ?? null]);
            jsonResponse("success", "Document status updated successfully.", [], 200);
        } catch (PDOException $e) {
            $logger->error('Failed to update document status', ['error' => $e->getMessage(), 'document_id' => $documentId, 'actor' => $user->id ?? null]);
            jsonResponse("error", "Failed to update document status: " . $e->getMessage(), [], 500);
        }
    }

    else {
        $logger->warning('Documents endpoint - method not allowed', ['method' => $method]);
        jsonResponse("error", "Method not allowed.", [], 405);
    }
} catch (Exception $e) {
    $logger->critical('Unhandled exception in documents.php', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    jsonResponse("error", "Internal server error.", [], 500);
}
