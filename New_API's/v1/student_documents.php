<?php
require_once '../config.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $user = authenticate('student');

    try {
        $stmt = $pdo->prepare("SELECT id, document_path, upload_date, file_size, file_type FROM documents WHERE student_id = ?");
        $stmt->execute([$user->id]);
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Map the response to include document_name (derived from document_path)
        $documents = array_map(function ($doc) {
            $documentName = basename($doc['document_path']);
            return [
                'id' => $doc['id'],
                'document_name' => $documentName, // Derive document_name from the file path
                'document_path' => $doc['document_path'],
                'upload_date' => $doc['upload_date'],
                'file_size' => $doc['file_size'],
                'file_type' => $doc['file_type']
            ];
        }, $documents);

        jsonResponse("success", "Documents retrieved successfully.", $documents, 200);
    } catch (PDOException $e) {
        $log->error("Failed to fetch documents: " . $e->getMessage());
        jsonResponse("error", "Failed to fetch documents.", [], 500);
    }
} elseif ($method === 'POST') {
    $user = authenticate('student');

    if (!isset($_FILES['document'])) {
        jsonResponse("error", "No document uploaded.", [], 400);
    }

    $file = $_FILES['document'];
    $allowedTypes = ['application/pdf', 'image/jpeg', 'image/png'];
    $fileType = mime_content_type($file['tmp_name']);
    if (!in_array($fileType, $allowedTypes)) {
        jsonResponse("error", "Invalid file type. Only PDF, JPEG, and PNG are allowed.", [], 400);
    }

    if ($file['size'] > 5 * 1024 * 1024) { // 5MB limit
        jsonResponse("error", "File size exceeds 5MB limit.", [], 400);
    }

    $uploadDir = 'uploads/documents/'; // Match the directory used in documents.php
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    if (!is_writable($uploadDir)) {
        jsonResponse("error", "Upload directory is not writable.", [], 500);
    }

    $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $newFileName = uniqid('doc_', true) . '.' . $fileExt;
    $filePath = $uploadDir . $newFileName;

    if (move_uploaded_file($file['tmp_name'], $filePath)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO documents (student_id, document_path, upload_date, file_size, file_type) VALUES (?, ?, NOW(), ?, ?)");
            $stmt->execute([$user->id, $filePath, $file['size'], $fileType]);
            jsonResponse("success", "Document uploaded successfully.", [], 200);
        } catch (PDOException $e) {
            $log->error("Failed to save document: " . $e->getMessage());
            unlink($filePath); // Remove the file if DB insert fails
            jsonResponse("error", "Failed to save document.", [], 500);
        }
    } else {
        jsonResponse("error", "Failed to upload document.", [], 500);
    }
} else {
    jsonResponse("error", "Method not allowed.", [], 405);
}