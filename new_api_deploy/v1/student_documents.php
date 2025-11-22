<?php
// students_document.php — hardened, logged, non-breaking replacement
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';

$logger = getLogger('student_documents');
$pdo = getPDO();
$method = $_SERVER['REQUEST_METHOD'];

// Handle CORS preflight request
if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    if ($method === 'GET') {
        $user = authenticate();
        $role = $user->role ?? null;

        if ($role !== 'student') {
            $logger->warning('documents GET - forbidden role', ['actor' => $user->id ?? null, 'role' => $role]);
            jsonResponse("error", "Only students can access documents.", [], 403);
        }

        $studentId = (int)($user->sub ?? $user->id ?? 0);
        $stmt = $pdo->prepare("
            SELECT id, document_path, upload_date, file_size, file_type, document_type
            FROM documents
            WHERE student_id = ?
        ");
        $stmt->execute([$studentId]);
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $formattedDocuments = array_map(function ($doc) {
            return [
                'id' => (int)$doc['id'],
                'document_name' => basename($doc['document_path']),
                'document_path' => $doc['document_path'],
                'upload_date' => $doc['upload_date'],
                'file_size' => (int)$doc['file_size'],
                'file_type' => $doc['file_type'],
                'document_type' => $doc['document_type'],
            ];
        }, $documents);

        $logger->info('documents GET - retrieved student documents', ['student_id' => $studentId, 'count' => count($formattedDocuments)]);
        jsonResponse("success", "Documents retrieved successfully.", $formattedDocuments, 200);
    }

    elseif ($method === 'POST') {
        $user = authenticate();
        $role = $user->role ?? null;

        if ($role !== 'student') {
            $logger->warning('documents POST - forbidden role', ['actor' => $user->id ?? null, 'role' => $role]);
            jsonResponse("error", "Only students can upload documents.", [], 403);
        }

        if (!isset($_FILES['document'])) {
            $logger->warning('documents POST - no file uploaded', ['actor' => $user->id ?? null]);
            jsonResponse("error", "No file uploaded.", [], 400);
        }

        $file = $_FILES['document'];
        $student_id = (int)($user->sub ?? $user->id ?? 0);

        // Validate file
        $allowedMIMEs = ['application/pdf', 'image/jpeg', 'image/png'];
        $allowedExtensions = ['pdf', 'jpeg', 'jpg', 'png'];
        $maxSize = 5 * 1024 * 1024; // 5MB

        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            $logger->warning('documents POST - invalid uploaded file', ['actor' => $user->id ?? null]);
            jsonResponse("error", "Invalid uploaded file.", [], 400);
        }

        $fileSize = (int)($file['size'] ?? 0);
        if ($fileSize > $maxSize) {
            $logger->info('documents POST - file too large', ['size' => $fileSize, 'max' => $maxSize, 'actor' => $user->id ?? null]);
            jsonResponse("error", "File size exceeds 5MB limit.", [], 400);
        }

        // Use finfo for reliable mime type detection
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']) ?: mime_content_type($file['tmp_name']);
        $originalName = basename($file['name'] ?? 'upload');
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (!in_array($mimeType, $allowedMIMEs, true) || !in_array($ext, $allowedExtensions, true)) {
            $logger->warning('documents POST - invalid file type', ['mime' => $mimeType, 'ext' => $ext, 'actor' => $user->id ?? null]);
            jsonResponse("error", "Invalid file type. Only PDF, JPG, and PNG are allowed.", [], 400);
        }

        // Create upload directory if it doesn't exist
        $uploadDir = realpath(__DIR__ . '/../uploads/documents');
        if ($uploadDir === false) {
            $uploadDir = __DIR__ . '/../uploads/documents/';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
                $logger->error('documents POST - failed to create upload directory', ['dir' => $uploadDir]);
                jsonResponse("error", "Failed to create upload directory.", [], 500);
            }
        }
        if (!is_writable($uploadDir)) {
            $logger->error('documents POST - upload directory not writable', ['dir' => $uploadDir]);
            jsonResponse("error", "Upload directory is not writable.", [], 500);
        }

        // Generate secure unique file name
        $safeBase = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
        $newFileName = uniqid($student_id . '_') . '_' . $safeBase . '.' . $ext;
        $targetFilePath = rtrim($uploadDir, '/\\') . DIRECTORY_SEPARATOR . $newFileName;

        if (!move_uploaded_file($file['tmp_name'], $targetFilePath)) {
            $logger->error('documents POST - move_uploaded_file failed', ['tmp' => $file['tmp_name'], 'target' => $targetFilePath, 'actor' => $user->id ?? null]);
            jsonResponse("error", "Failed to upload file.", [], 500);
        }

        // Store relative path in DB for portability
        $relativePath = 'uploads/documents/' . $newFileName;

        $document_type = isset($_POST['document_type']) ? trim((string)$_POST['document_type']) : null;

        try {
            $stmt = $pdo->prepare("
                INSERT INTO documents (student_id, document_path, upload_date, file_size, file_type, document_type)
                VALUES (?, ?, NOW(), ?, ?, ?)
            ");
            $stmt->execute([
                $student_id,
                $relativePath,
                $fileSize,
                $mimeType,
                $document_type
            ]);

            $logger->info('documents POST - uploaded and saved', [
                'document_id' => $pdo->lastInsertId(),
                'student_id' => $student_id,
                'path' => $relativePath,
                'size' => $fileSize
            ]);

            jsonResponse("success", "Document uploaded successfully.", [], 200);
        } catch (PDOException $e) {
            // cleanup file on DB failure
            if (file_exists($targetFilePath)) {
                @unlink($targetFilePath);
            }
            $logger->error('documents POST - DB insert failed', ['error' => $e->getMessage(), 'actor' => $user->id ?? null]);
            jsonResponse("error", "Failed to save document: " . $e->getMessage(), [], 500);
        }
    }

    elseif ($method === 'DELETE') {
        $user = authenticate();
        $role = $user->role ?? null;

        if ($role !== 'student') {
            $logger->warning('documents DELETE - forbidden role', ['actor' => $user->id ?? null, 'role' => $role]);
            jsonResponse("error", "Only students can delete documents.", [], 403);
        }

        $document_id = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]) : null;
        if (!$document_id) {
            $logger->warning('documents DELETE - missing id', ['actor' => $user->id ?? null]);
            jsonResponse("error", "Document ID is required.", [], 400);
        }

        $student_id = (int)($user->sub ?? $user->id ?? 0);

        try {
            // Fetch the document to ensure it belongs to the student
            $stmt = $pdo->prepare("SELECT document_path FROM documents WHERE id = ? AND student_id = ?");
            $stmt->execute([$document_id, $student_id]);
            $document = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$document) {
                $logger->info('documents DELETE - not found or no permission', ['document_id' => $document_id, 'student_id' => $student_id]);
                jsonResponse("error", "Document not found or you do not have permission to delete it.", [], 404);
            }

            $filePath = $document['document_path'];
            // Support both stored relative and absolute paths
            $absolutePath = $filePath;
            if (!file_exists($absolutePath)) {
                $absolutePath = __DIR__ . '/../' . ltrim($filePath, '/\\');
            }

            if (file_exists($absolutePath) && is_writable($absolutePath)) {
                @unlink($absolutePath);
                $logger->info('documents DELETE - file removed', ['path' => $absolutePath, 'document_id' => $document_id]);
            } else {
                $logger->warning('documents DELETE - file missing or not writable', ['path' => $absolutePath, 'document_id' => $document_id]);
            }

            // Delete the document from the database
            $stmt = $pdo->prepare("DELETE FROM documents WHERE id = ? AND student_id = ?");
            $stmt->execute([$document_id, $student_id]);

            $logger->info('documents DELETE - DB record removed', ['document_id' => $document_id, 'student_id' => $student_id]);
            jsonResponse("success", "Document deleted successfully.", [], 200);
        } catch (PDOException $e) {
            $logger->error('documents DELETE - DB error', ['error' => $e->getMessage(), 'document_id' => $document_id, 'student_id' => $student_id]);
            jsonResponse("error", "Failed to delete document: " . $e->getMessage(), [], 500);
        }
    } else {
        $logger->warning('documents - method not allowed', ['method' => $method]);
        jsonResponse("error", "Method not allowed.", [], 405);
    }
} catch (Exception $e) {
    $logger->critical('documents - unexpected error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    jsonResponse("error", "Internal server error.", [], 500);
}
