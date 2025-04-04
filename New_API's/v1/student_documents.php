<?php
require_once '../config.php';

$method = $_SERVER['REQUEST_METHOD'];

// Handle CORS preflight request
if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($method === 'GET') {
    $user = authenticate();
    $role = $user->role;

    if ($role !== 'student') {
        jsonResponse("error", "Only students can access documents.", [], 403);
    }

    try {
        $student_id = $user->id;
        $stmt = $pdo->prepare("
            SELECT id, document_path, upload_date, file_size, file_type, document_type
            FROM documents
            WHERE student_id = ?
        ");
        $stmt->execute([$student_id]);
        $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $formattedDocuments = array_map(function ($doc) {
            return [
                'id' => $doc['id'],
                'document_name' => basename($doc['document_path']),
                'document_path' => $doc['document_path'],
                'upload_date' => $doc['upload_date'],
                'file_size' => $doc['file_size'],
                'file_type' => $doc['file_type'],
                'document_type' => $doc['document_type'],
            ];
        }, $documents);

        jsonResponse("success", "Documents retrieved successfully.", $formattedDocuments, 200);
    } catch (PDOException $e) {
        $log->error("Failed to fetch documents: " . $e->getMessage());
        jsonResponse("error", "Failed to fetch documents: " . $e->getMessage(), [], 500);
    }
} elseif ($method === 'POST') {
    $user = authenticate();
    $role = $user->role;

    if ($role !== 'student') {
        jsonResponse("error", "Only students can upload documents.", [], 403);
    }

    if (!isset($_FILES['document'])) {
        jsonResponse("error", "No file uploaded.", [], 400);
    }

    $file = $_FILES['document'];
    $student_id = $user->id;

    // Validate file
    $allowedTypes = ['application/pdf', 'image/jpeg', 'image/png'];
    $maxSize = 5 * 1024 * 1024; // 5MB

    if (!in_array($file['type'], $allowedTypes)) {
        jsonResponse("error", "Invalid file type. Only PDF, JPG, and PNG are allowed.", [], 400);
    }

    if ($file['size'] > $maxSize) {
        jsonResponse("error", "File size exceeds 5MB limit.", [], 400);
    }

    // Create upload directory if it doesn't exist
    $uploadDir = '../uploads/documents/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    // Generate unique file name
    $fileName = uniqid() . '_' . basename($file['name']);
    $filePath = $uploadDir . $fileName;

    // Move the uploaded file
    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        jsonResponse("error", "Failed to upload file.", [], 500);
    }

    // Get document type from the request (if provided)
    $document_type = $_POST['document_type'] ?? null;

    try {
        // Insert into database
        $stmt = $pdo->prepare("
            INSERT INTO documents (student_id, document_path, upload_date, file_size, file_type, document_type)
            VALUES (?, ?, NOW(), ?, ?, ?)
        ");
        $stmt->execute([
            $student_id,
            $filePath,
            $file['size'],
            $file['type'],
            $document_type
        ]);

        jsonResponse("success", "Document uploaded successfully.", [], 200);
    } catch (PDOException $e) {
        $log->error("Failed to save document: " . $e->getMessage());
        // Remove the uploaded file if database insertion fails
        unlink($filePath);
        jsonResponse("error", "Failed to save document: " . $e->getMessage(), [], 500);
    }
} elseif ($method === 'DELETE') {
    $user = authenticate();
    $role = $user->role;

    if ($role !== 'student') {
        jsonResponse("error", "Only students can delete documents.", [], 403);
    }

    // Get the document ID from the query parameter
    $document_id = $_GET['id'] ?? null;
    if (!$document_id) {
        jsonResponse("error", "Document ID is required.", [], 400);
    }

    try {
        $student_id = $user->id;

        // Fetch the document to ensure it belongs to the student
        $stmt = $pdo->prepare("
            SELECT document_path
            FROM documents
            WHERE id = ? AND student_id = ?
        ");
        $stmt->execute([$document_id, $student_id]);
        $document = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$document) {
            jsonResponse("error", "Document not found or you do not have permission to delete it.", [], 404);
        }

        // Delete the file from the file system
        $filePath = $document['document_path'];
        if (file_exists($filePath)) {
            unlink($filePath);
        }

        // Delete the document from the database
        $stmt = $pdo->prepare("
            DELETE FROM documents
            WHERE id = ? AND student_id = ?
        ");
        $stmt->execute([$document_id, $student_id]);

        jsonResponse("success", "Document deleted successfully.", [], 200);
    } catch (PDOException $e) {
        $log->error("Failed to delete document: " . $e->getMessage());
        jsonResponse("error", "Failed to delete document: " . $e->getMessage(), [], 500);
    }
} else {
    jsonResponse("error", "Method not allowed.", [], 405);
}