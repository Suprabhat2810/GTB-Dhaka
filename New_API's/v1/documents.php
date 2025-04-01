<?php
require '../config.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
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
            $stmt = $pdo->prepare("INSERT INTO documents (student_id, document_path, upload_date, file_size, file_type) VALUES (?, ?, NOW(), ?, ?)");
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
} else {
    jsonResponse("error", "Method not allowed.", [], 405);
}