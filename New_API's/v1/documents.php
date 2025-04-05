<?php
require '../config.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $user = authenticate();
    if ($user->role !== 'admin') {
        jsonResponse("error", "Only admins can access this endpoint.", [], 403);
    }

    try {
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
        $verified = count(array_filter($documents, fn($d) => $d['status'] === 'verified'));
        $pending = $total - $verified;

        jsonResponse("success", "Documents retrieved successfully.", [
            'documents' => $formattedDocuments,
            'total' => $total,
            'verified' => $verified,
            'pending' => $pending,
        ], 200);
    } catch (PDOException $e) {
        $log->error("Failed to fetch documents: " . $e->getMessage());
        jsonResponse("error", "Failed to fetch documents: " . $e->getMessage(), [], 500);
    }
} elseif ($method === 'POST') {
    $user = authenticate();

    if (!isset($_FILES['document']) || !isset($_POST['student_id'])) {
        jsonResponse("error", "Missing document or student ID.", [], 400);
    }

    $studentId = filter_var($_POST['student_id'], FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
    if (!$studentId) {
        jsonResponse("error", "Invalid student ID.", [], 400);
    }

    if ($user->role !== 'admin' && $user->student_id != $studentId) {
        jsonResponse("error", "Unauthorized access.", [], 403);
    }

    try {
        $checkStudent = $pdo->prepare("SELECT 1 FROM students WHERE id = ?");
        $checkStudent->execute([$studentId]);
        if (!$checkStudent->fetch()) {
            jsonResponse("error", "Student not found.", [], 404);
        }

        $checkApproval = $pdo->prepare("SELECT approved FROM approvals WHERE student_id = ?");
        $checkApproval->execute([$studentId]);
        $approval = $checkApproval->fetch();
        if (!$approval || $approval['approved'] != 1) {
            jsonResponse("error", "Student not approved yet.", [], 403);
        }
    } catch (PDOException $e) {
        $log->error("Student validation failed: " . $e->getMessage());
        jsonResponse("error", "Failed to validate student.", [], 500);
    }

    $allowedFileTypes = ['application/pdf', 'image/jpeg', 'image/png'];
    $allowedExtensions = ['pdf', 'jpeg', 'jpg', 'png'];
    $maxFileSize = 5 * 1024 * 1024;
    $uploadDir = 'uploads/documents/';

    $file = $_FILES['document'];
    $fileName = basename($file['name']);
    $fileTmpName = $file['tmp_name'];
    $fileSize = $file['size'];
    $fileType = mime_content_type($fileTmpName);
    $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    if ($fileSize > $maxFileSize) {
        jsonResponse("error", "File is too large. Maximum allowed size is 5 MB.", [], 400);
    }

    if (!in_array($fileType, $allowedFileTypes) || !in_array($fileExt, $allowedExtensions)) {
        jsonResponse("error", "Invalid file type. Allowed types are PDF, JPEG, PNG.", [], 400);
    }

    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            jsonResponse("error", "Failed to create upload directory.", [], 500);
        }
    }

    if (!is_writable($uploadDir)) {
        jsonResponse("error", "Upload directory is not writable.", [], 500);
    }

    $newFileName = uniqid('doc_', true) . '.' . $fileExt;
    $targetFilePath = $uploadDir . $newFileName;

    if (move_uploaded_file($fileTmpName, $targetFilePath)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO documents (student_id, document_path, upload_date, file_size, file_type, status) VALUES (?, ?, NOW(), ?, ?, 'pending')");
            $stmt->execute([$studentId, $targetFilePath, $fileSize, $fileType]);
            $documentId = $pdo->lastInsertId();

            jsonResponse("success", "Document uploaded successfully.", [
                "document_id" => $documentId,
                "file_path" => $targetFilePath
            ], 201);
        } catch (PDOException $e) {
            unlink($targetFilePath);
            $log->error("Document upload failed: " . $e->getMessage());
            jsonResponse("error", "Document upload failed.", [], 500);
        }
    } else {
        jsonResponse("error", "Failed to move uploaded file.", [], 500);
    }
} elseif ($method === 'PUT') {
    $user = authenticate();
    if ($user->role !== 'admin') {
        jsonResponse("error", "Only admins can update document status.", [], 403);
    }

    $data = json_decode(file_get_contents("php://input"), true);
    $documentId = $data['id'] ?? null;
    $status = $data['status'] ?? null;

    if (!$documentId || !in_array($status, ['pending', 'verified'])) {
        jsonResponse("error", "Invalid document ID or status.", [], 400);
    }

    try {
        $stmt = $pdo->prepare("UPDATE documents SET status = ? WHERE id = ?");
        $stmt->execute([$status, $documentId]);

        if ($stmt->rowCount() === 0) {
            jsonResponse("error", "Document not found or no changes made.", [], 404);
        }

        jsonResponse("success", "Document status updated successfully.", [], 200);
    } catch (PDOException $e) {
        $log->error("Failed to update document status: " . $e->getMessage());
        jsonResponse("error", "Failed to update document status: " . $e->getMessage(), [], 500);
    }
} else {
    jsonResponse("error", "Method not allowed.", [], 405);
}