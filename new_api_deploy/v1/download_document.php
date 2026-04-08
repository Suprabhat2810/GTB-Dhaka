<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../services/StorageService.php';

use Services\StorageService;

$logger = getLogger('download_document');
$pdo = getPDO();

try {
    // Authenticate user (admin or student)
    $user = authenticate();
    
    // Get document path from query parameter
    $relativePath = $_GET['path'] ?? '';
    
    if (empty($relativePath)) {
        $logger->warning('Download attempt without path', ['actor' => $user->id ?? null]);
        jsonResponse("error", "Document path is required.", [], 400);
    }

    // Sanitize path to prevent directory traversal
    $relativePath = str_replace(['..', '\\'], ['', '/'], $relativePath);
    $relativePath = ltrim($relativePath, '/');
    
    // Additional security: verify path doesn't contain suspicious patterns
    if (preg_match('/\.\.|\\\\|[<>:"|?*]/', $relativePath)) {
        $logger->warning('Suspicious path pattern detected', [
            'path' => $relativePath,
            'actor' => $user->id ?? null
        ]);
        jsonResponse("error", "Invalid document path.", [], 400);
    }
    
    // Validate path length
    if (strlen($relativePath) > 500) {
        $logger->warning('Path too long', ['path_length' => strlen($relativePath), 'actor' => $user->id ?? null]);
        jsonResponse("error", "Invalid document path.", [], 400);
    }
    
    // Verify document exists in database and user has permission
    try {
        $stmt = $pdo->prepare("
            SELECT d.id, d.student_id, d.document_path, d.file_type, s.name as student_name
            FROM documents d
            JOIN students s ON d.student_id = s.id
            WHERE d.document_path = ?
            LIMIT 1
        ");
        $stmt->execute([$relativePath]);
        $document = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$document) {
            $logger->warning('Download attempt for non-existent document', [
                'path' => $relativePath,
                'actor' => $user->id ?? null
            ]);
            jsonResponse("error", "Document not found.", [], 404);
        }

        // Check permissions: admin can access all, student can only access their own
        $userRole = $user->role ?? null;
        $userId = $user->id ?? $user->sub ?? null;
        $studentId = $document['student_id'];

        if ($userRole !== 'admin' && $userId != $studentId) {
            $logger->warning('Unauthorized download attempt', [
                'path' => $relativePath,
                'actor' => $userId,
                'document_student_id' => $studentId
            ]);
            jsonResponse("error", "Unauthorized access to document.", [], 403);
        }

        // Get storage instance
        $storage = StorageService::getInstance();
        $driver = StorageService::getDriverName();

        if ($driver === 's3') {
            // For S3: Generate signed URL and redirect
            $signedUrl = $storage->getUrl($relativePath, 5); // 5 minutes expiration
            
            if (!$signedUrl) {
                $logger->error('Failed to generate S3 signed URL', [
                    'path' => $relativePath,
                    'actor' => $userId
                ]);
                jsonResponse("error", "Failed to generate download URL.", [], 500);
            }

            $logger->info('S3 signed URL generated', [
                'path' => $relativePath,
                'actor' => $userId,
                'student_id' => $studentId
            ]);

            // Redirect to signed URL
            header('Location: ' . $signedUrl);
            exit;

        } else {
            // For local storage: Stream file directly
            if (!method_exists($storage, 'getFilePath')) {
                $logger->error('LocalStorage missing getFilePath method');
                jsonResponse("error", "Storage configuration error.", [], 500);
            }

            $filePath = $storage->getFilePath($relativePath);
            
            if (!$filePath || !file_exists($filePath)) {
                $logger->error('Local file not found', [
                    'path' => $relativePath,
                    'full_path' => $filePath ?? 'null'
                ]);
                jsonResponse("error", "File not found on server.", [], 404);
            }

            // Stream file to browser
            $fileName = basename($filePath);
            $fileSize = filesize($filePath);
            $mimeType = $document['file_type'] ?? 'application/octet-stream';

            // Set headers for file download
            header('Content-Type: ' . $mimeType);
            header('Content-Disposition: inline; filename="' . $fileName . '"');
            header('Content-Length: ' . $fileSize);
            header('Cache-Control: private, max-age=0, must-revalidate');
            header('Pragma: public');

            // Clear output buffer
            if (ob_get_level()) {
                ob_end_clean();
            }

            // Stream file
            $handle = fopen($filePath, 'rb');
            if ($handle === false) {
                $logger->error('Failed to open file for streaming', ['path' => $filePath]);
                jsonResponse("error", "Failed to read file.", [], 500);
            }

            while (!feof($handle)) {
                echo fread($handle, 8192);
                flush();
            }
            fclose($handle);

            $logger->info('File streamed successfully', [
                'path' => $relativePath,
                'actor' => $userId,
                'student_id' => $studentId,
                'size' => $fileSize
            ]);

            exit;
        }

    } catch (PDOException $e) {
        $logger->error('Database error during download', [
            'error' => $e->getMessage(),
            'path' => $relativePath
        ]);
        jsonResponse("error", "Database error.", [], 500);
    }

} catch (Exception $e) {
    $logger->critical('Unexpected error in download_document.php', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    jsonResponse("error", "Internal server error.", [], 500);
}
